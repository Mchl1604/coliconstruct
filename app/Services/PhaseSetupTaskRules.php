<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProjectTypeStageTask;
use App\Models\Technician;
use Illuminate\Support\Facades\DB;

/**
 * What a task typed on the phase setup screen has to satisfy.
 *
 * The setup screen asks for a technician and two dates and does NOT insist on
 * them, which is the one way it differs from every other task form in this
 * system. That is deliberate: somebody laying out a twelve-task structure
 * before the crew is settled should be able to write down the work now and
 * decide who does it later, and a task with neither owner nor dates is already
 * a state this application draws properly - see Task::GAP_LABELS, which has had
 * a name for it since long before phases existed.
 *
 * What does not relax is what happens once a field IS filled in. A technician
 * named here has to be on this project's team and able to receive work, and
 * dates given here have to fall on days the project is actually booked, exactly
 * as TaskController would demand. Optional means "may be left empty", not "may
 * be wrong".
 *
 * The one rule with no equivalent elsewhere is that dates come as a pair. A
 * task with a start and no deadline is not a task somebody can be held to, and
 * the task board has no way to show it - so the screen takes both or neither.
 */
class PhaseSetupTaskRules
{
    public function __construct(
        private readonly TaskScheduleRules $scheduleRules,
        private readonly TaskAssignmentRules $assignmentRules,
    ) {}

    /**
     * Everything wrong with the tasks in a submitted structure, keyed by the
     * input they belong to so the form can put each message beside its field.
     *
     * @param  array<int, array<string, mixed>>  $rows  the phase rows, each
     *                                                  carrying a 'tasks' list
     * @return array<string, string>
     */
    public function errors(Project $project, array $rows): array
    {
        $ranges = $this->scheduleRules->ranges($project->project_id);
        $team = $this->assignableTechnicianIds($project);

        $errors = [];

        foreach ($rows as $phaseIndex => $row) {
            $tasks = array_values($row['tasks'] ?? []);

            if (count($tasks) > ProjectTypeStageTask::MAX_PER_STAGE) {
                $errors[sprintf('phases.%d.tasks', $phaseIndex)] = sprintf(
                    'Phase %d has more than %d tasks. Split the work across phases.',
                    $phaseIndex + 1,
                    ProjectTypeStageTask::MAX_PER_STAGE
                );

                continue;
            }

            foreach ($tasks as $taskIndex => $task) {
                $key = sprintf('phases.%d.tasks.%d', $phaseIndex, $taskIndex);
                $where = sprintf('Phase %d, task %d', $phaseIndex + 1, $taskIndex + 1);

                foreach ($this->taskErrors($task, $where, $ranges, $team) as $field => $message) {
                    $errors[$key.'.'.$field] = $message;
                }
            }
        }

        return $errors;
    }

    /**
     * Turn a submitted task row into the shape the writers store, with blank
     * strings read as "not answered" rather than as answers.
     *
     * @param  array<string, mixed>  $task
     * @return array{title: string, description: string, technician_id: int|null, start_date: string|null, due_date: string|null}
     */
    public function normalise(array $task): array
    {
        return [
            'title' => trim((string) ($task['title'] ?? '')),
            'description' => trim((string) ($task['description'] ?? '')),
            'technician_id' => $this->optionalInt($task['technician_id'] ?? null),
            'start_date' => $this->optionalString($task['start_date'] ?? null),
            'due_date' => $this->optionalString($task['due_date'] ?? null),
        ];
    }

    /**
     * The phase rows a project's setup screen should redraw after a refused
     * submit, with their tasks normalised.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function normaliseRows(array $rows): array
    {
        return collect($rows)
            ->map(function (array $row): array {
                $row['tasks'] = collect($row['tasks'] ?? [])
                    ->map(fn (array $task): array => $this->normalise($task))
                    ->values()
                    ->all();

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * How many phases a structure may hold, restated here so the setup screen
     * and this service quote the same number.
     */
    public function maxTasksPerPhase(): int
    {
        return ProjectTypeStageTask::MAX_PER_STAGE;
    }

    /**
     * The phases of a project that has been unlocked, with the real tasks
     * already on them counted.
     *
     * Only ever non-zero after a Super Admin override. Those tasks are real
     * work with real owners and are managed on the task board - the setup
     * screen shows the count so a removal can warn about them, and does not
     * offer to edit them.
     *
     * @return array<int, int> phase_id => open task count
     */
    public function realTaskCounts(Project $project): array
    {
        return $project->phases()
            ->withCount('tasks')
            ->get()
            ->mapWithKeys(fn (ProjectPhase $phase): array => [
                $phase->phase_id => (int) $phase->tasks_count,
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $task
     * @param  array<int, array{start: string, end: string}>  $ranges
     * @param  array<int, int>  $team
     * @return array<string, string>
     */
    private function taskErrors(array $task, string $where, array $ranges, array $team): array
    {
        $task = $this->normalise($task);

        $errors = [];

        if ($task['title'] === '') {
            $errors['title'] = $where.' needs a title.';
        }

        if ($task['description'] === '') {
            $errors['description'] = $where.' needs a description.';
        }

        if ($task['technician_id'] !== null) {
            if (! in_array($task['technician_id'], $team, true)) {
                $errors['technician_id'] = $where.' is assigned to somebody who is not on this project\'s team.';
            } else {
                $technician = Technician::query()->with('account')->find($task['technician_id']);

                if ($technician && ! $this->assignmentRules->canReceiveWork($technician)) {
                    $errors['technician_id'] = $this->assignmentRules->refusal($technician);
                }
            }
        }

        $hasStart = $task['start_date'] !== null;
        $hasDue = $task['due_date'] !== null;

        // Both or neither. See the class docblock.
        if ($hasStart !== $hasDue) {
            $errors[$hasStart ? 'due_date' : 'start_date'] = $where
                .' needs both a start date and an end date, or neither.';

            return $errors;
        }

        if (! $hasStart) {
            return $errors;
        }

        if ($task['due_date'] < $task['start_date']) {
            $errors['due_date'] = $where.' cannot end before it starts.';

            return $errors;
        }

        if ($ranges === []) {
            $errors['start_date'] = 'This project has no schedule yet, so its tasks cannot be given dates.';

            return $errors;
        }

        if (! $this->scheduleRules->windowCovers($ranges, $task['start_date'], $task['due_date'])) {
            $errors['start_date'] = sprintf(
                '%s has to start and finish on days this project is booked (%s).',
                $where,
                $this->scheduleRules->describe($ranges)
            );
        }

        return $errors;
    }

    /**
     * Technicians on the project's team whose membership is still open.
     *
     * The same test TaskController's own technician rule makes - a technician
     * taken off the team keeps their row, because it carries the dates they
     * worked, so the membership has to be an open one.
     *
     * @return array<int, int>
     */
    private function assignableTechnicianIds(Project $project): array
    {
        return DB::table('tbl_project_technicians')
            ->where('project_id', $project->project_id)
            ->whereNull('removed_at')
            ->pluck('technician_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }

        return (int) $value;
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
