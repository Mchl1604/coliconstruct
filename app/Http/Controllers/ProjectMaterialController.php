<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Services\ActivityLogger;
use App\Services\MaterialCatalog;
use App\Services\ProjectMaterialLedger;
use App\Services\ProjectMaterialRules;
use App\Support\BusinessTime;
use App\Support\MaterialUnit;
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
 * A project's Materials tab, and the material picker on its setup screen.
 *
 * One controller for both portals, as ProjectPhaseController is: an Admin and
 * a Lead Technician record materials through the same rules, and only the
 * page they are sent back to differs.
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
        $canRecord = $this->rules->canRecord($user, $project);

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
                    ->orWhere('brand', 'like', $like)),
            [[0, 'desc']],
        );

        return $table->respond(
            $page,
            fn (ProjectMaterialEntry $entry): string => view('projects.partials.material-entry-row', [
                'entry' => $entry,
                'canVoid' => $canRecord && $this->rules->canVoid($user, $project, $entry),
                'voidUrl' => $this->actionUrl($request, $project, 'void', ['entry' => $entry->material_entry_id]),
            ])->render(),
        );
    }

    /**
     * The picker's matches for what was typed.
     */
    public function suggest(Request $request, Project $project): JsonResponse
    {
        $this->authorizeCatalogUse($request, $project);

        $query = mb_substr((string) $request->query('q', ''), 0, 100);

        return response()->json([
            'materials' => $this->catalog->suggest($query, $project)->all(),
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
            'name' => ['required', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', Rule::enum(MaterialUnit::class)],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'material_type_id.required' => 'Choose a material type.',
            'material_type_id.exists' => 'Choose a material type.',
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
            'material' => $material->toOption(),
        ]);
    }

    /**
     * More of a material on site, or a material the plan did not have.
     */
    public function add(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeRecord($request, $project);

        $validator = Validator::make($request->all(), [
            'material_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric'],
            'entry_date' => ['required', 'date', 'before_or_equal:'.BusinessTime::today()->toDateString()],
            'notes' => ['nullable', 'string', 'max:255'],
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
            $this->ledger->add($project, $material, $data['quantity'], $data['entry_date'], $data['notes'] ?? null, $request->user());
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
        $this->authorizeRecord($request, $project);

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
        $line = ProjectMaterial::query()
            ->with('material')
            ->where('project_id', $project->project_id)
            ->find($data['project_material_id']);

        if ($line === null) {
            return $this->refused($request, $project, 'That material is not on this project.');
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
        if ($this->inAdminPortal($request)) {
            return;
        }

        $this->authorize('viewAssigned', $project);
    }

    private function authorizeRecord(Request $request, Project $project): void
    {
        if (! $this->rules->canRecord($request->user(), $project)) {
            throw new AccessDeniedHttpException('You cannot record materials on this project.');
        }
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
    private function inAdminPortal(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'super-admin.');
    }

    private function projectUrl(Request $request, Project $project): string
    {
        return $this->inAdminPortal($request)
            ? route('super-admin.projects.show', $project->project_id)
            : route('technician.projects.show', $project->project_id);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function actionUrl(Request $request, Project $project, string $action, array $parameters = []): string
    {
        $prefix = $this->inAdminPortal($request) ? 'super-admin' : 'technician';

        return route(sprintf('%s.projects.materials.%s', $prefix, $action), ['project' => $project->project_id] + $parameters);
    }

    /**
     * The groups and units the Add to List dialog offers - shared with the
     * views through this so both portals list them the same way.
     *
     * @return array{types: array<int, array{material_type_id: int, type_name: string}>, units: array<string, string>}
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
            'units' => MaterialUnit::options(),
        ];
    }
}
