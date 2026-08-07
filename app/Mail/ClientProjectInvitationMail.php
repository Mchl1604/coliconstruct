<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Project;

/**
 * The welcome sent to the address a project was booked under.
 *
 * It exists to connect a project to a person: the public website matches work
 * to an account by email address, so the one thing this message has to get
 * across is that registering with *this* address is what makes the project
 * appear. Somebody who already has an account simply signs in.
 */
class ClientProjectInvitationMail extends SystemMail
{
    /**
     * @param  bool  $hasAccount  Whether the address already has an account,
     *                            which decides whether the reader is told to
     *                            sign in or to register.
     * @param  array<int, string>  $projectTypes
     */
    public function __construct(
        public readonly Project $project,
        public readonly Client $contact,
        public readonly bool $hasAccount,
        public readonly array $projectTypes = [],
    ) {}

    protected function subjectLine(): string
    {
        return sprintf(
            'Your %s project %s has been created',
            config('company.name'),
            $this->project->reference_no
        );
    }

    protected function template(): string
    {
        return 'emails.client-project-invitation';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'project' => $this->project,
            'contact' => $this->contact,
            'hasAccount' => $this->hasAccount,
            'projectTypes' => $this->projectTypes,
            'clientName' => $this->contact->fullname ?: $this->contact->company_name,
            'contactEmail' => $this->contact->email_address,
            // A guest lands on the sign-in page; somebody without an account
            // needs the registration form, pre-named with their address so the
            // match cannot be got wrong by a typo.
            'actionUrl' => $this->hasAccount
                ? route('auth.login')
                : route('auth.register'),
            'projectsUrl' => route('public.projects'),
        ];
    }
}
