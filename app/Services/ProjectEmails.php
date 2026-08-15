<?php

namespace App\Services;

use App\Mail\ClientProjectInvitationMail;
use App\Mail\ProjectUpdateMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which project events reach a client's inbox, and in what words.
 *
 * The counterpart to NotificationService, and split from it deliberately. A
 * bell notification is cheap and internal, so the people who run the work get
 * one for everything; an email is an interruption to somebody outside the
 * company, so a client gets one only for the handful of things they would
 * actually want to be told about - their project opening, finishing, stopping,
 * starting again, and a document arriving for them to read.
 *
 * Nothing here emails staff. They read the bell, and doubling every internal
 * notification into an inbox is exactly the noise the requirement warns off.
 */
class ProjectEmails
{
    public function __construct(
        private readonly EmailService $email,
        private readonly ActivityLogger $activityLogger
    ) {}

    /**
     * Welcome the client a project was booked for.
     *
     * The only project email that is not about a change of state: it exists to
     * tell somebody the account they need, and which address to open it with.
     */
    public function projectCreated(Project $project): void
    {
        $project->loadMissing(['clients', 'projectTypes']);

        $types = $project->projectTypes->pluck('type_name')->all();

        foreach ($this->contacts($project) as $contact) {
            $sent = $this->email->send($contact->email_address, new ClientProjectInvitationMail(
                $project,
                $contact,
                hasAccount: $this->hasAccount($contact->email_address),
                projectTypes: $types
            ));

            if ($sent) {
                $this->activityLogger->record(
                    ActivityLog::INVITATION_EMAIL_SENT,
                    null,
                    sprintf(
                        'Emailed a project invitation for %s to %s.',
                        $project->reference_no,
                        $contact->email_address
                    ),
                    $project
                );
            }
        }
    }

    public function projectCompleted(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::COMPLETED, $project->completion_summary);
    }

    /**
     * The work is finished and the client is being asked to say so.
     *
     * The one project email that asks for a decision rather than announcing
     * one, which is why it is sent the moment completion is requested rather
     * than waiting for anything else to happen.
     */
    public function projectAwaitingConfirmation(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::AWAITING_CONFIRMATION, $project->completion_summary);
    }

    /**
     * Day five of seven. Sent by the scheduled run, and idempotent by the
     * column it stamps rather than by anything here.
     */
    public function completionReminder(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::CONFIRMATION_REMINDER, $project->completion_summary);
    }

    public function completionConfirmed(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::CONFIRMED, $project->completion_summary);
    }

    public function projectAutoCompleted(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::AUTO_COMPLETED, $project->completion_summary);
    }

    /**
     * A project put back to work, and the dates it was put back to.
     *
     * One email rather than two. "Your project has been reopened" and "here
     * are its new dates" describe a single decision taken in a single moment,
     * and arriving as two messages a second apart reads as a fault rather than
     * as thoroughness - so the new schedule travels in this email's details
     * table, which ProjectUpdateMail reads from the project itself.
     */
    public function projectReopened(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::REOPENED, $project->reopen_reason);
    }

    public function projectCancelled(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::CANCELLED, $project->cancellation_reason);
    }

    public function projectPutOnHold(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::ON_HOLD);
    }

    public function projectResumed(Project $project): void
    {
        $this->update($project, ProjectUpdateMail::RESUMED);
    }

    /**
     * A document the client can now read.
     *
     * @param  string  $documentType  assessment, quotation or contract.
     */
    public function documentUploaded(Project $project, string $documentType): void
    {
        $event = match ($documentType) {
            'assessment' => ProjectUpdateMail::ASSESSMENT_UPLOADED,
            'quotation' => ProjectUpdateMail::QUOTATION_UPLOADED,
            'contract' => ProjectUpdateMail::CONTRACT_UPLOADED,
            // Anything else is an internal document type with no client-facing
            // wording, so nothing is sent rather than something vague.
            default => null,
        };

        if ($event === null) {
            return;
        }

        $this->update($project, $event);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function update(Project $project, string $event, ?string $detail = null): void
    {
        foreach ($this->contacts($project) as $contact) {
            $this->email->send($contact->email_address, new ProjectUpdateMail(
                $project,
                $event,
                recipientName: $contact->fullname ?: $contact->company_name,
                detail: $detail
            ));
        }
    }

    /**
     * The addresses a project's client can be reached at.
     *
     * Contacts carry the address themselves rather than pointing at an
     * account, which is the whole point: a client is emailed about their
     * project whether or not they have ever registered.
     *
     * @return Collection<int, Client>
     */
    private function contacts(Project $project): Collection
    {
        $project->loadMissing('clients');

        return $project->clients
            ->filter(fn (Client $client): bool => filter_var($client->email_address, FILTER_VALIDATE_EMAIL) !== false)
            ->unique('email_address')
            ->values();
    }

    /**
     * Whether an address already has an account, which decides whether the
     * invitation asks the reader to sign in or to register.
     */
    private function hasAccount(string $email): bool
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->exists();
    }
}
