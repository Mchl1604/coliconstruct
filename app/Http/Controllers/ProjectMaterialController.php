<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Services\ActivityLogger;
use App\Services\MaterialCatalog;
use App\Services\MaterialRequests;
use App\Services\ProjectMaterialLedger;
use App\Services\ProjectMaterialRules;
use App\Services\StockAvailability;
use App\Support\BusinessTime;
use App\Support\MaterialUnit;
use App\Support\RoleAccess;
use App\Support\ServerTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A project's Materials tab, and the create wizard's material search.
 *
 * One controller for every portal, as ProjectPhaseController is: the office,
 * the inventory side and a Lead Technician record through the same rules, and
 * only the page they are sent back to differs.
 *
 * Recording answers with a redirect, like every other save on Project
 * Details - projectWorkspace.js redraws the administrator's page in place and
 * the technician's page reloads. The picker's two endpoints answer JSON.
 */
class ProjectMaterialController extends Controller
{
    public function __construct(
        private readonly ProjectMaterialRules $rules,
        private readonly ProjectMaterialLedger $ledger,
        private readonly MaterialCatalog $catalog,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * One page of the material history, for serverTable.js.
     */
    public function table(Request $request, Project $project): JsonResponse
    {
        $this->authorizeView($request, $project);

        $table = ServerTable::fromRequest($request);
        $user = $request->user();

        $page = $table->paginate(
            ProjectMaterialEntry::query()
                ->with(['projectMaterial.material', 'recorder'])
                ->where('project_id', $project->project_id),
            [
                0 => 'entry_date',
                1 => fn (Builder $builder, string $dir) => $builder->orderBy(
                    Material::query()
                        ->select('name')
                        ->join('tbl_project_materials', 'tbl_project_materials.material_id', '=', 'tbl_materials.material_id')
                        ->whereColumn('tbl_project_materials.project_material_id', 'tbl_project_material_entries.project_material_id'),
                    $dir
                ),
                2 => 'entry_type',
                3 => 'quantity',
            ],
            fn (Builder $builder, string $word, string $like) => $builder
                ->where('notes', 'like', $like)
                ->orWhere('recorded_by_name', 'like', $like)
                ->orWhere('entry_type', 'like', $like)
                ->orWhereHas('projectMaterial.material', fn (Builder $material) => $material
                    ->where('name', 'like', $like)
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('brand_name', 'like', $like))),
            [[0, 'desc']],
        );

        return $table->respond(
            $page,
            fn (ProjectMaterialEntry $entry): string => view('projects.partials.material-entry-row', [
                'entry' => $entry,
                'canVoid' => $this->rules->canVoid($user, $project, $entry),
                'voidUrl' => $this->actionUrl($request, $project, 'void', ['entry' => $entry->material_entry_id]),
            ])->render(),
        );
    }

    /**
     * The picker's matches for what was typed, each with its stock figures.
     */
    public function suggest(Request $request, Project $project, StockAvailability $availability): JsonResponse
    {
        if (! $this->rules->canManage($request->user(), $project) && ! $this->rules->canRequest($request->user(), $project)) {
            throw new AccessDeniedHttpException('You cannot change materials on this project.');
        }

        $query = mb_substr((string) $request->query('q', ''), 0, 100);
        $materials = $this->catalog->suggest($query, $project);
        $figures = $availability->forMaterials($materials->pluck('material_id')->all());
        // Prices stay with those who write quotations.
        $prices = RoleAccess::can($request->user(), RoleAccess::CREATE_PROJECTS)
            ? Material::query()->whereKey($materials->pluck('material_id')->all())->pluck('unit_price', 'material_id')
            : collect();

        return response()->json([
            'materials' => $materials
                ->map(fn (array $material): array => $material
                    + ($figures[$material['material_id']] ?? [])
                    + ($prices->has($material['material_id']) ? ['unit_price' => $prices[$material['material_id']]] : []))
                ->all(),
        ]);
    }

