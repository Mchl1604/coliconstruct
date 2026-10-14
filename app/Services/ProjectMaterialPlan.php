<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The materials step of a project's setup: what the job needs, and how much.
 *
 * Written with the phases, by the same Save and Finalize buttons, and only
 * until the project's first setup is finalized. After that the plan is the
 * baseline - see ProjectMaterialRules::canPlan() - and everything that changes
 * is an entry kept by ProjectMaterialLedger.
 */
class ProjectMaterialPlan
{
    /**
     * More lines than any job lists, and a ceiling on a tampered form.
     */
    public const MAX_LINES = 200;

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * The plan as the setup screen lists it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Project $project): Collection
    {
        return $project->materials()
            ->with('material.type')
            ->orderBy('project_material_id')
            ->get()
            ->map(fn (ProjectMaterial $line): array => $this->row($line->material, $line->quantity_needed, $line->notes))
            ->values();
    }

    /**
     * Submitted rows as the setup screen redraws them after a refusal, so the
     * person gets their own list back rather than the saved one.
     *
     * @param  array<int, array<string, mixed>>  $submitted
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsFromInput(array $submitted): Collection
    {
        $materials = Material::query()
            ->with('type')
            ->whereIn('material_id', collect($submitted)->pluck('material_id')->filter()->all())
            ->get()
            ->keyBy('material_id');

        return collect($submitted)
            ->map(function (mixed $row) use ($materials): ?array {
                $material = is_array($row) ? $materials->get((int) ($row['material_id'] ?? 0)) : null;

                return $material
                    ? $this->row($material, $row['quantity'] ?? '', $row['notes'] ?? null)
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * What is wrong with each submitted line, keyed the way the validator
     * reports it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function errors(Project $project, array $rows): array
    {
        $errors = [];
        $planned = $project->materials()->pluck('material_id')->map(fn ($id): int => (int) $id)->all();
        $materials = Material::query()
            ->whereIn('material_id', collect($rows)->pluck('material_id')->filter()->all())
            ->get()
            ->keyBy('material_id');
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            $key = 'materials.'.$index.'.quantity';
            $material = $materials->get((int) ($row['material_id'] ?? 0));

            if ($material === null) {
                $errors['materials.'.$index.'.material_id'] = 'That material is no longer listed.';

                continue;
            }

            // A material deactivated while it sat on a saved draft may stay;
            // it may not be newly picked.
            if (! $material->is_active && ! in_array((int) $material->material_id, $planned, true)) {
                $errors['materials.'.$index.'.material_id'] = sprintf('%s is inactive.', $material->displayName());

                continue;
            }

            if (isset($seen[$material->material_id])) {
                $errors['materials.'.$index.'.material_id'] = sprintf('%s is listed twice.', $material->displayName());

                continue;
            }

            $seen[$material->material_id] = true;

            $problem = $material->unit->quantityProblem($row['quantity'] ?? null);

            if ($problem !== null) {
                $errors[$key] = sprintf('%s: %s', $material->displayName(), $problem);
            }
        }

        return $errors;
    }

    /**
     * Replace the plan with what was submitted.
     *
     * Rewritten wholesale, like the draft tasks: before finalization nothing
     * has been recorded against a line, so there is no history to keep.
     *
     * @param  array<int, array{material_id: int, quantity: float|int|string, notes?: string|null}>  $rows
     *
     * @throws RuntimeException when the plan is already settled or a line is invalid.
     */
    public function save(Project $project, array $rows, ?User $by = null): void
    {
        if ($project->hasCompletedInitialSetup()) {
            throw new RuntimeException('Materials are set. Use the Materials tab.');
        }

        $rows = array_values($rows);

        if (count($rows) > self::MAX_LINES) {
            throw new RuntimeException(sprintf('At most %d materials.', self::MAX_LINES));
        }

        $errors = $this->errors($project, $rows);

        if ($errors !== []) {
            throw new RuntimeException((string) reset($errors));
        }

        $keep = collect($rows)->pluck('material_id')->map(fn ($id): int => (int) $id)->all();

        $project->materials()->whereNotIn('material_id', $keep)->delete();

        foreach ($rows as $row) {
            $notes = trim((string) ($row['notes'] ?? ''));

            ProjectMaterial::query()->updateOrCreate(
                ['project_id' => $project->project_id, 'material_id' => (int) $row['material_id']],
                [
                    'quantity_needed' => round((float) $row['quantity'], 2),
                    'notes' => $notes === '' ? null : mb_substr($notes, 0, 255),
                    'created_by' => $by?->id,
                ]
            );
        }
    }

    /**
     * Record the plan the project was set up with. Called once, when setup is
     * finalized for the first time.
     */
    public function recordFinalized(Project $project): void
    {
        $count = $project->materials()->count();

        $this->activityLogger->record(
            ActivityLog::PROJECT_MATERIALS_PLANNED,
            null,
            $count === 0
                ? sprintf('Set up %s with no materials listed.', $project->reference_no)
                : sprintf(
                    'Planned %d %s for %s.',
                    $count,
                    $count === 1 ? 'material' : 'materials',
                    $project->reference_no
                ),
            $project
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Material $material, mixed $quantity, ?string $notes): array
    {
        return $material->toOption() + [
            // As saved without the trailing zeros - "2.5", not "2.50", and
            // "12", not "12.00" - or as typed when it was not a number.
            'quantity' => is_numeric($quantity)
                ? rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.')
                : (string) $quantity,
            'notes' => (string) ($notes ?? ''),
        ];
    }
}
