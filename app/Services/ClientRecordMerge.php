<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns tbl_clients from one row per project into one row per person, and back.
 *
 * Called by the migration that makes the switch, and kept here rather than in
 * the migration so the grouping can be tested on its own. Written against the
 * query builder, not the models: it runs while the schema is half way between
 * the two shapes, and the models only describe the finished one.
 *
 * Two rows are the same person when they share an account (`user_id`) or an
 * address, compared the way ClientProjects compares them - trimmed and
 * lower-cased. The match is transitive: A shares an address with B and B
 * shares an account with C, so all three are one person. A row with neither
 * is a person of its own.
 */
class ClientRecordMerge
{
    public function __construct(private readonly ClientProjects $clientProjects) {}

    /**
     * Keep one row per person, point every project at it, delete the rest.
     *
     * Expects tbl_projects.client_id to exist (nullable) and
     * tbl_clients.project_id to still be there. The row kept is the one on the
     * person's most recent project, and its details win - except that an
     * account found on any of their rows is kept, because losing it would take
     * every one of their projects off their My Projects page.
     *
     * @return array{people: int, removed: int, placeholders: int, conflicts: int}
     */
    public function merge(): array
    {
        return DB::transaction(function (): array {
            $rows = $this->rowsNewestFirst();
            $removed = 0;
            $conflicts = 0;

            $groups = $this->groupByPerson($rows);

            foreach ($groups as $members) {
                $keeper = $members->first();
                $accounts = $members->pluck('user_id')->filter()->unique()->values();

                if ($accounts->count() > 1) {
                    $conflicts++;

                    Log::warning('Client merge: one person carries more than one account.', [
                        'client_ids' => $members->pluck('client_id')->all(),
                        'user_ids' => $accounts->all(),
                        'kept_user_id' => $keeper->user_id ?? $accounts->first(),
                    ]);
                }

                $userId = $keeper->user_id ?? $accounts->first();

                // The keeper's address wins; another row's fills it in only
                // when the keeper has none. Any address in the group is this
                // group's alone, so borrowing one cannot collide.
                $email = $members
                    ->map(fn (object $row): string => $this->clientProjects->normalise($row->email_address))
                    ->first(fn (string $address): bool => $address !== '');

                DB::table('tbl_clients')
                    ->where('client_id', $keeper->client_id)
                    ->update([
                        'user_id' => $userId,
                        // An account on the record answers the question the
                        // marker was asked, so it only survives without one.
                        'user_unlinked_at' => $userId === null ? $keeper->user_unlinked_at : null,
                        'email_address' => $email,
                    ]);

                DB::table('tbl_projects')
                    ->whereIn('project_id', $members->pluck('project_id')->all())
                    ->update(['client_id' => $keeper->client_id]);

                $duplicates = $members->skip(1)->pluck('client_id')->all();

                if ($duplicates !== []) {
                    $removed += DB::table('tbl_clients')->whereIn('client_id', $duplicates)->delete();
                }
            }

            return [
                'people' => $groups->count(),
                'removed' => $removed,
                'placeholders' => $this->giveOrphanedProjectsAClient(),
                'conflicts' => $conflicts,
            ];
        });
    }

    /**
     * Back to one row per project, for the migration's down().
     *
     * Expects tbl_clients.project_id to exist (nullable) and
     * tbl_projects.client_id to still be there. Each client's first project
     * takes the row itself; every later one gets a copy. A client with no
     * project left has nowhere to go in the old shape and is removed.
     */
    public function split(): void
    {
        DB::transaction(function (): void {
            $placed = [];

            DB::table('tbl_projects')
                ->whereNotNull('client_id')
                ->orderBy('project_id')
                ->select(['project_id', 'client_id'])
                ->chunkById(500, function (Collection $projects) use (&$placed): void {
                    foreach ($projects as $project) {
                        if (! isset($placed[$project->client_id])) {
                            DB::table('tbl_clients')
                                ->where('client_id', $project->client_id)
                                ->update(['project_id' => $project->project_id]);

                            $placed[$project->client_id] = true;

                            continue;
                        }

                        $copy = (array) DB::table('tbl_clients')->where('client_id', $project->client_id)->first();

                        unset($copy['client_id']);
                        $copy['project_id'] = $project->project_id;

                        DB::table('tbl_clients')->insert($copy);
                    }
                }, 'project_id');

            DB::table('tbl_clients')->whereNull('project_id')->delete();
        });
    }

