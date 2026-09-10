<?php

namespace App\Services;

use App\Models\PhaseStage;
use App\Models\ProjectType;
use App\Models\ProjectTypeStageTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writing the phase templates - the vocabulary of stages, and what each project
 * type does during the stages it uses.
 *
 * The only place any of the three template tables is written, for the same
 * reason ProjectTypeCatalog is the only place a type is: the three of them have
 * to stay consistent with each other, and a rule enforced in one of four call
 * sites is not a rule.
 *
 * Nothing here can reach a project. A template is copied onto a project once,
 * at phase setup, by PhaseTemplateMerger - so every write below changes what
 * the NEXT project is offered and no project that already exists.
 */
class PhaseTemplateCatalog
{
    /**
     * The vocabulary, with what depends on each stage.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function vocabulary(): Collection
    {
        $taskCounts = ProjectTypeStageTask::query()
            ->selectRaw('stage_id, COUNT(*) as total')
            ->groupBy('stage_id')
            ->pluck('total', 'stage_id');

        return PhaseStage::query()
            ->withCount('projectTypes')
            ->inOrder()
            ->get()
            ->map(fn (PhaseStage $stage): array => [
                'stage_id' => $stage->stage_id,
                'name' => $stage->name,
                'default_description' => $stage->default_description,
                'sort_order' => $stage->sort_order,
                'type_count' => $stage->project_types_count,
                'task_count' => (int) ($taskCounts[$stage->stage_id] ?? 0),
            ])
            ->values();
    }

    /**
     * Every project type, and how much of a template it has.
     *
     * What the System Settings list is drawn from, so a Super Admin can see at
     * a glance which types still have nothing behind them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function typeSummaries(): Collection
    {
        $taskCounts = ProjectTypeStageTask::query()
            ->selectRaw('type_id, COUNT(*) as total')
            ->groupBy('type_id')
            ->pluck('total', 'type_id');

        return ProjectType::query()
            ->withCount('stages')
            ->orderBy('type_name')
            ->get()
            ->map(fn (ProjectType $type): array => [
                'type_id' => $type->type_id,
                'type_name' => $type->type_name,
                'stage_count' => $type->stages_count,
                'task_count' => (int) ($taskCounts[$type->type_id] ?? 0),
            ])
            ->values();
    }

    /**
     * One type's template: every stage in the vocabulary, whether this type
     * uses it, and the tasks it contributes.
     *
     * The whole vocabulary rather than only the stages in use, because the
     * editor is a list of tick boxes - a stage this type does not use yet is
     * exactly what the person opening this screen is there to add.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templateFor(ProjectType $type): array
    {
        $type->loadMissing(['stages', 'stageTasks']);

        $used = $type->stages->pluck('stage_id')->all();

        return PhaseStage::query()
            ->inOrder()
            ->get()
            ->map(fn (PhaseStage $stage): array => [
                'stage_id' => $stage->stage_id,
                'name' => $stage->name,
                'default_description' => $stage->default_description,
                'selected' => in_array($stage->stage_id, $used, true),
                'tasks' => $type->stageTasks
                    ->where('stage_id', $stage->stage_id)
                    // Attribute names, not closures - see the same sort in
                    // PhaseTemplateMerger for why.
                    ->sortBy([
                        ['sequence', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->map(fn (ProjectTypeStageTask $task): array => [
                        'title' => $task->title,
                        'description' => $task->description,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // The vocabulary
    // ------------------------------------------------------------------

    /**
     * @throws RuntimeException
     */
    public function addStage(string $name, string $description): PhaseStage
    {
        $name = $this->clean($name);

        $this->assertStageNameIsFree($name);

        return PhaseStage::create([
            'name' => $name,
            'default_description' => trim($description),
            'sort_order' => PhaseStage::nextSortOrder(),
        ]);
    }

    /**
     * @throws RuntimeException
     */
    public function renameStage(PhaseStage $stage, string $name, string $description): PhaseStage
    {
        $name = $this->clean($name);

        $this->assertStageNameIsFree($name, $stage);

        $stage->update([
            'name' => $name,
            'default_description' => trim($description),
        ]);

        return $stage->refresh();
    }

