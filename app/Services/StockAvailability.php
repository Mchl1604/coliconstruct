<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * How much of each material is on hand, how much of that live projects have
 * already claimed, and what is left for anything new.
 *
 *     Reserved  = for each live project, what it planned less what the
 *                 warehouse has released to it, what it gave back
 *                 unreleased, and what it used straight off the shelf -
 *                 never below zero
 *     Available = On Hand - Reserved - never below zero
 *
 * A project's plan reserves stock from the moment it is saved, so a second
 * project cannot be promised the same ten units. The reservation shrinks as
 * Purchasing releases the materials with a Stock Out against the project,
 * and ends when the project closes or is archived.
 *
 * The wizard holds a new plan to what is available - see
 * ProjectMaterialPlan::limitToAvailable(). A plan saved before that may still
 * reserve more than is on hand: Reserved keeps the whole claim, and Available
 * simply reads zero.
 *
 * A plan also writes Stock Out rows marked as reservations, so the history
 * shows the claim. They never count: Reserved is already the claim, and adding
 * them would take it off twice.
 *
 * Giving back part of a plan nobody released writes a Returned row marked the
 * same way. That stock never left the shelf, so On Hand stays as it is and only
 * the claim shrinks - see StockLedger::splitReturn().
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
            ->counted()
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
            ->counted()
            ->where('project_id', $project->project_id)
            ->where('material_id', $material->material_id)
            ->whereDoesntHave('projectEntry', fn ($entry) => $entry->where('entry_type', ProjectMaterialEntry::TYPE_USED))
            ->sum('quantity');

        return round(max(0, -$this->hundredths($net)) / 100, 2);
    }

    /**
     * How much of one project's plan for one material is still claimed and
     * has never left the shelf.
     */
    public function claimedBy(Project $project, Material $material): float
    {
        $planned = ProjectMaterial::query()
            ->where('project_id', $project->project_id)
            ->where('material_id', $material->material_id)
            ->value('quantity_needed');

        $released = StockMovement::query()
            ->counted()
            ->whereNull('project_material_entry_id')
            ->where('project_id', $project->project_id)
            ->where('material_id', $material->material_id)
            ->sum('quantity');

        $givenBack = StockMovement::query()
            ->notVoided()
            ->where('is_reservation', true)
            ->where('quantity', '>', 0)
            ->where('project_id', $project->project_id)
            ->where('material_id', $material->material_id)
            ->sum('quantity');

        $usedOffShelf = StockMovement::query()
            ->counted()
            ->usedOnSite()
            ->where('project_id', $project->project_id)
            ->where('material_id', $material->material_id)
            ->sum('quantity');

        $outstanding = $this->hundredths($planned)
            - max(0, -$this->hundredths($released))
            - $this->hundredths($givenBack)
            + $this->hundredths($usedOffShelf);

        return round(max(0, $outstanding) / 100, 2);
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
            ->counted()
            ->whereNull('project_material_entry_id')
            ->whereIn('project_id', $plans->pluck('project_id')->unique()->all())
            ->whereIn('material_id', $materialIds)
            ->groupBy('project_id', 'material_id')
            ->select('project_id', 'material_id', DB::raw('SUM(quantity) as total'))
            ->get()
            ->mapWithKeys(fn (StockMovement $row): array => [
                $row->project_id.'|'.$row->material_id => -$this->hundredths($row->getAttributes()['total'] ?? 0),
            ]);

        // project_id|material_id => hundredths given back unreleased.
        $givenBack = StockMovement::query()
            ->notVoided()
            ->where('is_reservation', true)
            ->where('quantity', '>', 0)
            ->whereIn('project_id', $plans->pluck('project_id')->unique()->all())
            ->whereIn('material_id', $materialIds)
            ->groupBy('project_id', 'material_id')
            ->select('project_id', 'material_id', DB::raw('SUM(quantity) as total'))
            ->get()
            ->mapWithKeys(fn (StockMovement $row): array => [
                $row->project_id.'|'.$row->material_id => $this->hundredths($row->getAttributes()['total'] ?? 0),
            ]);

        // project_id|material_id => hundredths used straight off the shelf.
        $usedOffShelf = StockMovement::query()
            ->counted()
            ->usedOnSite()
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
            $outstanding = $this->hundredths($plan->quantity_needed) - max(0, $released[$key] ?? 0) - ($givenBack[$key] ?? 0) - ($usedOffShelf[$key] ?? 0);

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
