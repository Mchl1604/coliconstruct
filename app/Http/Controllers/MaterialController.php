<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\MaterialCatalog;
use App\Support\MaterialUnit;
use App\Support\ServerTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Configuration -> System Settings -> Materials: the material list and its
 * groups.
 *
 * Super Admin only, checked here rather than on the route group for the same
 * reason Project Types does it: the Configuration page is shared with Admin,
 * and System Settings is not theirs. Every write goes through MaterialCatalog,
 * the same service a project's Add to List goes through.
 */
class MaterialController extends Controller
{
    public function __construct(
        private readonly MaterialCatalog $catalog,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * One page of the list, for serverTable.js.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $table = ServerTable::fromRequest($request);
        $typeId = $request->integer('type') ?: null;
        $status = (string) $request->query('status', 'all');

        $query = Material::query()
            ->with('type')
            ->withCount('projectMaterials')
            ->when($typeId, fn (Builder $builder) => $builder->where('material_type_id', $typeId))
            ->when($status === 'active', fn (Builder $builder) => $builder->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $builder) => $builder->where('is_active', false));

        $page = $table->paginate(
            $query,
            [
                0 => 'name',
                1 => 'brand',
                2 => fn (Builder $builder, string $dir) => $builder->orderBy(
                    MaterialType::query()
                        ->select('type_name')
                        ->whereColumn('tbl_material_types.material_type_id', 'tbl_materials.material_type_id'),
                    $dir
                ),
                3 => 'unit',
                4 => fn (Builder $builder, string $dir) => $builder->orderBy('project_materials_count', $dir),
                5 => 'is_active',
            ],
            fn (Builder $builder, string $word, string $like) => $builder
                ->where('name', 'like', $like)
                ->orWhere('brand', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereHas('type', fn (Builder $type) => $type->where('type_name', 'like', $like)),
            [[2, 'asc'], [0, 'asc']],
        );

        return $table->respond(
            $page,
            fn (Material $material): string => view('super-admin.partials.material-row', [
                'material' => $material,
            ])->render(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateMaterial($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $material = $this->catalog->add($validator->validated(), $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_CREATED,
            null,
            sprintf("Added '%s' to the material list.", $material->displayName())
        );

        return response()->json([
            'message' => sprintf('%s added.', $material->displayName()),
            'material' => $material->toOption(),
        ]);
    }

    public function update(Request $request, Material $material): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateMaterial($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $previous = $material->displayName();

        try {
            $material = $this->catalog->update($material, $validator->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_UPDATED,
            null,
            $previous === $material->displayName()
                ? sprintf("Updated the material '%s'.", $material->displayName())
                : sprintf("Updated the material '%s', now '%s'.", $previous, $material->displayName())
        );

        return response()->json([
            'message' => sprintf('%s saved.', $material->displayName()),
            'material' => $material->toOption(),
        ]);
    }

    /**
     * Take a material off the pickers, or put it back.
     */
    public function setStatus(Request $request, Material $material): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $active = $request->boolean('is_active');

        $material = $this->catalog->setActive($material, $active);

        $this->activityLogger->record(
            $active ? ActivityLog::MATERIAL_ACTIVATED : ActivityLog::MATERIAL_DEACTIVATED,
            null,
            sprintf(
                "%s the material '%s'.",
                $active ? 'Activated' : 'Deactivated',
                $material->displayName()
            )
        );

        return response()->json([
            'message' => sprintf('%s %s.', $material->displayName(), $active ? 'activated' : 'deactivated'),
        ]);
    }

    public function destroy(Request $request, Material $material): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $name = $material->displayName();

        try {
            $this->catalog->remove($material);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_DELETED,
            null,
            sprintf("Removed '%s' from the material list.", $name)
        );

        return response()->json(['message' => sprintf('%s removed.', $name)]);
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    public function types(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['types' => $this->catalog->types()->all()]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateTypeName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $type = $this->catalog->addType((string) $request->input('type_name'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_TYPE_CREATED,
            null,
            sprintf("Added the material type '%s'.", $type->type_name)
        );

        return response()->json([
            'message' => sprintf('%s added.', $type->type_name),
            'types' => $this->catalog->types()->all(),
        ]);
    }

    public function updateType(Request $request, MaterialType $materialType): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateTypeName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $previous = $materialType->type_name;

        try {
            $type = $this->catalog->renameType($materialType, (string) $request->input('type_name'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_TYPE_UPDATED,
            null,
            sprintf("Renamed the material type '%s' to '%s'.", $previous, $type->type_name)
        );

        return response()->json([
            'message' => sprintf('Renamed to %s.', $type->type_name),
            'types' => $this->catalog->types()->all(),
        ]);
    }

    public function destroyType(Request $request, MaterialType $materialType): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $name = $materialType->type_name;

        try {
            $this->catalog->removeType($materialType);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_TYPE_DELETED,
            null,
            sprintf("Removed the material type '%s'.", $name)
        );

        return response()->json([
            'message' => sprintf('%s removed.', $name),
            'types' => $this->catalog->types()->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Hand-rolled rather than $request->validate(), for the reason the rest
     * of Configuration gives: a thrown ValidationException here would answer
     * with an HTML redirect instead of JSON.
     */
    private function validateMaterial(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'material_type_id' => ['required', 'integer', 'exists:tbl_material_types,material_type_id'],
            'name' => ['required', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', Rule::enum(MaterialUnit::class)],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'material_type_id.required' => 'Choose a material type.',
            'material_type_id.exists' => 'Choose a material type.',
            'name.required' => 'Enter a material name.',
            'name.max' => 'Name is too long.',
            'brand.max' => 'Brand is too long.',
            'unit.required' => 'Choose a unit.',
            'unit.enum' => 'Choose a unit.',
            'description.max' => 'Description is too long.',
        ]);
    }

    private function validateTypeName(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'type_name' => ['required', 'string', 'max:100'],
        ], [
            'type_name.required' => 'Enter a type name.',
            'type_name.max' => 'Type name is too long.',
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === User::ROLE_SUPER_ADMIN, 403);
    }
}
