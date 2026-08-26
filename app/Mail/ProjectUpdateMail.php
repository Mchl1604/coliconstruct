<?php

namespace App\Mail;

use App\Models\Project;
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
            self::CONFIRMATION_REMINDER => 'Your project is still waiting for confirmation',
            self::CONFIRMED => 'Thank you for confirming',
            self::AUTO_COMPLETED => 'Your project has been completed',
            self::REOPENED => 'Your project has been reopened',
            self::CANCELLED => 'Your project has been cancelled',
            self::ON_HOLD => 'Your project has been put on hold',
            self::RESUMED => 'Work on your project has resumed',
            self::ASSESSMENT_UPLOADED => 'Your assessment report is ready',
            self::QUOTATION_UPLOADED => 'Your quotation is ready',
            self::CONTRACT_UPLOADED => 'Your contract is ready',
            default => 'An update on your project',
        };
    }

    private function body(): string
    {
        $company = config('company.name');
        $window = Project::completionConfirmationDays();

        return match ($this->event) {
            self::COMPLETED => 'The work on this project has been completed and signed off. Thank you for choosing '
                .$company.'. You can review the completion details, including any photographs, on the project page.',

            self::AWAITING_CONFIRMATION => 'Our team has finished the work on this project. Please open the project '
                .'page to review what was done, including the completion photographs, and confirm that you are happy '
                .'with it. If we do not hear from you within '.$window.' days the project will be marked complete '
                .'automatically. If anything needs attention, contact us and we will put it right.',

            self::CONFIRMATION_REMINDER => 'This project is still waiting for your confirmation. Please review the '
                .'completion details on the project page and confirm when you are ready. It will be marked complete '
                .'automatically once the '.$window.' day confirmation period ends. If something is not right, '
                .'contact us rather than confirming.',

            self::CONFIRMED => 'Thank you for confirming that the work is complete. This project is now closed, and '
                .'its full record - the schedule, the reports and the completion photographs - stays available to '
                .'you online. It has been a pleasure working with you.',

            self::AUTO_COMPLETED => 'The '.$window.' day confirmation period for this project has passed, so it has '
                .'been marked complete. Its full record remains available to you online. If anything about the work '
                .'still needs attention, please get in touch and we will help.',

            self::REOPENED => 'Further work has been scheduled on this project, so it is active again rather than '
                .'waiting for your confirmation. The new dates are below, and you will be asked to confirm the '
                .'project once that work is finished.',

            self::CANCELLED => 'This project has been cancelled and no further work will be carried out on it. '
                .'Its full record remains available to you online.',
            self::ON_HOLD => 'Work on this project has been paused. Its schedule has been released, and we will let '
                .'you know as soon as it resumes.',
            self::RESUMED => 'This project is active again. It will be scheduled shortly, and you will be notified '
                .'once the new dates are set.',
            self::ASSESSMENT_UPLOADED => 'The assessment report for this project has been uploaded and is now '
                .'available to view on the project page.',
            self::QUOTATION_UPLOADED => 'The quotation for this project has been uploaded and is now available to '
                .'view on the project page.',
            self::CONTRACT_UPLOADED => 'The contract for this project has been uploaded and is now available to '
                .'view on the project page.',
            default => 'There has been an update on this project. Open the project page for the full details.',
        };
    }

    private function detailLabel(): string
    {
        return match ($this->event) {
            self::COMPLETED, self::AWAITING_CONFIRMATION,
            self::CONFIRMATION_REMINDER, self::CONFIRMED, self::AUTO_COMPLETED => 'Summary',
            self::REOPENED => 'Reason for reopening',
            self::CANCELLED => 'Reason',
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
            default => [],
        };
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
