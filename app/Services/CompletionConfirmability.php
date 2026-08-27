<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Support\Collection;

/**
 * Whether anybody can confirm this project's completion right now.
 *
 * A different question from "does this project have a Registered User", and
 * the difference is the whole reason this class exists. Ownership on the
 * public website is not one fact but two - see ClientProjects::applyOwnership,
 * which claims a project by the account id where there is one and by the
 * contact address where there is not - so a project with a null user_id may
 * still be perfectly confirmable by somebody who registered under the address
 * it was booked with.
 *
 * The answer is also not permanent, and nothing here treats it as though it
 * were. A project may be booked long before its client registers, and a client
 * may well register during the confirmation window: a project that nobody can
 * confirm this morning becomes confirmable the moment that account is opened,
 * with no assignment made by hand. So this is asked on every read and stored
 * nowhere, and it never decides anything irreversible - it is what an
 * administrator is shown, not a gate they are held behind. Completion is never
 * refused for want of a registered client.
 *
 * The account lookup is ClientProjects::accountFor(), which is the one
 * normalised address match in the system. Writing a second one here is exactly
 * how two pages come to disagree about whose project this is.
 */
class CompletionConfirmability
{
    /**
     * A Registered User account is connected to this project. That account can
     * see the project on My Projects and press Confirm Completion.
     */
    public const LINKED = 'linked';

    /**
     * No account is connected, but one exists under the project's contact
     * address and has not been deliberately taken off - so the address
     * fallback already puts this project on that person's My Projects page,
     * and they can confirm it.
     */
    public const CLAIMABLE = 'claimable';

    /**
     * Nobody can confirm this project on the website today. Not a verdict on
     * the project: the client may register tomorrow, and this becomes
     * CLAIMABLE the moment they do.
     */
    public const UNREACHABLE = 'unreachable';

    public function __construct(private readonly ClientProjects $clientProjects) {}

    /**
     * One of the three constants above, from the data as it stands now.
     *
     * Callers reading this for a page full of projects should eager load
     * `clients.account`, which is what keeps LINKED free of a query.
     */
    public function state(Project $project): string
    {
        $project->loadMissing('clients.account');

        if ($this->linkedAccount($project) !== null) {
            return self::LINKED;
        }

        return $this->claimableAccount($project) !== null
            ? self::CLAIMABLE
            : self::UNREACHABLE;
    }

    /**
     * The account that would confirm this project if asked today, whether it
     * got there by assignment or by address. Null when nobody can.
     */
    public function account(Project $project): ?User
    {
        $project->loadMissing('clients.account');

        return $this->linkedAccount($project) ?? $this->claimableAccount($project);
    }

    /**
     * The case the pages care about: the work is finished, the clock is
     * running, and there is nobody at the other end of it.
     */
    public function isUnreachable(Project $project): bool
    {
        return $this->state($project) === self::UNREACHABLE;
    }

    /**
     * The states of several projects at once, keyed by project id.
     *
     * For the listings, which ask this of a page of rows. Nothing clever: the
     * account lookup is shared rather than reimplemented, so this is the same
     * question asked repeatedly rather than a second way of answering it.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, string>
     */
    public function statesFor(Collection $projects): array
    {
        return $projects
            ->mapWithKeys(fn (Project $project): array => [
                $project->project_id => $this->state($project),
            ])
            ->all();
    }

    /**
     * A short sentence for a badge's tooltip, or null when there is nothing
     * worth saying - a project somebody can confirm needs no explanation.
     */
    public function hint(Project $project): ?string
    {
        if (! $this->isUnreachable($project)) {
            return null;
        }

        $deadline = $project->confirmationDeadline();

        return $deadline
            ? sprintf(
                'No registered client can confirm this project online. It completes automatically on %s.',
                $deadline->format(BusinessTime::DATE)
            )
            : 'No registered client can confirm this project online.';
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The account held on a contact row, read off the loaded relation rather
     * than queried - Project::registeredUserAccount() reads the first contact
     * only, and a project may in principle carry more than one.
     */
    private function linkedAccount(Project $project): ?User
    {
        return $project->clients
            ->map(fn (Client $contact): ?User => $contact->account)
            ->filter()
            ->first();
    }

    /**
     * An account that owns this project by address alone.
     *
     * Only a contact with no account against it and no removal recorded
     * against it either: `user_unlinked_at` is an administrator's decision
     * that this account does NOT own the project, and an answer here would
     * quietly overrule them - the same rule ClientProjects::applyOwnership
     * applies to the query behind My Projects, for the same reason.
     */
    private function claimableAccount(Project $project): ?User
    {
        return $project->clients
            ->filter(fn (Client $contact): bool => $contact->user_id === null
                && $contact->user_unlinked_at === null)
            ->map(fn (Client $contact): ?User => $this->clientProjects->accountFor($contact->email_address))
            ->filter()
            ->first();
    }
}
