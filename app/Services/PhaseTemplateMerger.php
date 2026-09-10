<?php

namespace App\Services;

use App\Models\PhaseStage;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProjectType;
use App\Models\ProjectTypeStageTask;
use Illuminate\Support\Collection;

/**
 * Turning a project's types into the structure its setup screen starts with.
 *
 * This is the answer to the question the whole feature exists for: a project
 * can be Aircon Installation AND Electrical Works, those two types have
 * different stages and different work inside the stages they share, and
 * somebody has to be shown one coherent structure to edit rather than two
 * lists to reconcile by hand.
 *
 * The merge, in four steps:
 *
 *   1. STAGES are the union of the stages the project's types use. A stage one
 *      type uses and the other does not is still part of this project - the
 *      electrical rough-in has to happen whether or not the aircon fitter cares
 *      about it.
 *
 *   2. ORDER comes from the vocabulary's sort_order and nowhere else. This is
 *      the reason tbl_project_type_stages has no sequence column: two types
 *      each numbering their own stages from one produces two orderings that
 *      cannot be compared, and picking a winner would be arbitrary. One shared
 *      order means the merged structure is the same no matter which type was
 *      read first.
 *
 *   3. TASKS under a stage are every contributing type's tasks, concatenated in
 *      type-name order so each type's list keeps its own internal order and the
 *      result is stable. Titles that repeat across types collapse into one row
 *      carrying both type names - two types both saying "Clear and protect work
 *      area" mean it once.
 *
 *   4. DESCRIPTIONS come from the stage, so a stage two types both contribute
 *      to has exactly one description and there is nothing to reconcile.
 *
 * Everything here is a suggestion. The rows come back with no phase_id, nothing
 * is written, and the setup screen renders every one of them as an editable row
 * somebody can rename, reorder, delete or ignore. A project is never linked to
 * a template, so editing a template tomorrow cannot reach a project set up
 * today.
 */
