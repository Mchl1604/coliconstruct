<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * How much of each material is in the warehouse, how much live projects hold,
 * and what is left for anything new.
 *
 *     Available            = On Hand: every counted movement summed
 *     Assigned to Projects = for each live project, what remains with it -
 *                            planned + added - used - returned - unused -
 *                            whether it is on site yet or not
 *     Total                = Available + Assigned to Projects
 *
 * Assigning stock to a project is a Stock Out: the plan writes one when the
 * project is saved - see StockLedger::syncAssignments() - and extra material
 * added on the Materials tab writes another. Using it moves nothing more; a
 * return, and leftovers at completion, put it back on the shelf.
 */
class StockAvailability
{
    /**
     * @param  array<int, int>  $materialIds
     * @return array<int, array{on_hand: float, assigned: float, available: float}>
     */
    public function forMaterials(array $materialIds): array
    {
        $materialIds = array_values(array_unique(array_map('intval', $materialIds)));

        if ($materialIds === []) {
            return [];
        }

        $onHand = StockMovement::query()
            ->counted()
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->select('material_id', DB::raw('SUM(quantity) as total'))
            ->pluck('total', 'material_id');

        $assigned = $this->assigned($materialIds);

        $figures = [];

        foreach ($materialIds as $materialId) {
            $held = max(0, $this->hundredths($onHand[$materialId] ?? 0));

            $figures[$materialId] = [
                'on_hand' => round($held / 100, 2),
                'assigned' => round(($assigned[$materialId] ?? 0) / 100, 2),
                'available' => round($held / 100, 2),
            ];
        }

        return $figures;
    }

    /**
     * One material's figures.
     *
     * @return array{on_hand: float, assigned: float, available: float}
     */
    public function forMaterial(Material $material): array
    {
        return $this->forMaterials([(int) $material->material_id])[(int) $material->material_id];
    }

    /**
     * How much of one material the warehouse has handed to one project and
     * not had back, counting every movement - what close-out may return.
     */
    public function heldBy(Project $project, Material $material): float
    {
        $net = StockMovement::query()
            ->counted()
            ->where('project_id', $project->project_id)
            ->where('material_id', $material->material_id)
            ->whereDoesntHave('projectEntry', fn ($entry) => $entry->where('entry_type', ProjectMaterialEntry::TYPE_USED))
            ->sum('quantity');

        return round(max(0, -$this->hundredths($net)) / 100, 2);
    }

    /**
     * material_id => hundredths still with live projects.
     *
     * @param  array<int, int>  $materialIds
     * @return array<int, int>
     */
    private function assigned(array $materialIds): array
    {
        $lines = ProjectMaterial::query()
            ->whereIn('material_id', $materialIds)
            ->whereHas('project', fn ($project) => $project
                ->where('is_archived', false)
                ->whereNotIn('status', Project::READ_ONLY_STATUSES))
            ->get(['project_material_id', 'material_id', 'quantity_needed']);

        if ($lines->isEmpty()) {
            return [];
        }

        // project_material_id => entry type => hundredths.
        $entries = ProjectMaterialEntry::query()
            ->notVoided()
            ->whereIn('project_material_id', $lines->modelKeys())
            ->groupBy('project_material_id', 'entry_type')
            ->select('project_material_id', 'entry_type', DB::raw('SUM(quantity) as total'))
            ->get()
            ->groupBy('project_material_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (ProjectMaterialEntry $row): array => [
                $row->entry_type => $this->hundredths($row->getAttributes()['total'] ?? 0),
            ]));

        $assigned = [];

        foreach ($lines as $line) {
            $figures = $entries->get($line->project_material_id, collect());
            $remaining = $this->hundredths($line->quantity_needed)
                + ($figures[ProjectMaterialEntry::TYPE_ADDED] ?? 0)
                - ($figures[ProjectMaterialEntry::TYPE_USED] ?? 0)
                - ($figures[ProjectMaterialEntry::TYPE_RETURNED] ?? 0)
                - ($figures[ProjectMaterialEntry::TYPE_UNUSED] ?? 0);

            if ($remaining > 0) {
                $assigned[(int) $line->material_id] = ($assigned[(int) $line->material_id] ?? 0) + $remaining;
            }
        }

        return $assigned;
    }

    private function hundredths(mixed $quantity): int
    {
        return (int) round((float) $quantity * 100);
    }
}
