<?php

namespace App\Mail;

use App\Models\Project;

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
        ];
    }

    private function heading(): string
    {
        return match ($this->event) {
            self::COMPLETED => 'Your project is complete',
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

        return match ($this->event) {
            self::COMPLETED => 'The work on this project has been completed and signed off. Thank you for choosing '
                .$company.'. You can review the completion details, including any photographs, on the project page.',
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
            self::COMPLETED => 'Summary',
            self::CANCELLED => 'Reason',
            default => 'Details',
        };
    }
}
