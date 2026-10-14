<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A project's materials after setup: what was added, used and returned, and
 * what is still on site.
 *
 *     On Site = Needed + Added - Used - Returned - Unused
 *
 * Nothing here is stored as a running total. Every figure is summed from the
 * entries that are not voided, every time, so a total can always be traced
 * to the entries that make it up and can never drift from them.
 *
 * Quantities are added up in hundredths - integers - because the columns hold
 * two decimals and floating-point sums of 0.1 m lengths do not come out even.
 */
class ProjectMaterialLedger
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * Every material on the project with its figures, in the order they were
     * planned.
     *
     * A line added after setup whose only entry was voided is left out: it
     * carries nothing but zeros and was a mistake somebody already undid.
     *
     * @return Collection<int, array{line: ProjectMaterial, material: Material, needed: float, added: float, used: float, returned: float, unused: float, on_site: float}>
     */
    public function summary(Project $project): Collection
    {
        $totals = $this->totals($project);

        return $project->materials()
            ->with('material.type')
            ->orderBy('project_material_id')
            ->get()
            ->map(function (ProjectMaterial $line) use ($totals): array {
                $figures = $totals[$line->project_material_id] ?? [];
                $needed = $this->hundredths($line->quantity_needed);
                $added = $figures[ProjectMaterialEntry::TYPE_ADDED] ?? 0;
                $used = $figures[ProjectMaterialEntry::TYPE_USED] ?? 0;
                $returned = $figures[ProjectMaterialEntry::TYPE_RETURNED] ?? 0;
                $unused = $figures[ProjectMaterialEntry::TYPE_UNUSED] ?? 0;

                return [
                    'line' => $line,
                    'material' => $line->material,
                    'needed' => $needed / 100,
                    'added' => $added / 100,
                    'used' => $used / 100,
                    'returned' => $returned / 100,
                    'unused' => $unused / 100,
                    'on_site' => ($needed + $added - $used - $returned - $unused) / 100,
                ];
            })
            ->reject(fn (array $row): bool => $row['needed'] == 0
                && $row['added'] == 0
                && $row['used'] == 0
                && $row['returned'] == 0
                && $row['unused'] == 0)
            ->values();
    }

    /**
     * How much of one line is on site right now.
     */
    public function onSite(ProjectMaterial $line): float
    {
        return $this->onSiteHundredths($line) / 100;
    }

    /**
     * More of a material brought in - or one the plan did not have at all,
     * which gets a line of its own with nothing planned against it.
     *
     * @throws RuntimeException
     */
    public function add(Project $project, Material $material, float|int|string $quantity, CarbonInterface|string $date, ?string $notes, User $by): ProjectMaterialEntry
    {
        $this->assertQuantity($material, $quantity);

        return DB::transaction(function () use ($project, $material, $quantity, $date, $notes, $by): ProjectMaterialEntry {
            $line = ProjectMaterial::query()
                ->where('project_id', $project->project_id)
                ->where('material_id', $material->material_id)
                ->first();

            if ($line === null) {
                if (! $material->is_active) {
                    throw new RuntimeException(sprintf('%s is inactive.', $material->displayName()));
                }

                $line = ProjectMaterial::create([
                    'project_id' => $project->project_id,
                    'material_id' => $material->material_id,
                    'quantity_needed' => 0,
                    'created_by' => $by->id,
                ]);
            }

            $entry = $this->write($line, ProjectMaterialEntry::TYPE_ADDED, $quantity, $date, $notes, $by);

            $this->activityLogger->record(
                ActivityLog::PROJECT_MATERIAL_ADDED,
                null,
                sprintf(
                    'Added %s %s of %s to %s.',
                    $material->unit->format($quantity),
                    $material->unit->value,
                    $material->displayName(),
                    $project->reference_no
                ),
                $project
            );

            return $entry;
        });
    }

    /**
     * Record some of what is on site as used, or as returned.
     *
     * Refused past what is on site: using or returning more than was ever
     * there would leave the figures saying the site holds a negative amount.
     * The fix for "we needed more" is to add it first.
     *
     * @throws RuntimeException
     */
    public function record(Project $project, ProjectMaterial $line, string $type, float|int|string $quantity, CarbonInterface|string $date, ?string $notes, User $by): ProjectMaterialEntry
    {
        if (! in_array($type, ProjectMaterialEntry::DRAWING_TYPES, true)) {
            throw new RuntimeException('Choose Used or Returned.');
        }

        if ((int) $line->project_id !== (int) $project->project_id) {
            throw new RuntimeException('That material is not on this project.');
        }

        $material = $line->material;

        $this->assertQuantity($material, $quantity);

        return DB::transaction(function () use ($project, $line, $material, $type, $quantity, $date, $notes, $by): ProjectMaterialEntry {
            // Locked so two people recording the last few meters at once
            // cannot both be told they are there.
            $line = ProjectMaterial::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            $onSite = $this->onSiteHundredths($line);

            if ($this->hundredths($quantity) > $onSite) {
                throw new RuntimeException($onSite <= 0
                    ? sprintf('No %s on site. Add more first.', $material->displayName())
                    : sprintf('Only %s %s on site. Add more first.', $material->unit->format($onSite / 100), $material->unit->value));
            }

            $entry = $this->write($line, $type, $quantity, $date, $notes, $by);

            $this->activityLogger->record(
                $type === ProjectMaterialEntry::TYPE_USED
                    ? ActivityLog::PROJECT_MATERIAL_USED
                    : ActivityLog::PROJECT_MATERIAL_RETURNED,
                null,
                sprintf(
                    '%s %s %s of %s on %s.',
                    $type === ProjectMaterialEntry::TYPE_USED ? 'Used' : 'Returned',
                    $material->unit->format($quantity),
                    $material->unit->value,
                    $material->displayName(),
                    $project->reference_no
                ),
                $project
            );

            return $entry;
        });
    }

    /**
     * Strike an entry out. It stays on the history, marked voided, and stops
     * counting toward the figures.
     *
     * Voiding an addition that has since been used would take the site below
     * zero, so that is refused until the later entries are voided first.
     *
     * @throws RuntimeException
     */
    public function void(Project $project, ProjectMaterialEntry $entry, User $by): void
    {
        if ((int) $entry->project_id !== (int) $project->project_id) {
            throw new RuntimeException('That entry is not on this project.');
        }

        if ($entry->isVoided()) {
            throw new RuntimeException('Already voided.');
        }

        if ($entry->entry_type === ProjectMaterialEntry::TYPE_UNUSED) {
            throw new RuntimeException('Leftovers cannot be voided.');
        }

        DB::transaction(function () use ($project, $entry, $by): void {
            $line = ProjectMaterial::query()
                ->with('material')
                ->whereKey($entry->project_material_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($entry->entry_type === ProjectMaterialEntry::TYPE_ADDED
                && $this->onSiteHundredths($line) - $this->hundredths($entry->quantity) < 0) {
                throw new RuntimeException('Already used. Void later entries first.');
            }

            $entry->forceFill([
                'voided_at' => now(),
                'voided_by' => $by->id,
            ])->save();

            $this->activityLogger->record(
                ActivityLog::PROJECT_MATERIAL_ENTRY_VOIDED,
                null,
                sprintf(
                    'Voided %s %s %s of %s on %s.',
                    strtolower($entry->typeLabel()),
                    $line->material->unit->format($entry->quantity),
                    $line->material->unit->value,
                    $line->material->displayName(),
                    $project->reference_no
                ),
                $project
            );
        });
    }

    /**
     * Record whatever is still on site as unused, once the project has
     * closed for good.
     *
     * No warning and no refusal: a job often ends with a few meters of tube
     * left, and that is a fact to keep, not a problem to stop the close on.
     * Called when a project becomes Completed or Cancelled - never at
     * Awaiting Client Confirmation, which can still be reopened. Running it
     * twice writes nothing the second time, because the first left nothing
     * on site.
     *
     * @return int How many materials had something left over.
     */
    public function closeOut(Project $project, string $reason): int
    {
        $lines = $project->materials()->with('material')->get();

        if ($lines->isEmpty()) {
            return 0;
        }

        $today = BusinessTime::today()->toDateString();
        $written = 0;

        foreach ($lines as $line) {
            $onSite = $this->onSiteHundredths($line);

            if ($onSite <= 0) {
                continue;
            }

            ProjectMaterialEntry::create([
                'project_id' => $project->project_id,
                'project_material_id' => $line->project_material_id,
                'entry_type' => ProjectMaterialEntry::TYPE_UNUSED,
                'quantity' => $onSite / 100,
                'entry_date' => $today,
                'notes' => $reason,
                'recorded_by' => null,
                'recorded_by_name' => 'System',
            ]);

            $written++;
        }

        if ($written > 0) {
            $this->activityLogger->record(
                ActivityLog::PROJECT_MATERIALS_CLOSED_OUT,
                null,
                sprintf(
                    'Recorded %d leftover %s on %s as unused.',
                    $written,
                    $written === 1 ? 'material' : 'materials',
                    $project->reference_no
                ),
                $project
            );
        }

        return $written;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * project_material_id => entry type => total, in hundredths.
     *
     * @return array<int, array<string, int>>
     */
    private function totals(Project $project): array
    {
        $totals = [];

        ProjectMaterialEntry::query()
            ->notVoided()
            ->where('project_id', $project->project_id)
            ->select('project_material_id', 'entry_type', DB::raw('SUM(quantity) as total'))
            ->groupBy('project_material_id', 'entry_type')
            ->get()
            ->each(function (ProjectMaterialEntry $row) use (&$totals): void {
                $totals[(int) $row->project_material_id][$row->entry_type] = $this->hundredths($row->getAttributes()['total'] ?? 0);
            });

        return $totals;
    }

    private function onSiteHundredths(ProjectMaterial $line): int
    {
        $figures = ProjectMaterialEntry::query()
            ->notVoided()
            ->where('project_material_id', $line->project_material_id)
            ->select('entry_type', DB::raw('SUM(quantity) as total'))
            ->groupBy('entry_type')
            ->pluck('total', 'entry_type')
            ->map(fn ($total): int => $this->hundredths($total));

        return $this->hundredths($line->quantity_needed)
            + ($figures[ProjectMaterialEntry::TYPE_ADDED] ?? 0)
            - ($figures[ProjectMaterialEntry::TYPE_USED] ?? 0)
            - ($figures[ProjectMaterialEntry::TYPE_RETURNED] ?? 0)
            - ($figures[ProjectMaterialEntry::TYPE_UNUSED] ?? 0);
    }

    private function write(ProjectMaterial $line, string $type, float|int|string $quantity, CarbonInterface|string $date, ?string $notes, User $by): ProjectMaterialEntry
    {
        $notes = trim((string) $notes);

        return ProjectMaterialEntry::create([
            'project_id' => $line->project_id,
            'project_material_id' => $line->project_material_id,
            'entry_type' => $type,
            'quantity' => round((float) $quantity, 2),
            'entry_date' => $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'notes' => $notes === '' ? null : mb_substr($notes, 0, 255),
            'recorded_by' => $by->id,
            'recorded_by_name' => $by->fullName(),
        ]);
    }

    /**
     * @throws RuntimeException
     */
    private function assertQuantity(Material $material, float|int|string $quantity): void
    {
        $problem = $material->unit->quantityProblem($quantity);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }

    private function hundredths(mixed $quantity): int
    {
        return (int) round((float) $quantity * 100);
    }
}
