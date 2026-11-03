<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * How much of each material is on hand, how much of that live projects have
 * already claimed, and what is left for anything new.
 *
 *     Reserved  = for each live project, what it planned less what the
 *                 warehouse has released to it - never below zero
 *     Available = On Hand - Reserved - never below zero
 *
 * A project's plan reserves stock from the moment it is saved, so a second
 * project cannot be promised the same ten units. The reservation shrinks as
 * Purchasing releases the materials with a Stock Out against the project,
 * and ends when the project closes or is archived.
 *
 * A plan may reserve more than is on hand - the wizard warns and still saves.
 * Reserved keeps the whole claim, and Available simply reads zero.
 *
 * Only movements made by hand count as a release. One the project's Materials
 * tab wrote - extra brought to site, a return, leftovers at close-out - is
 * about stock beyond the plan, not the plan itself.
 */
class StockAvailability
{
    /**
     * @param  array<int, int>  $materialIds
     * @return array<int, array{on_hand: float, reserved: float, available: float}>
     */
    public function forMaterials(array $materialIds, ?Project $except = null): array
    {
        $materialIds = array_values(array_unique(array_map('intval', $materialIds)));

        if ($materialIds === []) {
            return [];
        }

        $onHand = StockMovement::query()
            ->notVoided()
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->select('material_id', DB::raw('SUM(quantity) as total'))
            ->pluck('total', 'material_id');

        $reserved = $this->reserved($materialIds, $except);

        $figures = [];

        foreach ($materialIds as $materialId) {
            $held = $this->hundredths($onHand[$materialId] ?? 0);
            $claimed = $reserved[$materialId] ?? 0;

            $figures[$materialId] = [
                'on_hand' => round($held / 100, 2),
                'reserved' => round($claimed / 100, 2),
                'available' => round(max(0, $held - $claimed) / 100, 2),
            ];
        }

        return $figures;
    }

    /**
     * One material's figures.
     *
     * @return array{on_hand: float, reserved: float, available: float}
     */
    public function forMaterial(Material $material, ?Project $except = null): array
    {
        return $this->forMaterials([(int) $material->material_id], $except)[(int) $material->material_id];
    }

    /**
     * How much of one material the warehouse has handed to one project and
     * not had back, counting every movement - what close-out may return.
     */
    public function heldBy(Project $project, Material $material): float
    {
        $net = StockMovement::query()
            ->notVoided()
            ->where('project_id', $project->project_id)
            ->where('material_id', $material->material_id)
            ->sum('quantity');

        return round(max(0, -$this->hundredths($net)) / 100, 2);
    }

    /**
     * material_id => hundredths claimed by live projects' plans.
     *
     * @param  array<int, int>  $materialIds
     * @return array<int, int>
     */
    private function reserved(array $materialIds, ?Project $except): array
    {
        $plans = ProjectMaterial::query()
            ->whereIn('material_id', $materialIds)
            ->whereHas('project', fn ($project) => $project
                ->where('is_archived', false)
                ->whereNotIn('status', Project::READ_ONLY_STATUSES))
            ->when($except?->exists, fn ($query) => $query->where('project_id', '!=', $except->project_id))
            ->get(['project_id', 'material_id', 'quantity_needed']);

        if ($plans->isEmpty()) {
            return [];
        }

        // project_id|material_id => hundredths released by hand.
        $released = StockMovement::query()
            ->notVoided()
            ->whereNull('project_material_entry_id')
            ->whereIn('project_id', $plans->pluck('project_id')->unique()->all())
            ->whereIn('material_id', $materialIds)
            ->groupBy('project_id', 'material_id')
            ->select('project_id', 'material_id', DB::raw('SUM(quantity) as total'))
            ->get()
            ->mapWithKeys(fn (StockMovement $row): array => [
                $row->project_id.'|'.$row->material_id => -$this->hundredths($row->getAttributes()['total'] ?? 0),
            ]);

        $reserved = [];

        foreach ($plans as $plan) {
            $key = $plan->project_id.'|'.$plan->material_id;
            $outstanding = $this->hundredths($plan->quantity_needed) - max(0, $released[$key] ?? 0);

            if ($outstanding > 0) {
                $reserved[(int) $plan->material_id] = ($reserved[(int) $plan->material_id] ?? 0) + $outstanding;
            }
        }

        return $reserved;
    }

    private function hundredths(mixed $quantity): int
    {
        return (int) round((float) $quantity * 100);
    }
}