    /**
     * Every client row with what grouping needs, the most recent project's
     * row first.
     *
     * @return Collection<int, object>
     */
    private function rowsNewestFirst(): Collection
    {
        return DB::table('tbl_clients')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_clients.project_id')
            ->orderByDesc('tbl_projects.created_at')
            ->orderByDesc('tbl_projects.project_id')
            ->orderByDesc('tbl_clients.client_id')
            ->get([
                'tbl_clients.client_id',
                'tbl_clients.project_id',
                'tbl_clients.user_id',
                'tbl_clients.user_unlinked_at',
                'tbl_clients.email_address',
            ])
            ->values();
    }

    /**
     * The rows split into people, each person's rows still newest first.
     *
     * A union-find over row positions: every account and every address points
     * at the first row that carried it, and a later row carrying either joins
     * that row's group.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, Collection<int, object>>
     */
    private function groupByPerson(Collection $rows): Collection
    {
        $parent = range(0, max($rows->count() - 1, 0));
        $firstSeen = [];

        $root = function (int $position) use (&$parent): int {
            while ($parent[$position] !== $position) {
                $parent[$position] = $parent[$parent[$position]];
                $position = $parent[$position];
            }

            return $position;
        };

        foreach ($rows as $position => $row) {
            foreach ($this->identityKeys($row) as $key) {
                if (! isset($firstSeen[$key])) {
                    $firstSeen[$key] = $position;

                    continue;
                }

                $a = $root($firstSeen[$key]);
                $b = $root($position);

                if ($a !== $b) {
                    // The older root joins the newer one, so a group's root is
                    // always its most recent row.
                    $parent[max($a, $b)] = min($a, $b);
                }
            }
        }

        return $rows
            ->groupBy(fn (object $row, int $position): int => $root($position), preserveKeys: true)
            ->map(fn (Collection $members): Collection => $members->sortKeys()->values())
            ->values();
    }

    /**
     * What identifies the person on one row: their account, their address,
     * both, or neither.
     *
     * @return array<int, string>
     */
    private function identityKeys(object $row): array
    {
        $keys = [];

        if ($row->user_id !== null) {
            $keys[] = 'user:'.$row->user_id;
        }

        $address = $this->clientProjects->normalise($row->email_address);

        if ($address !== '') {
            $keys[] = 'email:'.$address;
        }

        return $keys;
    }

    /**
     * A project with no client row cannot take a NOT NULL client_id, so it is
     * given one rather than left to fail the migration half way through.
     *
     * The wizard has always written a client with every project, so this
     * should find nothing. The project's name stands in for the person
     * because the wizard names a project after its client.
     */
    private function giveOrphanedProjectsAClient(): int
    {
        $orphans = DB::table('tbl_projects')->whereNull('client_id')->get(['project_id', 'name']);

        foreach ($orphans as $project) {
            $name = filled($project->name) ? (string) $project->name : 'Unknown client';

            $clientId = DB::table('tbl_clients')->insertGetId([
                'project_id' => $project->project_id,
                'client_type' => 'Residential',
                'firstname' => $name,
                'fullname' => $name,
            ], 'client_id');

            DB::table('tbl_projects')
                ->where('project_id', $project->project_id)
                ->update(['client_id' => $clientId]);

            Log::warning('Client merge: project had no client, placeholder created.', [
                'project_id' => $project->project_id,
                'client_id' => $clientId,
            ]);
        }

        return $orphans->count();
    }
}
