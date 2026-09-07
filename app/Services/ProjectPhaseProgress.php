<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * How far through its phases a project is, and what closing one takes.
 *
 * Every figure the monitoring interface prints comes from here - "2/4 Phases",
 * the bar, each phase's status and task count, and whether its Complete Phase
 * button is drawn. Stating them once means the bar and the cards under it can
 * never disagree, which is the reliability the whole feature exists for.
 *
 * Nothing here is stored. A phase's status is worked out from `completed_at`
 * on it and on the phases before it, and its task figures are counted from the
 * tasks pointing at it. A stored status column would be a second copy of both
 * and the first thing to drift.
 */
class ProjectPhaseProgress
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * Everything the monitoring panel needs for one project, in one place.
     *
     * @return array{
     *     total: int,
     *     completed: int,
     *     label: string,
     *     percent: int,
     *     currentPhaseId: int|null,
     *     phases: Collection<int, array{
     *         phase: ProjectPhase,
     *         status: string,
     *         label: string,
     *         badge: string,
     *         isCurrent: bool,
     *         totalTasks: int,
     *         completedTasks: int,
     *         openTasks: int,
     *         taskPercent: int,
     *         canBeCompleted: bool
     *     }>
     * }
     */
    public function summary(Project $project): array
    {
        $phases = $this->phasesWithCounts($project);

        // A project nobody is working on any more has no phase in progress,
        // whatever its phases say on their own. Completed work has none left
        // open at all - ProjectCompletion closes them - so this is really
        // about cancelled and archived projects, where the stages that were
        // never reached must not read as a queue somebody is working down.
        $projectIsClosed = $project->isReadOnly() || $project->isArchived();
        $current = $projectIsClosed ? null : $this->currentPhaseFrom($phases);

        $rows = $phases->map(function (ProjectPhase $phase) use ($current, $projectIsClosed): array {
            $status = $this->statusFor($phase, $current, $projectIsClosed);

            $completedTasks = (int) $phase->completed_tasks_count;
            // Cancelled work is left out of the denominator rather than
            // counted as outstanding: a task nobody is going to do should not
            // hold "5/5 Tasks" at 4/5 forever.
            $totalTasks = (int) $phase->countable_tasks_count;
            $openTasks = (int) $phase->open_tasks_count;

            return [
                'phase' => $phase,
                'status' => $status,
                'label' => ProjectPhase::STATUSES[$status]['label'],
                'badge' => ProjectPhase::STATUSES[$status]['badge'],
                'isCurrent' => $status === ProjectPhase::STATUS_IN_PROGRESS,
                'totalTasks' => $totalTasks,
                'completedTasks' => $completedTasks,
                'openTasks' => $openTasks,
                'taskPercent' => $totalTasks > 0
                    ? (int) round($completedTasks / $totalTasks * 100)
                    : 0,
                // Whether the rules would let this phase close right now. Who
                // is allowed to press the button is a separate question - see
                // ProjectPhaseRules::canCompletePhase().
                'canBeCompleted' => $status === ProjectPhase::STATUS_IN_PROGRESS
                    && $openTasks === 0
                    && $totalTasks > 0,
            ];
        });

        $total = $phases->count();
        $completed = $phases->filter->isCompleted()->count();

        return [
            'total' => $total,
            'completed' => $completed,
            // "2/4 Phases" - the whole string, so no view has to assemble it
            // and get the wording different from the next one.
            'label' => sprintf('%d/%d %s', $completed, $total, $total === 1 ? 'Phase' : 'Phases'),
            'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'currentPhaseId' => $current?->phase_id,
            'phases' => $rows,
        ];
    }

    /**
     * The phase the project is on: the earliest one not yet closed.
     *
     * Null once every phase is finished, which is a project with nothing left
     * to work through rather than an error.
     */
    public function currentPhase(Project $project): ?ProjectPhase
    {
        return $project->currentPhase();
    }

    /**
     * Why this phase cannot be closed yet, as sentences.
     *
     * Written the way ProjectPolicy::blockersFor() is, and for the same
     * reason: a refusal that does not say what is in the way leaves the reader
     * to go and find it themselves.
     *
     * @return array<int, string>
     */
    public function completionBlockers(ProjectPhase $phase): array
    {
        if ($phase->isCompleted()) {
            return ['This phase has already been completed.'];
        }

        $current = $this->currentPhase($phase->project);

        // Phases are closed in order. Letting Phase 3 close while Phase 2 is
        // open would make "2/4 Phases" a count of finished phases rather than
        // a position in the job, and those are different things.
        if ($current !== null && $current->phase_id !== $phase->phase_id) {
            return [sprintf(
                'This is not the current phase. %s has to be completed first.',
                $current->label()
            )];
        }

        $blockers = [];

        $openTasks = $phase->tasks()->whereIn('status', Task::OPEN_STATUSES)->count();

        if ($openTasks > 0) {
            $blockers[] = sprintf(
                'This phase cannot be completed because %d %s still incomplete.',
                $openTasks,
                $openTasks === 1 ? 'task is' : 'tasks are'
            );
        }

        if ($phase->tasks()->count() === 0) {
            $blockers[] = 'This phase has no tasks yet, so there is nothing to complete.';
        }

        return $blockers;
    }

    /**
     * The tasks standing between this phase and being closed, so the refusal
     * can list them rather than only counting them.
     *
     * @return Collection<int, Task>
     */
    public function incompleteTasks(ProjectPhase $phase): Collection
    {
        return $phase->tasks()
            ->with('technician')
            ->whereIn('status', Task::OPEN_STATUSES)
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Close a phase out.
     *
     * `$override` is a Super Admin going ahead with tasks still open, and it
     * is the only thing that gets past completionBlockers(). Even then the
     * phase order is not negotiable - a Super Admin overriding their way past
     * an open task is a judgement about the work; overriding their way past
     * the sequence would just make the count wrong.
     *
     * The override is a decision rather than an explanation: the dialog
     * confirms it in one sentence and asks for nothing else, so what is
     * recorded is who did it and when. `$reason` stays on the signature for a
     * caller that has one to give - nothing in the interface does today.
     *
     * @throws RuntimeException when the phase cannot be closed and no override
     *                          was given.
     */
    public function complete(
        ProjectPhase $phase,
        User $user,
        bool $override = false,
        ?string $reason = null
    ): void {
        $blockers = $this->completionBlockers($phase);
        $overridable = $override
            && ! $phase->isCompleted()
            && $this->isCurrent($phase);

        if ($blockers !== [] && ! $overridable) {
            throw new RuntimeException($blockers[0]);
        }

        // Only recorded when the override actually did something. A phase that
        // was ready to close is closed normally however the button was
        // pressed, and filing that as an override would put phases into the
        // audit trail that nobody waived anything for.
        $waived = $blockers !== [] && $override;
        $outstanding = $waived ? $this->outstandingSummary($phase) : null;

        DB::transaction(function () use ($phase, $user, $waived, $reason): void {
            $phase->update([
                'completed_at' => now(),
                'completed_by' => $user->id,
                'completion_override_reason' => $waived ? $reason : null,
                'completion_overridden_by' => $waived ? $user->id : null,
            ]);
        });

        $phase->refresh();

        $this->activityLogger->record(
            $waived
                ? ActivityLog::PROJECT_PHASE_COMPLETION_OVERRIDDEN
                : ActivityLog::PROJECT_PHASE_COMPLETED,
            null,
            $waived
                ? sprintf(
                    'Completed %s on %s despite %s.',
                    $phase->label(),
                    $phase->project->reference_no,
                    $outstanding
                )
                : sprintf('Completed %s on %s.', $phase->label(), $phase->project->reference_no),
            $phase
        );
    }

    /**
     * What was still open when a phase was overridden, for the log line - the
     * fact that entry exists to record. Read before the write, because closing
     * the phase does not close the tasks on it but the sentence is about the
     * moment the decision was taken.
     */
    private function outstandingSummary(ProjectPhase $phase): string
    {
        $open = $phase->tasks()->whereIn('status', Task::OPEN_STATUSES)->count();

        return $open === 1 ? '1 incomplete task' : $open.' incomplete tasks';
    }

    /**
     * Whether this phase is the one the project is currently on.
     */
    public function isCurrent(ProjectPhase $phase): bool
    {
        return $this->currentPhase($phase->project)?->phase_id === $phase->phase_id;
    }

    /**
     * The phases a task may be filed under on this project.
     *
     * A completed phase is not one of them. Adding work to a stage somebody
     * has already signed off would either reopen a closed phase or leave it
     * reading "5/6 Tasks" while still marked Completed, and both make the
     * monitoring figures say something untrue about work that is finished.
     *
     * `$keepPhaseId` is the exception, and only for editing: a task already
     * sitting on a phase that has since been completed keeps that phase as an
     * option, so its title or its dates can be corrected without the form
     * silently moving the work somewhere else. It is the same allowance the
     * technician picker makes for a task's current owner - see
     * TaskAssignmentRules.
     *
     * @return Collection<int, ProjectPhase>
     */
    public function selectablePhases(Project $project, ?int $keepPhaseId = null): Collection
    {
        if ($project->needsPhaseSetup()) {
            return collect();
        }

        return $project->phases()
            ->inOrder()
            ->get()
            ->filter(fn (ProjectPhase $phase): bool => ! $phase->isCompleted()
                || $phase->phase_id === $keepPhaseId)
            ->values();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * This project's phases with their three task figures attached, in one
     * query rather than three per phase.
     *
     * @return Collection<int, ProjectPhase>
     */
    private function phasesWithCounts(Project $project): Collection
    {
        return $project->phases()
            ->withCount([
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
                'tasks as open_tasks_count' => fn ($query) => $query->whereIn('status', Task::OPEN_STATUSES),
                'tasks as countable_tasks_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
            ])
            ->inOrder()
            ->get();
    }

    /**
     * @param  Collection<int, ProjectPhase>  $phases
     */
    private function currentPhaseFrom(Collection $phases): ?ProjectPhase
    {
        return $phases->first(fn (ProjectPhase $phase): bool => ! $phase->isCompleted());
    }

    private function statusFor(ProjectPhase $phase, ?ProjectPhase $current, bool $projectIsClosed): string
    {
        if ($phase->isCompleted()) {
            return ProjectPhase::STATUS_COMPLETED;
        }

        // Nobody is working through this one. A cancelled project badged
        // "Phase 3 - In Progress · Current Phase" claims a crew is on site,
        // and the phase after it reading "Not Started" implies they will get
        // to it. Completed projects never reach here: ProjectCompletion closes
        // whatever is still open on the way out.
        if ($projectIsClosed) {
            return ProjectPhase::STATUS_NOT_COMPLETED;
        }

        return $current !== null && $current->phase_id === $phase->phase_id
            ? ProjectPhase::STATUS_IN_PROGRESS
            : ProjectPhase::STATUS_NOT_STARTED;
    }
}
