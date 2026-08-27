<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;

/**
 * Which Registered User account a project is connected to.
 *
 * A project's client - the name, the address, the number written on the job -
 * is contact information and belongs to the project. A Registered User is an
 * account on the public website, and connecting the two is what puts the
 * project on that person's My Projects page. The two are related but they are
 * not the same thing, and changing one here never touches the other.
 *
 * Nothing new is stored for the link: tbl_clients.user_id has held it since
 * accounts were first tied to project contacts, and this class is the one
 * place that writes it by hand. What is new is the ability to say "not this
 * account" - see user_unlinked_at, and ClientProjects::applyOwnership, which
 * is what stops the address fallback quietly re-linking a project an
 * administrator has just cleared.
 *
 * A project may in principle carry more than one contact row, so every write
 * here covers all of them: a link half-written is a project two accounts
 * disagree about owning.
 */
class ProjectRegisteredUser
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        // For normalise() only. Comparing two addresses is done one way in
        // this system, and a second lower/trim written here would report a
        // difference between "Office@Company.ph" and "office@company.ph".
        private readonly ClientProjects $clientProjects
    ) {}

    /**
     * The accounts an administrator may choose from.
     *
     * Every Registered User that has not been archived, deactivated ones
     * included: an account switched off today may well be switched back on,
     * and refusing to record who a project belongs to because of it would be
     * the wrong way round. The picker shows the status so the choice is made
     * with it in view.
     *
     * @return EloquentCollection<int, User>
     */
    public function candidates(): EloquentCollection
    {
        return User::query()
            ->clients()
            ->notArchived()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('name')
            ->get();
    }

    /**
     * Connect a project to a Registered User account.
     *
     * Idempotent by construction: assigning the account a project already has
     * writes the same row and records nothing, so a double-submitted dialog
     * does not fill the trail with entries about a change that did not happen.
     *
     * @throws RuntimeException when the project has no contact row to hold the
     *                          link, or the account is not one that may hold it
     */
    public function assign(Project $project, User $account): bool
    {
        $this->guardAssignable($account);

        $contacts = $this->contactsFor($project);

        if ($contacts->isEmpty()) {
            throw new RuntimeException('This project has no client information to connect an account to.');
        }

        $previous = $this->currentAccount($project);

        if ($previous && $previous->id === $account->id) {
            return false;
        }

        Client::query()
            ->where('project_id', $project->project_id)
            ->update([
                'user_id' => $account->id,
                // An assignment answers the very question the marker was
                // asked, so the marker goes.
                'user_unlinked_at' => null,
            ]);

        $this->activityLogger->record(
            $previous
                ? ActivityLog::REGISTERED_USER_ASSIGNMENT_CHANGED
                : ActivityLog::REGISTERED_USER_ASSIGNED,
            $account,
            $previous
                ? sprintf(
                    "Changed the Registered User on project '%s' from %s (%s) to %s (%s).",
                    $this->projectLabel($project),
                    $previous->fullName(),
                    $previous->email,
                    $account->fullName(),
                    $account->email
                )
                : sprintf(
                    "Assigned Registered User %s (%s) to project '%s'.",
                    $account->fullName(),
                    $account->email,
                    $this->projectLabel($project)
                ),
            $project
        );

        return true;
    }

    /**
     * Take the Registered User off a project.
     *
     * Neither record is deleted and neither is otherwise changed: the account
     * keeps its details and its other projects, and the project keeps its
     * client information, its team, its schedule and its history. All that
     * ends is the connection between them.
     */
    public function remove(Project $project): bool
    {
        $previous = $this->currentAccount($project);

        if ($previous === null) {
            return false;
        }

        Client::query()
            ->where('project_id', $project->project_id)
            ->update([
                'user_id' => null,
                // Why this is written rather than left null - see the class
                // comment, and ClientProjects::applyOwnership.
                'user_unlinked_at' => now(),
            ]);

        $this->activityLogger->record(
            ActivityLog::REGISTERED_USER_REMOVED,
            $previous,
            sprintf(
                "Removed Registered User %s (%s) from project '%s'. Both records were kept.",
                $previous->fullName(),
                $previous->email,
                $this->projectLabel($project)
            ),
            $project
        );

        return true;
    }

    /**
     * The account a project is connected to right now, queried rather than
     * read off a relation the caller may have loaded before the write.
     *
     * The join behind Project::registeredUser() is what skips a contact row
     * carrying no account, so this answers "which account", not "which contact
     * row came first".
     */
    public function currentAccount(Project $project): ?User
    {
        return $project->registeredUser()->first();
    }

    /**
     * Whether this project's contact address and its account's address are
     * different things.
     *
     * False when there is no account, and false when the two are the same
     * address written differently - the comparison is the normalised one, so
     * trailing spaces and capitals are not a difference worth telling an
     * administrator about.
     */
    public function accountEmailDiffers(Project $project): bool
    {
        return $this->divergentContacts($project)->isNotEmpty();
    }

    /**
     * Move this project's contact address onto the one its Registered User
     * signs in with.
     *
     * The one deliberate crossing between the two records this class otherwise
     * keeps apart, and it only ever runs one way. The account is not touched:
     * its address is a login credential, changing it belongs to the client
     * themselves behind an emailed code (see ProfileService::changeEmail), and
     * nothing an administrator does on a project page may reach it.
     *
     * Only contacts actually held by that account are moved. A project
     * carrying a second contact row for somebody else keeps it, because that
     * row is not this account's to rewrite.
     *
     * @return array{previous: string, current: string}|null what changed, or
     *                                                       null when there was nothing to change
     *
     * @throws RuntimeException when the project has no Registered User
     */
    public function useAccountEmail(Project $project): ?array
    {
        $account = $this->currentAccount($project);

        if ($account === null) {
            throw new RuntimeException('This project has no Registered User to take an address from.');
        }

        if (! filled($account->email)) {
            throw new RuntimeException('That account has no email address to copy.');
        }

        $contacts = $this->divergentContacts($project);

        if ($contacts->isEmpty()) {
            return null;
        }

        // The addresses being replaced, gathered before the write so the trail
        // can say what this project used to be reachable at.
        $previous = $contacts
            ->pluck('email_address')
            ->filter()
            ->unique()
            ->implode(', ');

        Client::query()
            ->whereIn('client_id', $contacts->pluck('client_id')->all())
            ->update(['email_address' => $account->email]);

        $this->activityLogger->record(
            ActivityLog::PROJECT_CONTACT_EMAIL_UPDATED,
            $account,
            sprintf(
                "Changed the contact email on project '%s' from %s to %s, the address on Registered User %s. "
                    .'The account itself was not changed.',
                $this->projectLabel($project),
                $previous,
                $account->email,
                $account->fullName()
            ),
            $project
        );

        return ['previous' => $previous, 'current' => (string) $account->email];
    }

    /**
     * The contact rows this account holds whose address is not the account's
     * own.
     *
     * @return EloquentCollection<int, Client>
     */
    private function divergentContacts(Project $project): EloquentCollection
    {
        $account = $this->currentAccount($project);

        if ($account === null || ! filled($account->email)) {
            return Client::query()->whereRaw('1 = 0')->get();
        }

        $address = $this->clientProjects->normalise($account->email);

        return $this->contactsFor($project)
            ->filter(fn (Client $contact): bool => $contact->user_id === $account->id
                && $this->clientProjects->normalise($contact->email_address) !== $address)
            ->values();
    }

    /**
     * @throws RuntimeException
     */
    private function guardAssignable(User $account): void
    {
        if (! $account->isClient()) {
            throw new RuntimeException('That account is not a Registered User.');
        }

        if ($account->isArchivedAccount()) {
            throw new RuntimeException('That account is archived. Restore it before assigning it to a project.');
        }
    }

    /**
     * @return EloquentCollection<int, Client>
     */
    private function contactsFor(Project $project): EloquentCollection
    {
        return Client::query()
            ->where('project_id', $project->project_id)
            ->get();
    }

    private function projectLabel(Project $project): string
    {
        return (string) ($project->reference_no ?? $project->name);
    }
}
