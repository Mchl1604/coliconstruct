<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Project;
use App\Models\StockMovement;
use App\Services\MaterialCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The Inventory side of the office portal: Stock, Movements and Materials.
 *
 * Reached by Super Admin, Admin Officer and Purchasing Admin - the
 * `area:inventory` middleware on the route group is the whole of that rule,
 * see RoleAccess. The page itself is a shell: each tab's table loads a page
 * at a time from StockController or MaterialController.
 */
class InventoryController extends Controller
{
    public function index(Request $request, MaterialCatalog $catalog): View
    {
        $stockFilter = (string) $request->query('stock', '');

        return view('super-admin.inventory', [
            'summary' => StockController::summary(),
            'stockStatuses' => Material::STOCK_STATUSES,
            'movementTypes' => StockMovement::TYPES,
            // With their sub-types, for the Stock tab's two filters.
            'types' => $catalog->types(),
            // Opened from a Low Stock notice: ?stock=low_stock lands on the
            // Stock tab already narrowed to it.
            'stockFilter' => array_key_exists($stockFilter, Material::STOCK_STATUSES) ? $stockFilter : '',
            // What Stock Out and a return may name. Not archived; whether a
            // project is still open enough to take stock is asked again when
            // the movement is saved.
            'projects' => Project::query()
                ->where('is_archived', false)
                ->orderByDesc('project_id')
                ->get(['project_id', 'reference_no', 'name', 'status'])
                ->map(fn (Project $project): array => [
                    'project_id' => (int) $project->project_id,
                    'label' => trim($project->reference_no.' · '.$project->name),
                    'is_open' => ! $project->isReadOnly(),
                ])
                ->values(),
        ]);
    }
}
