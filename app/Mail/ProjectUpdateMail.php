<?php

namespace App\Mail;

use App\Models\Project;
use App\Services\TargetDateChange;
use App\Support\BusinessTime;

/**
 * A change worth telling a client about, on a project they own.
 *
 * One mailable for every project event rather than a class each: they differ
 * only in a heading and a sentence, and keeping them together is what stops
 * two of them describing the same system in two different voices.
 */
class ProjectUpdateMail extends SystemMail
{
    public const COMPLETED = 'completed';

    /**
     * The confirmation workflow.
     *
     * AWAITING_CONFIRMATION and CONFIRMATION_REMINDER are the only project
     * emails that ask the reader to do something, so both carry a Confirm
     * Completion button rather than the usual "view my project" - see
     * actionLabel().
     */
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const CONFIRMATION_REMINDER = 'confirmation_reminder';

    public const CONFIRMED = 'confirmed';

    public const AUTO_COMPLETED = 'auto_completed';

    public const REOPENED = 'reopened';

    public const CANCELLED = 'cancelled';

    public const ON_HOLD = 'on_hold';

    public const RESUMED = 'resumed';

    public const ASSESSMENT_UPLOADED = 'assessment_uploaded';

    public const QUOTATION_UPLOADED = 'quotation_uploaded';

    public const CONTRACT_UPLOADED = 'contract_uploaded';

    public const TARGET_DATE_CHANGED = 'target_date_changed';

    /**
     * @param  string  $event  One of the constants above.
     * @param  string|null  $detail  The reason, remark or summary the event
     *                               carries, when it has one.
     */
    public function __construct(
        public readonly Project $project,
        public readonly string $event,
        public readonly ?string $recipientName = null,
        public readonly ?string $detail = null,
    ) {}

    protected function subjectLine(): string
    {
        $reference = $this->project->reference_no;

        return match ($this->event) {
            self::COMPLETED => sprintf('Your project %s is complete', $reference),
            self::AWAITING_CONFIRMATION => sprintf('Please confirm your completed project %s', $reference),
            self::CONFIRMATION_REMINDER => sprintf('Reminder: %s is waiting for your confirmation', $reference),
            self::CONFIRMED => sprintf('Thank you for confirming project %s', $reference),
            self::AUTO_COMPLETED => sprintf('Your project %s has been completed', $reference),
            self::REOPENED => sprintf('Your project %s has been reopened', $reference),
            self::CANCELLED => sprintf('Your project %s has been cancelled', $reference),
            self::ON_HOLD => sprintf('Your project %s has been put on hold', $reference),
            self::RESUMED => sprintf('Work has resumed on your project %s', $reference),
            self::ASSESSMENT_UPLOADED => sprintf('An assessment report is available for %s', $reference),
            self::QUOTATION_UPLOADED => sprintf('A quotation is available for %s', $reference),
            self::CONTRACT_UPLOADED => sprintf('A contract is available for %s', $reference),
            self::TARGET_DATE_CHANGED => sprintf('Target date changed for %s', $reference),
            default => sprintf('An update on your project %s', $reference),
        };
    }

