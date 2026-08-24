<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Which projects belong to a client account.
 *
 * Ownership is derived from the email address rather than stored as a link:
 * tbl_clients holds a project's contact details and requires a project_id, so
 * it cannot represent an account that has no work yet. Matching on the address
 * means a project created before the client registers becomes theirs the
 * moment they do, with nothing to reassign by hand.
 *
 * The match is on a normalised address - trimmed and lower-cased on both sides
 * - so "J.DelaCruz@Example.com " on a project reaches the account registered
 * as "j.delacruz@example.com".
 */
class ClientProjects
{
    /**
     * The order the client's own page reads in: work that needs them, then
     * work that is happening, then work that is coming, then work that is
     * finished, then work that is not.
     *
     * A project awaiting their confirmation leads the list. It is the only
     * entry on the page asking them to do something, and it has a deadline -
     * burying it below the ongoing work is how a client misses the seven days.
     *
     * @var array<string, int>
     */
    private const STATUS_RANK = [
        Project::STATUS_AWAITING_CLIENT_CONFIRMATION => 0,
        'ongoing' => 1,
        'pending' => 2,
        'unscheduled' => 3,
        'completed' => 4,
        'cancelled' => 5,
        'archived' => 6,
    ];

    public function normalise(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /**
     * Every project whose client contact carries this account's address.
     *
     * @return EloquentCollection<int, Project>
     */
    public function forUser(User $user): EloquentCollection
    {
        return $this->forOwner($user);
    }

    /**
     * @return EloquentCollection<int, Project>
     */
    public function forEmail(?string $email): EloquentCollection
    {
        return $this->forOwner(null, $email);
    }

    /**
     * Work owned by an account, by an address, or by both.
     *
     * The account's own id is the real link and is checked first; the address
     * is the fallback, and it still matters - a project booked before its
     * client registered carries no id until they do. A caller with only an
     * address (an invitation being sent, say) passes that alone.
     *
     * @return EloquentCollection<int, Project>
     */
    private function forOwner(?User $user, ?string $email = null): EloquentCollection
    {
        $normalised = $this->normalise($email ?? $user?->email);

        if ($user === null && $normalised === '') {
            return Project::query()->whereRaw('1 = 0')->get();
        }

        return Project::query()
            ->with([
                'clients',
                'schedules',
                'projectTypes',
                'projectTechnicians.technician.account',
            ])
            ->whereHas('clients', fn ($query) => $this->applyOwnership($query, $user, $normalised))
            ->where('is_archived', false)
            ->get()
            // One sortable key rather than an array of closures: Collection's
            // multi-key sortBy treats a callable as a comparator, not as a
            // value to sort on. Newest first inside each group, so the id is
            // subtracted from a ceiling to invert it.
            ->sortBy(fn (Project $project): string => sprintf(
                '%d-%012d',
                self::STATUS_RANK[$project->status] ?? 9,
                PHP_INT_MAX - (int) $project->project_id
            ))
            ->values();
    }

    /**
     * One project, but only if it belongs to this account.
     *
     * Returns null rather than throwing so the caller decides between 404 and
     * a friendlier answer; either way another client's project is never
     * reachable by guessing an id.
     */
    public function findForUser(User $user, int $projectId): ?Project
    {
        $normalised = $this->normalise($user->email);

        return Project::query()
            ->with([
                'clients',
                'schedules',
                'projectTypes',
                'projectTechnicians.technician.account',
                'tasks',
                'completionPhotos',
                // The Project Documents buttons, which only appear for the
                // documents a project actually has.
                'documents',
                // The client's tracker: what the technicians reported, newest
                // first, with whatever they photographed. report_date carries
                // no time, so several reports filed on one day would come back
                // in whatever order the database chose - most recently filed
                // breaks the tie.
                // Archived reports are left out: the tracker shows what is on
                // the active record, and archiving one takes it off every
                // active list including this one.
                'reports' => fn ($query) => $query->active()
                    ->with('images')
                    ->orderByDesc('report_date')
                    ->orderByDesc('id'),
            ])
            ->where('project_id', $projectId)
            ->where('is_archived', false)
            ->whereHas('clients', fn ($query) => $this->applyOwnership($query, $user, $normalised))
            ->first();
    }

    /**
     * "This contact is theirs": by account, or by address, or either.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|Builder<Client>  $query
     */
    private function applyOwnership($query, ?User $user, string $normalisedEmail): void
    {
        $query->where(function ($owned) use ($user, $normalisedEmail): void {
            if ($user !== null) {
                $owned->where('user_id', $user->id);
            }

            if ($normalisedEmail !== '') {
                // Compared in SQL on both sides so the whole match stays one
                // scan rather than a filter in PHP. Still needed alongside the
                // id: a project booked before its client registered carries no
                // id until they do.
                $owned->orWhereRaw('LOWER(TRIM(email_address)) = ?', [$normalisedEmail]);
            }
        });
    }

    /**
     * Whether an address already has a client account behind it.
     *
     * Used when a project is created: a match means the project is visible to
     * that client immediately, and no match is not an error - the project is
     * created anyway and becomes visible if the account is opened later.
     */
    public function accountFor(?string $email): ?User
    {
        $normalised = $this->normalise($email);

        if ($normalised === '') {
            return null;
        }

        return User::query()
            ->where('role', User::ROLE_CLIENT)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalised])
            ->first();
    }

    /**
     * How far along a project is, as a whole percentage of its tasks.
     *
     * Derived rather than stored: a task completed is progress made, and
     * nothing has to be kept in step.
     */
    public function progressFor(Project $project): int
    {
        // Awaiting confirmation counts as done: the work is finished, and a
        // bar reading anything less would contradict the message asking the
        // client to confirm that very thing.
        if ($project->isWorkFinished()) {
            return 100;
        }

        if ($project->status === 'cancelled' || $project->status === 'archived') {
            return 0;
        }

        $project->loadMissing('tasks');

        $total = $project->tasks->count();

        if ($total === 0) {
            return 0;
        }

        $done = $project->tasks->where('status', 'completed')->count();

        return (int) round($done / $total * 100);
    }

    /**
     * The lead technician's name, or null while the project has none.
     */
    public function leadTechnicianName(Project $project): ?string
    {
        $project->loadMissing('projectTechnicians.technician.account');

        $lead = $project->projectTechnicians
            ->first(fn ($projectTechnician): bool => $projectTechnician->technician?->account?->role === User::ROLE_LEAD_TECHNICIAN);

        return $lead?->technician?->name;
    }

    /**
     * Every project contact carrying this address, for the linking check.
     *
     * @return EloquentCollection<int, Client>
     */
    public function contactsFor(?string $email): EloquentCollection
    {
        $normalised = $this->normalise($email);

        if ($normalised === '') {
            return Client::query()->whereRaw('1 = 0')->get();
        }

        return Client::query()
            ->whereRaw('LOWER(TRIM(email_address)) = ?', [$normalised])
            ->get();
    }
}
