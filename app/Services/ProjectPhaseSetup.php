<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProjectPhaseDraftTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writing, locking and unlocking a project's phase structure.
 *
 * The one place rows in tbl_project_phases are created, reordered or removed.
 * Everything else in the application reads phases; only this writes them, and
 * only while the project's structure is unlocked - which is the whole of the
 * "Admin and Lead Technician cannot change the count after finalization" rule.
 * They are not stopped by a hidden button, they are stopped by there being no
 * way in.
 *
 * A Super Admin's override does not get a second code path. It puts the
 * project back to `pending`, which is the state this service already knows how
 * to write, and finalizing again is the same action the first setup ended
 * with. One way in and one way out means an overridden project cannot end up
 * in a state a freshly set up one never reaches.
 */
class ProjectPhaseSetup
{
    /**
     * Sequences are rewritten in two passes, and this is where the first pass
     * parks them.
     *
     * (project_id, sequence) is unique, so swapping two phases around by
     * writing their new numbers straight in would collide the moment the first
     * update lands on a number the second one still holds. Every surviving
     * phase is therefore moved out of the way first. Comfortably past
     * MAX_PHASES and comfortably inside an unsigned smallint.
     */
    private const SEQUENCE_PARKING = 1000;

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Write a submitted structure onto a project whose phases are unlocked.
     *
     * @param  array<int, array{phase_id?: int|null, title: string, description: string}>  $rows
     *                                                                                            In the order they should be numbered. A row carrying a
     *                                                                                            phase_id is an existing phase being kept - which is what
     *                                                                                            lets a phase be renamed or moved without the tasks on it
     *                                                                                            noticing. A row without one is new.
     * @param  array<int, int>  $reassignments  phase_id => the phase its tasks
     *                                          should move to, for phases being
     *                                          removed with work still on them.
     *
     * @throws RuntimeException when the structure is locked, is the wrong size,
     *                          or would strand tasks.
     */
    public function save(Project $project, array $rows, array $reassignments = []): void
    {
        if ($project->phasesAreFinalized()) {
            throw new RuntimeException(
                'This project\'s phase structure has been finalized and can no longer be changed.'
            );
        }

        $rows = array_values($rows);

        if (count($rows) < ProjectPhase::MIN_PHASES) {
            throw new RuntimeException('A project needs at least one phase.');
        }

        if (count($rows) > ProjectPhase::MAX_PHASES) {
            throw new RuntimeException(sprintf(
                'A project can have at most %d phases.',
                ProjectPhase::MAX_PHASES
            ));
        }

        $existing = $project->phases()->get()->keyBy('phase_id');

        $keptIds = collect($rows)
            ->pluck('phase_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        // A submitted phase_id this project does not own is a form that has
        // been tampered with or a stale tab. Either way it must not be
        // silently treated as a new phase, because that would quietly detach
        // whatever tasks the real phase held.
        foreach ($keptIds as $id) {
            if (! $existing->has($id)) {
                throw new RuntimeException('That phase is no longer part of this project. Reload the page and try again.');
            }
        }

        $removed = $existing->reject(fn (ProjectPhase $phase): bool => in_array($phase->phase_id, $keptIds, true));

        $this->guardRemovals($removed, $keptIds, $reassignments);

        DB::transaction(function () use ($project, $rows, $removed, $reassignments): void {
            // Tasks move BEFORE their phase goes, so there is no window in
            // which a task points at a row that is on its way out.
            //
            // Only real tasks. A removed phase's DRAFT tasks go with it, by
            // cascade - a draft is a note about work that has not been agreed,
            // not a record of any, and the person deleting the phase has its
            // draft tasks on the screen in front of them as they do it.
            foreach ($removed as $phase) {
                if (isset($reassignments[$phase->phase_id])) {
                    $phase->tasks()->update(['phase_id' => $reassignments[$phase->phase_id]]);
                }

                $phase->delete();
            }

            // Pass one: park every surviving phase clear of the numbers about
            // to be written. See SEQUENCE_PARKING.
            $project->phases()->getQuery()->update([
                'sequence' => DB::raw('sequence + '.self::SEQUENCE_PARKING),
            ]);

            // Pass two: the real numbering, in submitted order. The phase each
            // submitted row ended up as is kept, because the draft tasks
            // underneath it are about to be written against it and a row that
            // was new a moment ago has no id the submission could have carried.
            $phaseIds = [];

            foreach ($rows as $index => $row) {
                $attributes = [
                    'sequence' => $index + 1,
                    // Provenance only - which stage of the vocabulary this row
                    // was suggested from, or null for one somebody typed. See
                    // the column's migration.
                    'stage_id' => $row['stage_id'] ?? null,
                    'title' => trim($row['title']),
                    'description' => trim($row['description']),
                ];

                if (! empty($row['phase_id'])) {
                    ProjectPhase::query()
                        ->where('phase_id', $row['phase_id'])
                        ->update($attributes);

                    $phaseIds[$index] = (int) $row['phase_id'];

                    continue;
                }

                $phaseIds[$index] = ProjectPhase::create(
                    $attributes + ['project_id' => $project->project_id]
                )->phase_id;
            }

            $this->writeDraftTasks($rows, $phaseIds);
        });
    }

    /**
     * Replace the draft tasks under a project's phases with what was just
     * submitted.
     *
     * Rewritten wholesale rather than diffed, for the same reason a project
     * type's template is: a draft row carries no history and nothing points at
     * it, so there is no identity worth preserving across a save - and a diff
     * would be a second way for what is stored to disagree with what was sent.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $phaseIds  submitted row index => phase_id
     */
    private function writeDraftTasks(array $rows, array $phaseIds): void
    {
        if ($phaseIds === []) {
            return;
        }

        ProjectPhaseDraftTask::query()
            ->whereIn('phase_id', array_values($phaseIds))
            ->delete();

        foreach ($rows as $index => $row) {
            $tasks = array_values($row['tasks'] ?? []);

            foreach ($tasks as $order => $task) {
                ProjectPhaseDraftTask::create([
                    'phase_id' => $phaseIds[$index],
                    'sequence' => $order + 1,
                    'title' => trim((string) $task['title']),
                    'description' => trim((string) $task['description']),
                    'technician_id' => $task['technician_id'] ?? null,
                    'start_date' => $task['start_date'] ?? null,
                    'due_date' => $task['due_date'] ?? null,
                ]);
            }
        }
    }

    /**
     * Lock the structure, and let the project get on with being monitored.
     *
     * After this, `phase_count` is the denominator every progress figure on
     * the project is read against, and nobody below a Super Admin can move it.
     *
     * @param  array<int, array{phase_id?: int|null, title: string, description: string}>  $rows
     * @param  array<int, int>  $reassignments
     *
     * @throws RuntimeException
     */
    public function finalize(Project $project, User $user, array $rows, array $reassignments = []): void
    {
        $wasOverridden = $project->phaseStructureWasOverridden();
        $previousCount = $project->phase_count;

        $this->save($project, $rows, $reassignments);

        // The drafts become real work here and nowhere else. Doing it after
        // save() rather than inside it is what makes Save Without Locking safe
        // to press at five o'clock: the same rows are written either way, and
        // only this path turns them into tasks the board lists and technicians
        // are told about.
        $seeded = $this->convertDraftTasks($project);

        $count = $project->phases()->count();

        $project->forceFill([
            'phase_setup_status' => Project::PHASE_SETUP_FINALIZED,
            'phase_count' => $count,
            'phase_setup_finalized_at' => now(),
            'phase_setup_finalized_by' => $user->id,
        ])->save();

        // Re-finalizing after an override is a different event to read about
        // than a project being set up for the first time, so the sentence says
        // which one happened and what the count did.
        $this->activityLogger->record(
            ActivityLog::PROJECT_PHASES_FINALIZED,
            null,
            $wasOverridden && $previousCount !== null
                ? sprintf(
                    'Re-finalized the phase structure for %s after an override: %d %s (was %d).',
                    $project->reference_no,
                    $count,
                    $count === 1 ? 'phase' : 'phases',
                    $previousCount
                )
                : sprintf(
                    'Finalized the phase structure for %s: %d %s.',
                    $project->reference_no,
                    $count,
                    $count === 1 ? 'phase' : 'phases'
                ),
            $project
        );

        if ($seeded->isNotEmpty()) {
            $this->activityLogger->record(
                ActivityLog::TASK_CREATED,
                null,
                sprintf(
                    'Created %d %s on %s from its phase setup.',
                    $seeded->count(),
                    $seeded->count() === 1 ? 'task' : 'tasks',
                    $project->reference_no
                ),
                $project
            );

            $this->notifications->tasksCreatedInPhaseSetup($project, $seeded);
        }
    }

    /**
     * Turn a finalized project's draft tasks into real ones.
     *
     * The drafts are deleted as they are converted, which is also the guard
     * against a second helping: a Super Admin who unlocks a finalized structure
     * and finalizes it again finds no drafts under the phases that already
     * seeded theirs, so nothing is duplicated. Only tasks typed during the
     * unlock - on a phase they have just added, say - are new enough to still
     * be drafts, and those are exactly the ones that should be created.
     *
     * @return Collection<int, Task>
     */
    private function convertDraftTasks(Project $project): Collection
    {
        $phaseIds = $project->phases()->pluck('phase_id');

        if ($phaseIds->isEmpty()) {
            return collect();
        }

        return DB::transaction(function () use ($project, $phaseIds): Collection {
            $drafts = ProjectPhaseDraftTask::query()
                ->whereIn('phase_id', $phaseIds)
                ->orderBy('phase_id')
                ->inOrder()
                ->get();

            if ($drafts->isEmpty()) {
                return collect();
            }

            $tasks = $drafts->map(fn (ProjectPhaseDraftTask $draft): Task => Task::create([
                'project_id' => $project->project_id,
                'phase_id' => $draft->phase_id,
                'technician_id' => $draft->technician_id,
                'task_title' => $draft->title,
                'task_description' => $draft->description,
                'start_date' => $draft->start_date,
                'due_date' => $draft->due_date,
                // A task nobody has been given is 'unassigned', which is a
                // state this system has always had and already draws - the
                // Missing Technician & Date chips are how somebody finds it
                // again on the board. One that was given an owner during setup
                // starts where any other new task starts.
                'status' => $draft->technician_id === null ? 'unassigned' : 'pending',
            ]));

            ProjectPhaseDraftTask::query()->whereIn('phase_id', $phaseIds)->delete();

            return $tasks;
        });
    }

    /**
     * Unlock a finalized structure so a Super Admin can change it.
     *
     * The project goes back to `pending`, which takes the monitoring interface
     * away and puts the setup screen back - and stops new tasks being filed
     * against a structure that is in the middle of being rearranged. The
     * override is recorded on the project permanently, not just in the log:
     * anybody reading this project's progress afterwards should be able to see
     * that the denominator was once something else.
     *
     * The dialog confirms the decision in one sentence and asks for nothing
     * else, so what is recorded is who unlocked it and when. `$reason` stays
     * on the signature for a caller that has one to give.
     *
     * @throws RuntimeException
     */
    public function override(Project $project, User $user, ?string $reason = null): void
    {
        if (! $user->isSuperAdmin()) {
            throw new RuntimeException('Only a Super Admin can override a finalized phase structure.');
        }

        if ($project->needsPhaseSetup()) {
            throw new RuntimeException('This project\'s phase structure is not locked.');
        }

        $project->forceFill([
            'phase_setup_status' => Project::PHASE_SETUP_PENDING,
            'phase_structure_overridden_at' => now(),
            'phase_structure_overridden_by' => $user->id,
            'phase_structure_override_reason' => $reason,
        ])->save();

        $this->activityLogger->record(
            ActivityLog::PROJECT_PHASE_STRUCTURE_OVERRIDDEN,
            null,
            sprintf(
                'Unlocked the phase structure for %s, finalized with %d %s.%s',
                $project->reference_no,
                (int) $project->phase_count,
                (int) $project->phase_count === 1 ? 'phase' : 'phases',
                $reason !== null ? ' Reason: '.$reason : ''
            ),
            $project
        );
    }

    /**
     * The phases in a save that are being removed with tasks still on them,
     * and where each one's work has been told to go.
     *
     * Public because the setup screen asks the same question before the form
     * is submitted, so a Super Admin is told what a removal will cost while
     * they can still change their mind.
     *
     * @return Collection<int, array{phase: ProjectPhase, tasks: int}>
     */
    public function phasesHoldingTasks(Project $project): Collection
    {
        return $project->phases()
            ->withCount('tasks')
            ->inOrder()
            ->get()
            ->filter(fn (ProjectPhase $phase): bool => $phase->tasks_count > 0)
            ->map(fn (ProjectPhase $phase): array => [
                'phase' => $phase,
                'tasks' => (int) $phase->tasks_count,
            ])
            ->values();
    }

    /**
     * Refuse a removal that would strand work.
     *
     * The rule the spec is emphatic about: a phase holding tasks does not just
     * disappear, and its tasks are never deleted along with it. The remover
     * has to say which phase the work moves to, and that phase has to be one
     * that is surviving this save - moving tasks onto another phase that is
     * also being removed would strand them one step further along.
     *
     * @param  Collection<int, ProjectPhase>  $removed
     * @param  array<int, int>  $keptIds
     * @param  array<int, int>  $reassignments
     *
     * @throws RuntimeException
     */
    private function guardRemovals(Collection $removed, array $keptIds, array $reassignments): void
    {
        foreach ($removed as $phase) {
            $taskCount = $phase->tasks()->count();

            if ($taskCount === 0) {
                continue;
            }

            $target = $reassignments[$phase->phase_id] ?? null;

            if ($target === null) {
                throw new RuntimeException(sprintf(
                    '%s still has %d %s on it. Move that work to another phase before removing it.',
                    $phase->label(),
                    $taskCount,
                    $taskCount === 1 ? 'task' : 'tasks'
                ));
            }

            if (! in_array((int) $target, $keptIds, true)) {
                throw new RuntimeException(sprintf(
                    'The tasks on %s have to move to a phase this project is keeping.',
                    $phase->label()
                ));
            }
        }
    }
}
