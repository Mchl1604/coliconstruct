<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\StockMovement;
use App\Services\MaterialCatalog;
use App\Services\ProjectMaterialLedger;
use App\Services\ProjectMaterialRules;
use App\Support\ServerTable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Inventory side of the office portal: three pages, Materials, Inventory
 * and Movements.
 *
 * Reached by Super Admin, Admin Officer and Purchasing Admin - the
 * `area:inventory` middleware on the route group is the whole of that rule,
 * see RoleAccess. Each page is a shell: its table loads a page at a time from
 * StockController or MaterialController.
 */
class InventoryController extends Controller
{
    public function index(Request $request, MaterialCatalog $catalog): View
    {
        $stockFilter = (string) $request->query('stock', '');

        return view('super-admin.inventory', [
            'summary' => StockController::summary(),
            'stockStatuses' => Material::STOCK_STATUSES,
            // With their sub-types, for the table's two filters.
            'types' => $catalog->types(),
            // Opened from a Low Stock notice: ?stock=low_stock lands on the
            // table already narrowed to it.
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

    /**
     * Every movement in and out. History on a stock row opens it with
     * ?material= narrowing the list to that material.
     */
    public function movements(Request $request): View
    {
        return view('super-admin.movements', [
            'movementTypes' => StockMovement::TYPES,
            'filteredMaterial' => Material::query()->find($request->integer('material')),
        ]);
    }

    /**
     * Every live project, for those whose way into one is its materials.
     */
    public function projects(): View
    {
        return view('super-admin.inventory-projects');
    }

    /**
     * One page of the projects table, for serverTable.js.
     */
    public function projectsTable(Request $request): JsonResponse
    {
        $table = ServerTable::fromRequest($request);

        $page = $table->paginate(
            Project::query()
                ->with('client')
                ->where('is_archived', false)
                ->withCount(['materialRequests as pending_requests_count' => fn (Builder $query) => $query
                    ->where('status', MaterialRequest::STATUS_PENDING)]),
            [
                0 => 'reference_no',
                1 => 'name',
                3 => 'status',
            ],
            fn (Builder $builder, string $word, string $like) => $builder
                ->where('reference_no', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhereHas('client', fn (Builder $client) => $client
                    ->where('company_name', 'like', $like)
                    ->orWhere('fullname', 'like', $like)),
            [[0, 'desc']],
        );

        return $table->respond(
            $page,
            fn (Project $project): string => view('super-admin.partials.inventory-project-row', ['project' => $project])->render(),
        );
    }

    /**
     * A project as the inventory side reads it: what it is, and its materials.
     * Nothing here changes the project itself.
     */
    public function showProject(Request $request, Project $project, ProjectMaterialLedger $ledger, ProjectMaterialRules $rules): View
    {
        $abilities = $rules->abilities($request->user(), $project);

        return view('super-admin.inventory-project', [
            'project' => $project->load('client'),
            'materialSummary' => $ledger->summary($project),
            'materialAbilities' => $abilities,
            'materialQuickAdd' => $abilities['add']
                ? ProjectMaterialController::quickAddOptions()
                : ['types' => [], 'brands' => [], 'units' => []],
        ]);
    }

    /**
     * The list projects pick their materials from.
     */
    public function materials(): View
    {
        return view('super-admin.materials');
    }
}
