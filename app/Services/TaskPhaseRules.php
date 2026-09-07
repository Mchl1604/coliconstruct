<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A task belongs to one of its project's finalized phases.
 *
 * The task-side half of the phase lock, and stated once here for the same
 * reason TaskScheduleRules and TaskAssignmentRules are: four endpoints across
 * two portals create and edit tasks, and a phase rule enforced in three of
 * them is not a rule.
 *
 * Three separate things are checked, in this order:
 *
 *   1. Whether the project can take a task at all. A project whose phases are
 *      not finalized cannot: there is nothing to file the work under, and the
 *      answer is to go and set the phases up rather than to create a task
 *      without one.
 *   2. Whether the phase submitted is one of that project's own. Which stops
 *      a task being filed under another project's Phase 2 by anybody editing
 *      the form.
 *   3. Whether that phase is still open. A stage somebody has signed off takes
 *      no new work - see below.
 */
class TaskPhaseRules
{
    /**
     * Why this project cannot take a new task yet, or null when it can.
     *
     * Worded as an instruction rather than a refusal - the reader is nearly
     * always somebody who can go and fix it in two clicks.
     */
    public function blockReason(Project $project): ?string
    {
        if ($project->needsPhaseSetup()) {
            return 'This project has not been configured with its project phases yet. Set up the project phases before adding tasks.';
        }

        if ($project->phases()->count() === 0) {
            // Belt and braces: finalize() will not write this state, and a
            // task created against it would have nowhere to go.
            return 'This project has no phases to file a task under. Ask a Super Admin to review its phase structure.';
        }

        if ($project->phases()->whereNull('completed_at')->count() === 0) {
            // Every stage of the job has been signed off, so there is no open
            // phase left to file work under. The project itself is what should
            // be closed out now, not extended with another task.
            return 'Every phase of this project has been completed, so there is no open phase to add a task to.';
        }

        return null;
    }

    public function accepts(Project $project): bool
    {
        return $this->blockReason($project) === null;
    }

    /**
     * The validation rules for the phase_id field on a task form.
     *
     * `$keepPhaseId` is for editing, and only for editing: a task already
     * sitting on a phase that has since been completed may keep it, so
     * correcting that task's title or dates does not have to move the work to
     * a different stage first. Creating is never given the allowance - see
     * ProjectPhaseProgress::selectablePhases(), which builds the select from
     * the same rule.
     *
     * @return array<int, mixed>
     */
    public function rules(Project $project, ?int $keepPhaseId = null): array
    {
        return ['required', 'integer', $this->belongsToProject($project, $keepPhaseId)];
    }

    /**
     * The messages that go with them. Both say what to do rather than what is
     * wrong with the input, because a person who sees these has picked from a
     * select that should not have offered the option.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phase_id.required' => 'Choose the phase this task belongs to.',
            'phase_id.integer' => 'Choose the phase this task belongs to.',
            // One message for both halves of the rule. A phase that is not
            // this project's and a phase that has already been signed off are
            // the same thing to the person at the form: not one of the options
            // they were offered.
            'phase_id.exists' => 'Pick a phase of this project that has not been completed yet.',
        ];
    }

    private function belongsToProject(Project $project, ?int $keepPhaseId): Exists
    {
        return Rule::exists('tbl_project_phases', 'phase_id')
            ->where('project_id', $project->project_id)
            ->where(function (Builder $query) use ($keepPhaseId) {
                $query->whereNull('completed_at');

                if ($keepPhaseId !== null) {
                    $query->orWhere('phase_id', $keepPhaseId);
                }
            });
    }
}