    /**
     * Remove a stage from the vocabulary.
     *
     * Refused while a type still uses it. The cascade would work - a template
     * is not a record of anything - but it would take that type's default tasks
     * for the stage with it silently, and somebody wrote those. Untick it there
     * first, where the tasks about to be lost are on the screen.
     *
     * Phases already stamped with this stage are never in question: they keep
     * their own title and description and simply lose the provenance stamp.
     *
     * @throws RuntimeException
     */
    public function removeStage(PhaseStage $stage): void
    {
        $types = $stage->projectTypes()->orderBy('type_name')->pluck('type_name');

        if ($types->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                "'%s' is used by %s, so it cannot be removed. Take it off %s first.",
                $stage->name,
                $types->implode(', '),
                $types->count() === 1 ? 'that project type' : 'those project types'
            ));
        }

        $stage->delete();
    }

    /**
     * Rewrite the vocabulary's order from a submitted list of stage ids.
     *
     * The whole list every time rather than a move-one-up endpoint: the order
     * is a property of the list, and sending the list is the only version of
     * this that cannot half-apply.
     *
     * @param  array<int, int>  $stageIds
     *
     * @throws RuntimeException
     */
    public function reorderStages(array $stageIds): void
    {
        $known = PhaseStage::query()->pluck('stage_id')->all();

        $submitted = collect($stageIds)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($submitted->count() !== count($known) || $submitted->diff($known)->isNotEmpty()) {
            throw new RuntimeException('The stage list is out of date. Reload the page and try again.');
        }

        DB::transaction(function () use ($submitted): void {
            $submitted->each(function (int $stageId, int $index): void {
                PhaseStage::query()
                    ->where('stage_id', $stageId)
                    ->update(['sort_order' => ($index + 1) * PhaseStage::SORT_STEP]);
            });
        });
    }

    // ------------------------------------------------------------------
    // A type's template
    // ------------------------------------------------------------------

    /**
     * Replace one project type's whole template in a single write.
     *
     * The submitted shape is what the editor holds: the stages ticked, each
     * with its list of default tasks. Sending all of it means a save cannot
     * half-apply - a stage unticked and a task added in the same sitting either
     * both happen or neither does.
     *
     * @param  array<int, array{stage_id: int, tasks: array<int, array{title: string, description: string}>}>  $stages
     *
     * @throws RuntimeException
     */
    public function saveTemplate(ProjectType $type, array $stages): void
    {
        $known = PhaseStage::query()->pluck('stage_id')->all();

        $rows = [];

        foreach ($stages as $stage) {
            $stageId = (int) ($stage['stage_id'] ?? 0);

            if (! in_array($stageId, $known, true)) {
                throw new RuntimeException('That stage is no longer in the vocabulary. Reload the page and try again.');
            }

            $tasks = array_values($stage['tasks'] ?? []);

            if (count($tasks) > ProjectTypeStageTask::MAX_PER_STAGE) {
                throw new RuntimeException(sprintf(
                    'A project type can have at most %d default tasks in one stage.',
                    ProjectTypeStageTask::MAX_PER_STAGE
                ));
            }

            $rows[$stageId] = $tasks;
        }

        DB::transaction(function () use ($type, $rows): void {
            // Rewritten wholesale rather than diffed. These rows carry no
            // history and nothing points at them, so there is no identity worth
            // preserving across a save - and a diff would be a second way for
            // the stored template to disagree with the submitted one.
            $type->stageTasks()->delete();
            $type->stages()->sync(array_keys($rows));

            foreach ($rows as $stageId => $tasks) {
                foreach ($tasks as $index => $task) {
                    ProjectTypeStageTask::create([
                        'type_id' => $type->type_id,
                        'stage_id' => $stageId,
                        'sequence' => $index + 1,
                        'title' => trim((string) $task['title']),
                        'description' => trim((string) $task['description']),
                    ]);
                }
            }
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @throws RuntimeException
     */
    private function assertStageNameIsFree(string $name, ?PhaseStage $ignoring = null): void
    {
        $exists = PhaseStage::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoring, fn ($query) => $query->where('stage_id', '!=', $ignoring->stage_id))
            ->exists();

        if ($exists) {
            throw new RuntimeException(sprintf("'%s' is already a phase stage.", $name));
        }
    }

    private function clean(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }
}