    /**
     * The create wizard's material search: what matches what was typed, each
     * with how many are on hand, assigned to live projects, and available.
     *
     * Not tied to a project - there is none yet - so it offers the plain
     * suggestions, and the office alone reaches it.
     */
    public function options(Request $request, StockAvailability $availability): JsonResponse
    {
        $query = mb_substr((string) $request->query('q', ''), 0, 100);
        $materials = $this->catalog->suggest($query);
        $figures = $availability->forMaterials($materials->pluck('material_id')->all());
        // Prices stay with those who write quotations.
        $prices = RoleAccess::can($request->user(), RoleAccess::CREATE_PROJECTS)
            ? Material::query()->whereKey($materials->pluck('material_id')->all())->pluck('unit_price', 'material_id')
            : collect();

        return response()->json([
            'materials' => $materials
                ->map(fn (array $material): array => $material
                    + ($figures[$material['material_id']] ?? [])
                    + ($prices->has($material['material_id']) ? ['unit_price' => $prices[$material['material_id']]] : []))
                ->all(),
        ]);
    }

    /**
     * Add a material to the master list from this project, because what the
     * job needs is not on it yet. Answered with the new material, which the
     * picker then selects.
     */
    public function quickAdd(Request $request, Project $project): JsonResponse
    {
        $this->authorizeCatalogUse($request, $project);

        $validator = Validator::make($request->all(), [
            'material_type_id' => ['required', 'integer', 'exists:tbl_material_types,material_type_id'],
            'brand_id' => ['nullable', 'integer', 'exists:tbl_brands,brand_id'],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', Rule::enum(MaterialUnit::class)],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'material_type_id.required' => 'Choose a material type.',
            'material_type_id.exists' => 'Choose a material type.',
            'brand_id.exists' => 'Choose a brand from the list.',
            'name.required' => 'Enter a material name.',
            'unit.required' => 'Choose a unit.',
            'unit.enum' => 'Choose a unit.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $material = $this->catalog->add($validator->validated(), $request->user(), $project);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_CREATED,
            null,
            sprintf("Added '%s' to the material list from %s.", $material->displayName(), $project->reference_no),
            $project
        );

        return response()->json([
            'message' => sprintf('%s added to list.', $material->displayName()),
            // Just listed, so none of it has been received yet.
            'material' => $material->toOption() + ['available' => 0],
        ]);
    }

    /**
     * More of a material on site, or a material the plan did not have - taken
     * off the warehouse shelf today, up to what is available.
     */
    public function add(Request $request, Project $project): RedirectResponse
    {
        if (! $this->rules->canManage($request->user(), $project)) {
            throw new AccessDeniedHttpException('You cannot add materials to this project.');
        }

        $validator = Validator::make($request->all(), [
            'material_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->refused($request, $project, $validator->errors()->first());
        }

        $data = $validator->validated();
        $material = Material::query()->find($data['material_id']);

        if ($material === null) {
            return $this->refused($request, $project, 'That material is no longer listed.');
        }

        try {
            $this->ledger->add(
                $project,
                $material,
                $data['quantity'],
                BusinessTime::today(),
                null,
                $request->user()
            );
        } catch (RuntimeException $exception) {
            return $this->refused($request, $project, $exception->getMessage());
        }

        return $this->done($request, $project, sprintf(
            '%s %s of %s added.',
            $material->unit->format($data['quantity']),
            $material->unit->value,
            $material->displayName()
        ));
    }

    /**
     * Some of what is on site used, or returned.
     */
    public function record(Request $request, Project $project): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'project_material_id' => ['required', 'integer'],
            'entry_type' => ['required', Rule::in(ProjectMaterialEntry::DRAWING_TYPES)],
            'quantity' => ['required', 'numeric'],
            'entry_date' => ['required', 'date', 'before_or_equal:'.BusinessTime::today()->toDateString()],
            'notes' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->refused($request, $project, $validator->errors()->first());
        }

        $data = $validator->validated();

        if (! $this->rules->canRecordType($request->user(), $project, $data['entry_type'])) {
            throw new AccessDeniedHttpException('You cannot record materials on this project.');
        }

        $line = ProjectMaterial::query()
            ->with('material')
            ->where('project_id', $project->project_id)
            ->find($data['project_material_id']);

        if ($line === null) {
            return $this->refused($request, $project, 'That material is not on this project.');
        }

        // A lead's return waits for the inventory side to approve it.
        if ($data['entry_type'] === ProjectMaterialEntry::TYPE_RETURNED && $this->rules->returnNeedsApproval($request->user(), $project)) {
            try {
                app(MaterialRequests::class)->requestReturn($project, $line, $data['quantity'], $data['entry_date'], $data['notes'] ?? null, $request->user());
            } catch (RuntimeException $exception) {
                return $this->refused($request, $project, $exception->getMessage());
            }

            return $this->done($request, $project, 'Return sent for approval.');
        }

        try {
            $this->ledger->record($project, $line, $data['entry_type'], $data['quantity'], $data['entry_date'], $data['notes'] ?? null, $request->user());
        } catch (RuntimeException $exception) {
            return $this->refused($request, $project, $exception->getMessage());
        }

        return $this->done($request, $project, sprintf(
            '%s %s %s of %s.',
            $data['entry_type'] === ProjectMaterialEntry::TYPE_USED ? 'Used' : 'Returned',
            $line->material->unit->format($data['quantity']),
            $line->material->unit->value,
            $line->material->displayName()
        ));
    }

