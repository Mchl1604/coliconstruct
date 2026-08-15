<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The confirmation clock.
 *
 * A project handed to its client for confirmation cannot sit there forever, so
 * two things happen on a timer: a reminder on day five, and completion on day
 * seven whether anybody replied or not.
 *
 * Two passes over the same set, in the order a person would do them, modelled
 * on SendTaskReminders. Scheduled daily and safe to run again by hand: the
 * reminder stamps completion_reminder_sent_at so it cannot go out twice, and
 * the completion pass only ever sees projects still in the awaiting status.
 *
 * This must not depend on anybody opening a page. A client who never signs in
 * again is exactly the case the seven days exist for, so the work happens here
 * rather than in a controller - see routes/console.php, and note that it needs
 * `php artisan schedule:work` or a cron entry calling schedule:run.
 */
class ProcessCompletionConfirmations extends Command
{
    protected $signature = 'projects:process-completion-confirmations';

    protected $description = 'Remind clients to confirm completed projects, and complete the ones nobody answered for.';

    public function handle(
        ProjectCompletion $completion,
        NotificationService $notifications,
        ProjectEmails $emails,
        ActivityLogger $activityLogger
    ): int {
        $now = CarbonImmutable::now();

        $reminded = 0;
        $completed = 0;

        // Day five first. A project that is somehow past both marks gets its
        // reminder and its completion in the same run, which reads oddly - so
        // the reminder pass deliberately excludes anything already due to be
        // completed, and those clients hear once, about the thing that
        // actually happened.
        foreach ($this->awaitingSince($now->subDays(Project::COMPLETION_REMINDER_DAYS))
            ->whereNull('completion_reminder_sent_at')
            ->where('completion_requested_at', '>', $now->subDays(Project::COMPLETION_CONFIRMATION_DAYS))
            ->get() as $project) {
            $reminded += $this->remind($project, $notifications, $emails) ? 1 : 0;
        }

        foreach ($this->awaitingSince($now->subDays(Project::COMPLETION_CONFIRMATION_DAYS))->get() as $project) {
            $completed += $this->autoComplete($project, $completion, $notifications, $emails, $activityLogger) ? 1 : 0;
        }

        $this->info(sprintf(
            'Confirmation sweep: %d reminder(s) sent, %d project(s) completed automatically.',
            $reminded,
            $completed
        ));

        return self::SUCCESS;
    }

    /**
     * Projects that have been waiting on their client since at least the given
     * moment.
     *
     * completion_requested_at is null on work completed before this workflow
     * existed, and `<=` never matches null - so those rows are excluded
     * without a clause of their own, which is the right answer: they were
     * closed under the old rules and there is nobody to remind.
     *
     * @return Builder<Project>
     */
    private function awaitingSince(CarbonImmutable $moment)
    {
        return Project::query()
            ->with(['clients', 'projectTechnicians.technician.account'])
            ->where('status', Project::STATUS_AWAITING_CLIENT_CONFIRMATION)
            ->where('is_archived', false)
            ->whereNotNull('completion_requested_at')
            ->where('completion_requested_at', '<=', $moment)
            ->orderBy('completion_requested_at');
    }

    /**
     * Day five: a nudge, and nothing else.
     *
     * The clock is deliberately not touched. The reminder tells the client the
     * deadline is coming; it does not move it, and a client who reads it on
     * day six still has until day seven.
     */
    private function remind(Project $project, NotificationService $notifications, ProjectEmails $emails): bool
    {
        try {
            // Stamped first. If the mail server then fails, the client has
            // lost a reminder; if it were stamped last, a failure would send
            // the same reminder again every day until the deadline.
            $project->forceFill(['completion_reminder_sent_at' => CarbonImmutable::now()])->save();

            $notifications->completionConfirmationReminder($project);
            $emails->completionReminder($project);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $this->warn(sprintf('Could not remind the client about %s.', $project->reference_no ?? $project->project_id));

            return false;
        }
    }

    /**
     * Day seven: the project completes itself.
     *
     * One project failing must not stop the rest of the sweep, so each is its
     * own transaction and its own try.
     */
    private function autoComplete(
        Project $project,
        ProjectCompletion $completion,
        NotificationService $notifications,
        ProjectEmails $emails,
        ActivityLogger $activityLogger
    ): bool {
        try {
            DB::transaction(function () use ($project, $completion): void {
                $completion->confirm($project, null, Project::METHOD_AUTO_COMPLETED);
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->warn(sprintf('Could not complete %s.', $project->reference_no ?? $project->project_id));

            return false;
        }

        // Nobody is signed in here, so the logger records the actor as
        // "System" - which is exactly what happened.
        $activityLogger->record(
            ActivityLog::PROJECT_AUTO_COMPLETED,
            null,
            sprintf(
                "Completed project '%s' automatically after %d days without client confirmation "
                    .'(Awaiting Client Confirmation -> Completed).',
                $project->reference_no ?? $project->name,
                Project::COMPLETION_CONFIRMATION_DAYS
            ),
            $project
        );

        $notifications->projectAutoCompleted($project);
        $emails->projectAutoCompleted($project->refresh());

        return true;
    }
}
