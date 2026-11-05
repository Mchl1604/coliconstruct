<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialSubtype;
use App\Models\MaterialType;
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
 * Inventory -> Materials: the material list, its groups and their sub-types,
 * and its brands.
 *
 * Who reaches it is the `area:inventory` middleware on the route group - a
 * Super Admin, Admin Officer or Purchasing Admin, see RoleAccess - so nothing
 * here asks again. Every write goes through MaterialCatalog, the same service
 * a project's Add to List goes through.
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
        $table = ServerTable::fromRequest($request);
        $typeId = $request->integer('type') ?: null;
        $subtypeId = $request->integer('subtype') ?: null;
        $status = (string) $request->query('status', 'all');

        $query = Material::query()
            ->with('type')
            ->withCount('projectMaterials')
            ->when($typeId, fn (Builder $builder) => $builder->where('material_type_id', $typeId))
            ->when($subtypeId, fn (Builder $builder) => $builder->where('material_subtype_id', $subtypeId))
            ->when($status === 'active', fn (Builder $builder) => $builder->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $builder) => $builder->where('is_active', false));

        $page = $table->paginate(
            $query,
            [
                0 => 'name',
                1 => fn (Builder $builder, string $dir) => $builder->orderBy(
                    Brand::query()
                        ->select('brand_name')
                        ->whereColumn('tbl_brands.brand_id', 'tbl_materials.brand_id'),
                    $dir
                ),
                2 => fn (Builder $builder, string $dir) => $builder->orderBy(
                    MaterialType::query()
                        ->select('type_name')
                        ->whereColumn('tbl_material_types.material_type_id', 'tbl_materials.material_type_id'),
                    $dir
                ),
                3 => 'unit',
            ],
            fn (Builder $builder, string $word, string $like) => $builder
                ->where('name', 'like', $like)
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('brand_name', 'like', $like))
                ->orWhere('description', 'like', $like)
                ->orWhereHas('type', fn (Builder $type) => $type->where('type_name', 'like', $like))
                ->orWhereHas('subtype', fn (Builder $subtype) => $subtype->where('subtype_name', 'like', $like)),
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

    public function destroy(Material $material): JsonResponse
    {
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

    public function types(): JsonResponse
    {
        return response()->json(['types' => $this->catalog->types()->all()]);
    }

    /**
     * The materials in one type, for its View dialog.
     */
    public function showType(MaterialType $materialType): JsonResponse
    {
        $materials = $materialType->materials()
            ->with('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Material $material): array => $material->toOption());

        return response()->json([
            'type_name' => $materialType->type_name,
            'materials' => $materials->all(),
        ]);
    }

    public function storeType(Request $request): JsonResponse
    {
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

    public function destroyType(MaterialType $materialType): JsonResponse
    {
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
    // Sub-types
    //
    // Each answers with the whole type list, sub-types included, because
    // the counts on the Material Types table move with them.
    // ------------------------------------------------------------------

    public function storeSubtype(Request $request, MaterialType $materialType): JsonResponse
    {
        $validator = $this->validateSubtypeName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $subtype = $this->catalog->addSubtype($materialType, (string) $request->input('subtype_name'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_SUBTYPE_CREATED,
            null,
            sprintf("Added the sub-type '%s' to %s.", $subtype->subtype_name, $materialType->type_name)
        );

        return response()->json([
            'message' => sprintf('%s added.', $subtype->subtype_name),
            'types' => $this->catalog->types()->all(),
        ]);
    }

    public function updateSubtype(Request $request, MaterialSubtype $materialSubtype): JsonResponse
    {
        $validator = $this->validateSubtypeName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $previous = $materialSubtype->subtype_name;

        try {
            $subtype = $this->catalog->renameSubtype($materialSubtype, (string) $request->input('subtype_name'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_SUBTYPE_UPDATED,
            null,
            sprintf("Renamed the sub-type '%s' to '%s' in %s.", $previous, $subtype->subtype_name, $subtype->type?->type_name)
        );

        return response()->json([
            'message' => sprintf('Renamed to %s.', $subtype->subtype_name),
            'types' => $this->catalog->types()->all(),
        ]);
    }

    public function destroySubtype(MaterialSubtype $materialSubtype): JsonResponse
    {
        $name = $materialSubtype->subtype_name;
        $typeName = $materialSubtype->type?->type_name;

        try {
            $this->catalog->removeSubtype($materialSubtype);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::MATERIAL_SUBTYPE_DELETED,
            null,
            sprintf("Removed the sub-type '%s' from %s.", $name, $typeName)
        );

        return response()->json([
            'message' => sprintf('%s removed.', $name),
            'types' => $this->catalog->types()->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Brands
    // ------------------------------------------------------------------

    public function brands(): JsonResponse
    {
        return response()->json(['brands' => $this->catalog->brands()->all()]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $validator = $this->validateBrandName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $brand = $this->catalog->addBrand((string) $request->input('brand_name'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::BRAND_CREATED,
            null,
            sprintf("Added the brand '%s'.", $brand->brand_name)
        );

        return response()->json([
            'message' => sprintf('%s added.', $brand->brand_name),
            'brands' => $this->catalog->brands()->all(),
        ]);
    }

    public function updateBrand(Request $request, Brand $brand): JsonResponse
    {
        $validator = $this->validateBrandName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $previous = $brand->brand_name;

        try {
            $brand = $this->catalog->renameBrand($brand, (string) $request->input('brand_name'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::BRAND_UPDATED,
            null,
            sprintf("Renamed the brand '%s' to '%s'.", $previous, $brand->brand_name)
        );

        return response()->json([
            'message' => sprintf('Renamed to %s.', $brand->brand_name),
            'brands' => $this->catalog->brands()->all(),
        ]);
    }

    public function destroyBrand(Brand $brand): JsonResponse
    {
        $name = $brand->brand_name;

        try {
            $this->catalog->removeBrand($brand);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::BRAND_DELETED,
            null,
            sprintf("Removed the brand '%s'.", $name)
        );

        return response()->json([
            'message' => sprintf('%s removed.', $name),
            'brands' => $this->catalog->brands()->all(),
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
            // Checked against the type by MaterialCatalog.
            'material_subtype_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer', 'exists:tbl_brands,brand_id'],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', Rule::enum(MaterialUnit::class)],
            'description' => ['nullable', 'string', 'max:255'],
            // Checked against the unit by MaterialCatalog.
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'material_type_id.required' => 'Choose a material type.',
            'material_type_id.exists' => 'Choose a material type.',
            'brand_id.exists' => 'Choose a brand from the list.',
            'name.required' => 'Enter a material name.',
            'name.max' => 'Name is too long.',
            'unit.required' => 'Choose a unit.',
            'unit.enum' => 'Choose a unit.',
            'description.max' => 'Description is too long.',
            'reorder_level.numeric' => 'Enter a reorder level of zero or more.',
            'reorder_level.min' => 'Enter a reorder level of zero or more.',
            'unit_price.numeric' => 'Enter a price of zero or more.',
            'unit_price.min' => 'Enter a price of zero or more.',
            'unit_price.max' => 'That price is too high.',
        ]);
    }

    private function validateSubtypeName(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'subtype_name' => ['required', 'string', 'max:100'],
        ], [
            'subtype_name.required' => 'Enter a sub-type name.',
            'subtype_name.max' => 'Sub-type name is too long.',
        ]);
    }

    private function validateBrandName(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'brand_name' => ['required', 'string', 'max:100'],
        ], [
            'brand_name.required' => 'Enter a brand name.',
            'brand_name.max' => 'Brand name is too long.',
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
}