class PhaseTemplateMerger
{
    /**
     * The structure to offer for a project, and what a reader should be told
     * about where it came from.
     *
     * @return array{
     *     phases: array<int, array<string, mixed>>,
     *     from_templates: bool,
     *     types_without_template: array<int, string>,
     *     dropped_stages: array<int, string>
     * }
     */
    public function for(Project $project): array
    {
        $types = $project->projectTypes()
            ->with(['stages', 'stageTasks'])
            ->orderBy('type_name')
            ->get();

        $contributing = $types->filter(fn (ProjectType $type): bool => $type->stages->isNotEmpty());

        // Nobody has written a template for any of this project's types, so
        // there is nothing to merge. The built-in suggestion is what this
        // screen offered before templates existed, and it is what it goes on
        // offering until a Super Admin writes one.
        if ($contributing->isEmpty()) {
            return [
                'phases' => $this->fallback(),
                'from_templates' => false,
                'types_without_template' => $types->pluck('type_name')->all(),
                'dropped_stages' => [],
            ];
        }

        $stages = $this->unionOfStages($contributing);

        // Practically unreachable - it would take a vocabulary of more than
        // twenty stages and a project using all of them - but a structure is
        // capped at MAX_PHASES and prefilling past it would produce a screen
        // that cannot be submitted. Better to fill what fits and say what did
        // not.
        $dropped = $stages->slice(ProjectPhase::MAX_PHASES);
        $stages = $stages->take(ProjectPhase::MAX_PHASES);

        return [
            'phases' => $stages
                ->map(fn (PhaseStage $stage): array => $this->phaseFor($stage, $contributing))
                ->values()
                ->all(),
            'from_templates' => true,
            'types_without_template' => $types
                ->reject(fn (ProjectType $type): bool => $type->stages->isNotEmpty())
                ->pluck('type_name')
                ->values()
                ->all(),
            'dropped_stages' => $dropped->pluck('name')->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Every stage any of these types uses, once each, in the vocabulary's
     * order.
     *
     * @param  Collection<int, ProjectType>  $types
     * @return Collection<int, PhaseStage>
     */
    private function unionOfStages(Collection $types): Collection
    {
        return $types
            ->flatMap(fn (ProjectType $type): Collection => $type->stages)
            ->unique('stage_id')
            // Attribute names rather than closures: a closure inside a
            // multi-key sortBy is treated as a comparator taking two items, not
            // as a value to sort on, and one written the other way silently
            // sorts by nothing useful.
            ->sortBy([
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    /**
     * One suggested phase: the stage's own heading, and every contributing
     * type's work for it.
     *
     * @param  Collection<int, ProjectType>  $types
     * @return array<string, mixed>
     */
    private function phaseFor(PhaseStage $stage, Collection $types): array
    {
        $contributors = $types->filter(
            fn (ProjectType $type): bool => $type->stages->contains('stage_id', $stage->stage_id)
        );

        return [
            // No phase_id: nothing here exists yet, and the setup screen must
            // treat every row as new until somebody saves it.
            'phase_id' => null,
            'stage_id' => $stage->stage_id,
            'title' => $stage->name,
            'description' => $stage->default_description,
            'sources' => $contributors->pluck('type_name')->values()->all(),
            'tasks' => $this->tasksFor($stage, $contributors),
            'task_count' => 0,
        ];
    }

    /**
     * The merged task list under one stage.
     *
     * Deduped on a case-insensitive, whitespace-collapsed title, which is the
     * only place in this design string matching is used - and it is used
     * between two lists a person will read side by side on the next screen,
     * where a merge they disagree with is one click to undo. Matching STAGES
     * that way would have been a different matter, which is why stages have
     * ids.
     *
     * @param  Collection<int, ProjectType>  $contributors
     * @return array<int, array<string, mixed>>
     */
    private function tasksFor(PhaseStage $stage, Collection $contributors): array
    {
        /** @var array<string, array<string, mixed>> $merged */
        $merged = [];

        foreach ($contributors as $type) {
            $tasks = $type->stageTasks
                ->where('stage_id', $stage->stage_id)
                ->sortBy([
                    ['sequence', 'asc'],
                    ['id', 'asc'],
                ]);

            foreach ($tasks as $task) {
                $key = $this->titleKey($task->title);

                if (isset($merged[$key])) {
                    // The same job named by two types. It keeps the position
                    // and wording of the first type to claim it, and gains the
                    // second type's name so the screen can show who wants it.
                    $merged[$key]['sources'][] = $type->type_name;

                    // Worth flagging rather than silently discarding: the two
                    // types agree on what the task is called and disagree on
                    // what it involves, and only a person can settle that.
                    if (trim($task->description) !== trim($merged[$key]['description'])) {
                        $merged[$key]['description_conflict'] = true;
                    }

                    continue;
                }

                $merged[$key] = [
                    'title' => $task->title,
                    'description' => $task->description,
                    'sources' => [$type->type_name],
                    'description_conflict' => false,
                    // A template says what the work is. Who does it and when
                    // are facts about a project, asked for on the setup screen.
                    'technician_id' => null,
                    'start_date' => null,
                    'due_date' => null,
                ];
            }
        }

        return array_values($merged);
    }

    /**
     * The built-in structure, for a project no template covers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fallback(): array
    {
        return collect(ProjectPhase::SUGGESTED_PHASES)
            ->map(fn (array $phase): array => [
                'phase_id' => null,
                // Deliberately unstamped. These four happen to share their
                // names with the seeded vocabulary, and matching them up by
                // name is exactly the guesswork stage ids exist to avoid.
                'stage_id' => null,
                'title' => $phase['title'],
                'description' => $phase['description'],
                'sources' => [],
                'tasks' => [],
                'task_count' => 0,
            ])
            ->all();
    }

    private function titleKey(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? $title));
    }
}