    protected function template(): string
    {
        return 'emails.project-update';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'project' => $this->project,
            'event' => $this->event,
            'detail' => $this->detail,
            'recipientName' => $this->recipientName,
            'heading' => $this->heading(),
            'body' => $this->body(),
            'detailLabel' => $this->detailLabel(),
            'projectUrl' => route('public.projects.show', $this->project->project_id),
            'actionLabel' => $this->actionLabel(),
            'extraRows' => $this->extraRows(),
        ];
    }

    private function heading(): string
    {
        return match ($this->event) {
            self::COMPLETED => 'Your project is complete',
            self::AWAITING_CONFIRMATION => 'Please confirm your completed project',
            self::CONFIRMATION_REMINDER => 'Please confirm your project',
            self::CONFIRMED => 'Thank you for confirming',
            self::AUTO_COMPLETED => 'Your project has been completed',
            self::REOPENED => 'Your project has been reopened',
            self::CANCELLED => 'Your project has been cancelled',
            self::ON_HOLD => 'Your project has been put on hold',
            self::RESUMED => 'Work on your project has resumed',
            self::ASSESSMENT_UPLOADED => 'Your assessment report is ready',
            self::QUOTATION_UPLOADED => 'Your quotation is ready',
            self::CONTRACT_UPLOADED => 'Your contract is ready',
            self::TARGET_DATE_CHANGED => 'Target date changed',
            default => 'An update on your project',
        };
    }

    private function body(): string
    {
        $company = config('company.name');
        $window = Project::completionConfirmationDays();

        return match ($this->event) {
            self::COMPLETED => 'Your project is complete. Thank you for choosing '.$company.'.',

            self::AWAITING_CONFIRMATION => 'Work is done. Please review and confirm within '.$window.' days.',

            self::CONFIRMATION_REMINDER => 'Please confirm before the '.$window.'-day window ends.',

            self::CONFIRMED => 'Thanks for confirming. Your project is now closed.',

            self::AUTO_COMPLETED => 'Auto-completed after '.$window.' days. Contact us if needed.',

            self::REOPENED => 'More work is scheduled. New dates are below.',

            self::CANCELLED => 'This project has been cancelled.',
            self::ON_HOLD => 'Work is paused. We will tell you when it resumes.',
            self::RESUMED => 'Work has resumed. New dates coming soon.',
            self::ASSESSMENT_UPLOADED => 'Your assessment report is ready to view.',
            self::QUOTATION_UPLOADED => 'Your quotation is ready to view.',
            self::CONTRACT_UPLOADED => 'Your contract is ready to view.',
            self::TARGET_DATE_CHANGED => 'Your target date has changed.',
            default => 'Your project has an update.',
        };
    }

    private function detailLabel(): string
    {
        return match ($this->event) {
            self::COMPLETED, self::AWAITING_CONFIRMATION,
            self::CONFIRMATION_REMINDER, self::CONFIRMED, self::AUTO_COMPLETED => 'Summary',
            self::REOPENED => 'Reason for reopening',
            self::CANCELLED => 'Reason',
            self::TARGET_DATE_CHANGED => 'Reason for change',
            default => 'Details',
        };
    }

    /**
     * What the button at the foot of the email says.
     *
     * The two emails that ask for a decision name it, because "view my
     * project" beside a request to confirm reads as though the confirming
     * happens somewhere else.
     */
    private function actionLabel(): string
    {
        return match ($this->event) {
            self::AWAITING_CONFIRMATION, self::CONFIRMATION_REMINDER => 'Review and confirm',
            default => 'View my project',
        };
    }

    /**
     * Facts worth putting in the details table for this event and no other.
     *
     * The confirmation emails carry the deadline, because a client who reads
     * one a few days late needs the date rather than "within seven days". The
     * reopen email carries the new dates.
     *
     * @return array<string, string|null>
     */
    private function extraRows(): array
    {
        return match ($this->event) {
            self::AWAITING_CONFIRMATION, self::CONFIRMATION_REMINDER => [
                'Completion date' => $this->project->completed_at?->format(BusinessTime::DATE),
                'Confirm by' => $this->project->confirmationDeadline()?->format(BusinessTime::DATE),
            ],
            self::CONFIRMED, self::AUTO_COMPLETED => [
                'Completion date' => $this->project->completed_at?->format(BusinessTime::DATE),
            ],
            // The new dates are the actionable half of a reopening, so they
            // travel in this email rather than in a second one sent a moment
            // later. Read from the project, which by now holds the schedule
            // the reopen just created.
            self::REOPENED => [
                'New schedule' => $this->scheduleSummary(),
            ],
            // Read from the history row the change just wrote, so the email
            // states the same before and after the project page does.
            self::TARGET_DATE_CHANGED => $this->targetDateRows(),
            default => [],
        };
    }

    /**
     * @return array<string, string|null>
     */
    private function targetDateRows(): array
    {
        $latest = $this->project->targetDateHistory()->first();

        return [
            'Previous target date' => $latest?->previous_date
                ? TargetDateChange::format($latest->previous_date)
                : null,
            'New target date' => TargetDateChange::format($this->project->target_end_date),
        ];
    }

    /**
     * Every date range the project currently holds, in the same wording every
     * other screen describes a schedule with.
     */
    private function scheduleSummary(): ?string
    {
        $this->project->loadMissing('schedules');

        if ($this->project->schedules->isEmpty()) {
            return null;
        }

        return $this->project->schedules
            ->sortBy('start_datetime')
            ->map(fn ($schedule): string => $schedule->describe())
            ->implode('; ');
    }
}
