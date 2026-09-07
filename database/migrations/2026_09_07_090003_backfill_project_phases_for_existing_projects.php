<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A phase structure for every project that existed before phases did.
 *
 * The feature is only meaningful for projects created after it, and yet every
 * project already in the system has to keep working: task creation now demands
 * a phase, the monitoring panel now expects a denominator, and Urgent Actions
 * would otherwise report the entire back catalogue as needing setup on the
 * morning this ships.
 *
 * So every existing project is given the standard four phases and stamped
 * finalized, with no finalizer named - phase_setup_finalized_by stays null,
 * which is this system's way of saying "the system did this", exactly as the
 * activity log writes 'System' for an actor that is not a signed-in person.
 * Nobody agreed to these four phases, and the record should not claim anybody
 * did.
 *
 * Existing tasks are then distributed across those phases by what state they
 * are in rather than all being dropped onto Phase 1. A backfilled project
 * whose work is half finished reads as half finished, which is the entire
 * reason the phases are there - and it keeps the invariant the live rules
 * enforce: a phase marked completed holds no open task.
 */
return new class extends Migration
{
    /**
     * The structure every backfilled project gets. Generic on purpose: it
     * describes any installation job this company does without pretending to
     * know which one.
     *
     * @var array<int, array{title: string, description: string}>
     */
    private const DUMMY_PHASES = [
        ['title' => 'Site Preparation', 'description' => 'Prepare the work area before installation.'],
        ['title' => 'Installation', 'description' => 'Install the required equipment.'],
        ['title' => 'Testing', 'description' => 'Test the completed installation.'],
        ['title' => 'Final Inspection', 'description' => 'Perform the final inspection and verification.'],
    ];

    /**
     * Task statuses that count as finished work for the purpose of deciding
     * how far through a backfilled project is. Cancelled work is not open -
     * nobody is going to do it - so it does not hold a phase back.
     *
     * @var array<int, string>
     */
    private const SETTLED_TASK_STATUSES = ['completed', 'cancelled'];

    public function up(): void
    {
        $now = now();

        $projects = DB::table('tbl_projects')
            ->select('project_id', 'status', 'is_archived', 'created_at')
            ->orderBy('project_id')
            ->get();

        foreach ($projects as $project) {
            DB::transaction(function () use ($project, $now): void {
                $phaseIds = $this->createPhases((int) $project->project_id, $now);

                $this->distributeTasks($project, $phaseIds, $now);

                DB::table('tbl_projects')
                    ->where('project_id', $project->project_id)
                    ->update([
                        'phase_setup_status' => 'finalized',
                        'phase_count' => count($phaseIds),
                        'phase_setup_finalized_at' => $now,
                        // Left null deliberately - see the class docblock.
                        'phase_setup_finalized_by' => null,
                    ]);
            });
        }
    }

    public function down(): void
    {
        DB::table('tbl_tasks')->update(['phase_id' => null]);
        DB::table('tbl_project_phases')->delete();

        DB::table('tbl_projects')->update([
            'phase_setup_status' => 'pending',
            'phase_count' => null,
            'phase_setup_finalized_at' => null,
            'phase_setup_finalized_by' => null,
        ]);
    }

    /**
     * @return array<int, int> The new phase ids, in sequence order.
     */
    private function createPhases(int $projectId, mixed $now): array
    {
        $ids = [];

        foreach (self::DUMMY_PHASES as $index => $phase) {
            $ids[] = (int) DB::table('tbl_project_phases')->insertGetId([
                'project_id' => $projectId,
                'sequence' => $index + 1,
                'title' => $phase['title'],
                'description' => $phase['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * Put this project's existing tasks onto its new phases, and close the
     * phases that finished work fills.
     *
     * @param  array<int, int>  $phaseIds
     */
    private function distributeTasks(object $project, array $phaseIds, mixed $now): void
    {
        $phaseCount = count($phaseIds);

        $tasks = DB::table('tbl_tasks')
            ->select('task_id', 'status', 'completed_at')
            ->where('project_id', $project->project_id)
            ->orderByRaw('completed_at is null')
            ->orderBy('completed_at')
            ->orderBy('task_id')
            ->get();

        // A project that is finished is finished: every phase closes, and the
        // tasks - whatever state they are in - spread across all of them. A
        // completed project with an open task on it is a project somebody
        // closed with an override, and that is a fact the phases should show
        // rather than tidy away.
        $isClosed = (bool) $project->is_archived
            || in_array($project->status, ['completed', 'archived'], true);

        $settled = $tasks
            ->filter(fn (object $task): bool => in_array($task->status, self::SETTLED_TASK_STATUSES, true))
            ->values();

        $open = $tasks
            ->reject(fn (object $task): bool => in_array($task->status, self::SETTLED_TASK_STATUSES, true))
            ->values();

        $phasesDone = $this->phasesDone($tasks->count(), $settled->count(), $phaseCount, $isClosed);

        if ($isClosed) {
            $this->spread($tasks->pluck('task_id')->all(), $phaseIds);
        } else {
            // Finished work fills the phases that are being closed, or Phase 1
            // when none are. Everything still open lands on the phases after
            // them, so no closed phase holds an open task.
            $this->spread(
                $settled->pluck('task_id')->all(),
                array_slice($phaseIds, 0, max($phasesDone, 1))
            );

            $this->spread(
                $open->pluck('task_id')->all(),
                array_slice($phaseIds, $phasesDone)
            );
        }

        if ($phasesDone > 0) {
            DB::table('tbl_project_phases')
                ->whereIn('phase_id', array_slice($phaseIds, 0, $phasesDone))
                ->update([
                    // No completer named, for the same reason no finalizer is:
                    // nobody pressed Complete Phase on a phase that did not
                    // exist. The date is the project's own history, not a
                    // claim about who was there.
                    'completed_at' => $project->created_at ?? $now,
                    'completed_by' => null,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * How many of this project's phases the work already recorded on it has
     * earned, as a share of its finished tasks.
     *
     * A live project is never given every phase: something is always still to
     * do on a job that is still open, and a project reading 4/4 Phases while
     * its status says Ongoing would be the first thing anybody complained
     * about.
     */
    private function phasesDone(int $total, int $settled, int $phaseCount, bool $isClosed): int
    {
        if ($isClosed) {
            return $phaseCount;
        }

        if ($total === 0) {
            return 0;
        }

        return min((int) floor($settled / $total * $phaseCount), $phaseCount - 1);
    }

    /**
     * Deal a list of task ids evenly across a list of phase ids.
     *
     * @param  array<int, int>  $taskIds
     * @param  array<int, int>  $phaseIds
     */
    private function spread(array $taskIds, array $phaseIds): void
    {
        if ($taskIds === [] || $phaseIds === []) {
            return;
        }

        $perPhase = (int) ceil(count($taskIds) / count($phaseIds));

        foreach (array_chunk($taskIds, $perPhase) as $index => $chunk) {
            DB::table('tbl_tasks')
                ->whereIn('task_id', $chunk)
                // The last chunk can run past the end when the division is not
                // exact; everything left over belongs to the final phase.
                ->update(['phase_id' => $phaseIds[$index] ?? end($phaseIds)]);
        }
    }
};