    public function void(Request $request, Project $project, ProjectMaterialEntry $entry): RedirectResponse
    {
        if ((int) $entry->project_id !== (int) $project->project_id) {
            abort(404);
        }

        if (! $this->rules->canVoid($request->user(), $project, $entry)) {
            throw new AccessDeniedHttpException('You cannot void this entry.');
        }

        try {
            $this->ledger->void($project, $entry, $request->user());
        } catch (RuntimeException $exception) {
            return $this->refused($request, $project, $exception->getMessage());
        }

        return $this->done($request, $project, 'Entry voided.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'material_id.required' => 'Choose a material.',
            'project_material_id.required' => 'Choose a material.',
            'entry_type.required' => 'Choose Used or Returned.',
            'entry_type.in' => 'Choose Used or Returned.',
            'quantity.required' => 'Enter a quantity.',
            'quantity.numeric' => 'Enter a quantity.',
            'entry_date.required' => 'Choose a date.',
            'entry_date.date' => 'Choose a date.',
            'entry_date.before_or_equal' => 'The date cannot be in the future.',
            'notes.max' => 'Notes are too long.',
        ];
    }

    /**
     * Back to the Materials tab with the reason - the tab, not merely the
     * page, so the technicians' page (which reloads) opens where the person
     * was rather than on its first tab.
     */
    private function refused(Request $request, Project $project, string $message): RedirectResponse
    {
        return redirect()
            ->to($this->projectUrl($request, $project).'#project-materials')
            ->withInput()
            ->with('error', $message);
    }

    private function done(Request $request, Project $project, string $message): RedirectResponse
    {
        return redirect()
            ->to($this->projectUrl($request, $project).'#project-materials')
            ->with('success', $message);
    }

    /**
     * The office reads every project; a technician reads the ones they are
     * on, through the same policy the rest of their portal uses.
     */
    private function authorizeView(Request $request, Project $project): void
    {
        if ($this->portal($request) !== 'technician') {
            return;
        }

        $this->authorize('viewAssigned', $project);
    }

    private function authorizeCatalogUse(Request $request, Project $project): void
    {
        if (! $this->rules->canAddToCatalog($request->user(), $project)) {
            throw new AccessDeniedHttpException('You cannot change materials on this project.');
        }
    }

    /**
     * Which portal this request came through - read from the route name, as
     * ProjectPhaseController does.
     */
    private function portal(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return match (true) {
            str_starts_with($name, 'super-admin.') => 'super-admin',
            str_starts_with($name, 'inventory.') => 'inventory',
            default => 'technician',
        };
    }

    private function projectUrl(Request $request, Project $project): string
    {
        return route($this->portal($request).'.projects.show', $project->project_id);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function actionUrl(Request $request, Project $project, string $action, array $parameters = []): string
    {
        return route(sprintf('%s.projects.materials.%s', $this->portal($request), $action), ['project' => $project->project_id] + $parameters);
    }

    /**
     * The groups, brands and units the Add to List dialog offers - shared
     * with the views through this so both portals list them the same way.
     *
     * @return array{types: array<int, array{material_type_id: int, type_name: string}>, brands: array<int, array{brand_id: int, brand_name: string}>, units: array<string, string>}
     */
    public static function quickAddOptions(): array
    {
        return [
            'types' => MaterialType::query()
                ->orderBy('type_name')
                ->get(['material_type_id', 'type_name'])
                ->map(fn (MaterialType $type): array => [
                    'material_type_id' => (int) $type->material_type_id,
                    'type_name' => $type->type_name,
                ])
                ->all(),
            'brands' => Brand::query()
                ->orderBy('brand_name')
                ->get(['brand_id', 'brand_name'])
                ->map(fn (Brand $brand): array => [
                    'brand_id' => (int) $brand->brand_id,
                    'brand_name' => $brand->brand_name,
                ])
                ->all(),
            'units' => MaterialUnit::options(),
        ];
    }
}
