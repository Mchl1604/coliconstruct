<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialSubtype;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\User;
use App\Support\MaterialUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The material list: the one place its rows, its groups and their sub-types,
 * and its brands are written, and the suggestions a project's material picker
 * offers from it.
 *
 * It says what a material is called, who makes it, what it is counted in and
 * when it counts as Low Stock. How many the company holds is StockLedger's.
 *
 * Written from two places. Purchasing keeps it on the Inventory page; a lead
 * or an administrator may add to it from a project when what they need is
 * missing, so the next project finds it. Both come through add(), so an entry
 * added from a project is held to exactly the rules one added in Inventory is.
 */
class MaterialCatalog
{
    /**
     * How many suggestions a picker is offered at once.
     */
    public const SUGGESTION_LIMIT = 12;

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    /**
     * Every group, with how many materials are in it and its sub-types.
     *
     * @return Collection<int, array{material_type_id: int, type_name: string, material_count: int, subtypes: array<int, array{material_subtype_id: int, subtype_name: string, material_count: int}>}>
     */
    public function types(): Collection
    {
        return MaterialType::query()
            ->withCount('materials')
            ->with(['subtypes' => fn ($query) => $query->withCount('materials')->orderBy('subtype_name')])
            ->orderBy('type_name')
            ->get()
            ->map(fn (MaterialType $type): array => [
                'material_type_id' => (int) $type->material_type_id,
                'type_name' => $type->type_name,
                'material_count' => (int) $type->materials_count,
                'subtypes' => $type->subtypes
                    ->map(fn (MaterialSubtype $subtype): array => [
                        'material_subtype_id' => (int) $subtype->material_subtype_id,
                        'subtype_name' => $subtype->subtype_name,
                        'material_count' => (int) $subtype->materials_count,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    public function addType(string $name): MaterialType
    {
        $name = $this->clean($name);

        $this->assertTypeNameIsFree($name);

        return MaterialType::create(['type_name' => $name]);
    }

    public function renameType(MaterialType $type, string $name): MaterialType
    {
        $name = $this->clean($name);

        $this->assertTypeNameIsFree($name, $type);

        $type->update(['type_name' => $name]);

        return $type->refresh();
    }

    /**
     * Refused while any material is filed under it - moving them is a choice
     * somebody should make, not a side effect of tidying the groups.
     *
     * @throws RuntimeException
     */
    public function removeType(MaterialType $type): void
    {
        $count = $type->materials()->count();

        if ($count > 0) {
            throw new RuntimeException(sprintf(
                'It has %d %s. Move them first.',
                $count,
                $count === 1 ? 'material' : 'materials'
            ));
        }

        $type->delete();
    }

    // ------------------------------------------------------------------
    // Sub-types
    // ------------------------------------------------------------------

    public function addSubtype(MaterialType $type, string $name): MaterialSubtype
    {
        $name = $this->clean($name);

        $this->assertSubtypeNameIsFree($type, $name);

        return MaterialSubtype::create([
            'material_type_id' => $type->material_type_id,
            'subtype_name' => $name,
        ]);
    }

    public function renameSubtype(MaterialSubtype $subtype, string $name): MaterialSubtype
    {
        $name = $this->clean($name);

        $this->assertSubtypeNameIsFree($subtype->type, $name, $subtype);

        $subtype->update(['subtype_name' => $name]);

        return $subtype->refresh();
    }

    /**
     * Refused while any material is filed under it, for the reason
     * removeType() gives.
     *
     * @throws RuntimeException
     */
    public function removeSubtype(MaterialSubtype $subtype): void
    {
        $count = $subtype->materials()->count();

        if ($count > 0) {
            throw new RuntimeException(sprintf(
                'It has %d %s. Move them first.',
                $count,
                $count === 1 ? 'material' : 'materials'
            ));
        }

        $subtype->delete();
    }

    // ------------------------------------------------------------------
    // Brands
    // ------------------------------------------------------------------

    /**
     * Every brand, with how many materials carry it.
     *
     * @return Collection<int, array{brand_id: int, brand_name: string, material_count: int}>
     */
    public function brands(): Collection
    {
        return Brand::query()
            ->withCount('materials')
            ->orderBy('brand_name')
            ->get()
            ->map(fn (Brand $brand): array => [
                'brand_id' => (int) $brand->brand_id,
                'brand_name' => $brand->brand_name,
                'material_count' => (int) $brand->materials_count,
            ])
            ->values();
    }

    public function addBrand(string $name): Brand
    {
        $name = $this->clean($name);

        $this->assertBrandNameIsFree($name);

        return Brand::create(['brand_name' => $name]);
    }

    /**
     * Every material carrying it reads the new name at once - it is one row,
     * not a word copied onto each of them.
     */
    public function renameBrand(Brand $brand, string $name): Brand
    {
        $name = $this->clean($name);

        $this->assertBrandNameIsFree($name, $brand);

        $brand->update(['brand_name' => $name]);

        return $brand->refresh();
    }

    /**
     * Refused while any material carries it, for the reason removeType()
     * gives.
     *
     * @throws RuntimeException
     */
    public function removeBrand(Brand $brand): void
    {
        $count = $brand->materials()->count();

        if ($count > 0) {
            throw new RuntimeException(sprintf(
                'It has %d %s. Change them first.',
                $count,
                $count === 1 ? 'material' : 'materials'
            ));
        }

        $brand->delete();
    }

    // ------------------------------------------------------------------
    // Materials
    // ------------------------------------------------------------------

    /**
     * Add a material to the list.
     *
     * @param  array{material_type_id: int, material_subtype_id?: int|null, brand_id?: int|null, name: string, unit: string, description?: string|null, reorder_level?: float|string|null}  $attributes
     *
     * @throws RuntimeException when the same name, brand and unit is already listed.
     */
    public function add(array $attributes, ?User $by = null, ?Project $fromProject = null): Material
    {
        $values = $this->values($attributes);

        $this->assertMaterialIsNew($values);

        return Material::create($values + [
            'is_active' => true,
            'created_by' => $by?->id,
            'created_from_project_id' => $fromProject?->project_id,
        ])->load('type');
    }

    /**
     * Change a material's details.
     *
     * The unit is the one thing that cannot change once any project has used
     * it: twelve "pieces" would silently become twelve meters on every one of
     * them. Everything else - a corrected spelling, a brand, a description - is
     * a fix to the label, and every project reads the new label.
     *
     * @param  array{material_type_id: int, material_subtype_id?: int|null, brand_id?: int|null, name: string, unit: string, description?: string|null, reorder_level?: float|string|null}  $attributes
     *
     * @throws RuntimeException
     */
    public function update(Material $material, array $attributes): Material
    {
        $values = $this->values($attributes);

        if ($values['unit'] !== $material->unit->value && $this->isUsed($material)) {
            throw new RuntimeException('Used on projects. The unit cannot change.');
        }

        $this->assertMaterialIsNew($values, $material);

        $material->update($values);

        return $material->refresh()->load('type');
    }

    /**
     * Take a material off the pickers, or put it back. Projects that already
     * have it keep it.
     */
    public function setActive(Material $material, bool $active): Material
    {
        $material->update(['is_active' => $active]);

        return $material->refresh();
    }

    /**
     * Delete a material nobody has used. One that is on a project is
     * deactivated instead - the project still has to say what it was.
     *
     * @throws RuntimeException
     */
    public function remove(Material $material): void
    {
        if ($this->isUsed($material)) {
            throw new RuntimeException('Used on projects.');
        }

        $material->delete();
    }

    public function isUsed(Material $material): bool
    {
        return ProjectMaterial::query()
            ->where('material_id', $material->material_id)
            ->exists();
    }

    // ------------------------------------------------------------------
    // Suggestions
    // ------------------------------------------------------------------

    /**
     * The active materials matching what was typed, best first.
     *
     * Every word has to match somewhere - name, brand, description or group -
     * so "daikin 1.5" finds the 1.5 HP Daikin units and nothing else. Among
     * the matches, the materials used most on earlier projects of the same
     * type come first: an Aircon Installation is offered the brackets and the
     * copper tube the last Aircon Installations used before anything else.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function suggest(string $query, ?Project $project = null, int $limit = self::SUGGESTION_LIMIT): Collection
    {
        $words = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $materials = Material::query()
            ->active()
            ->with('type')
            ->when($words !== [], function (Builder $builder) use ($words): void {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';

                    $builder->where(function (Builder $match) use ($like): void {
                        $match->where('name', 'like', $like)
                            ->orWhereHas('brand', fn (Builder $brand) => $brand->where('brand_name', 'like', $like))
                            ->orWhere('description', 'like', $like)
                            ->orWhereHas('type', fn (Builder $type) => $type->where('type_name', 'like', $like))
                            ->orWhereHas('subtype', fn (Builder $subtype) => $subtype->where('subtype_name', 'like', $like));
                    });
                }
            })
            ->orderBy('name')
            ->limit(200)
            ->get();

        $usage = $this->usageOnSimilarProjects($project);

        return $materials
            ->sortBy([
                fn (Material $a, Material $b): int => ($usage[$b->material_id] ?? 0) <=> ($usage[$a->material_id] ?? 0),
                fn (Material $a, Material $b): int => strnatcasecmp($a->displayName(), $b->displayName()),
            ])
            ->take($limit)
            ->map(fn (Material $material): array => $material->toOption() + [
                'used_on' => (int) ($usage[$material->material_id] ?? 0),
            ])
            ->values();
    }

    /**
     * The materials most often planned on earlier projects of the same
     * type, for the chips above a project's material list.
     *
     * Read from what projects actually used rather than from a template
     * somebody has to keep up: the list gets better on its own as work is
     * set up.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function suggestedFor(Project $project, int $limit = 10): Collection
    {
        $usage = $this->usageOnSimilarProjects($project);

        if ($usage === []) {
            return collect();
        }

        arsort($usage);

        $ids = array_slice(array_keys($usage), 0, $limit * 2);

        return Material::query()
            ->active()
            ->with('type')
            ->whereIn('material_id', $ids)
            ->get()
            ->sortByDesc(fn (Material $material): int => $usage[$material->material_id] ?? 0)
            ->take($limit)
            ->map(fn (Material $material): array => $material->toOption() + [
                'used_on' => (int) ($usage[$material->material_id] ?? 0),
            ])
            ->values();
    }

    /**
     * material_id => how many other projects sharing a type with this one
     * have it on their list.
     *
     * @return array<int, int>
     */
    private function usageOnSimilarProjects(?Project $project): array
    {
        if ($project === null) {
            return [];
        }

        $typeIds = $project->projectTypes()->pluck('tbl_project_types.type_id');

        if ($typeIds->isEmpty()) {
            return [];
        }

        return ProjectMaterial::query()
            ->select('material_id', DB::raw('COUNT(DISTINCT project_id) as uses'))
            ->where('project_id', '!=', $project->project_id)
            ->whereIn('project_id', function ($query) use ($typeIds): void {
                $query->select('project_id')
                    ->from('tbl_project_type_map')
                    ->whereIn('type_id', $typeIds);
            })
            ->groupBy('material_id')
            ->pluck('uses', 'material_id')
            ->map(fn ($uses): int => (int) $uses)
            ->all();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The columns a submission writes, trimmed and with blanks as nulls.
     *
     * The reorder level and the sub-type are only written when the
     * submission carries them, so a project's Add to List - which has neither
     * field - leaves them alone.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{material_type_id: int, material_subtype_id?: int|null, brand_id: int|null, name: string, unit: string, description: string|null, reorder_level?: float|null}
     */
    private function values(array $attributes): array
    {
        $unit = MaterialUnit::tryFrom((string) ($attributes['unit'] ?? ''));

        if ($unit === null) {
            throw new RuntimeException('Choose a unit.');
        }

        if (! MaterialType::query()->whereKey($attributes['material_type_id'] ?? null)->exists()) {
            throw new RuntimeException('Choose a material type.');
        }

        $brandId = filled($attributes['brand_id'] ?? null) ? (int) $attributes['brand_id'] : null;

        if ($brandId !== null && ! Brand::query()->whereKey($brandId)->exists()) {
            throw new RuntimeException('Choose a brand from the list.');
        }

        $description = $this->clean((string) ($attributes['description'] ?? ''));

        $values = [
            'material_type_id' => (int) $attributes['material_type_id'],
            'brand_id' => $brandId,
            'name' => $this->clean((string) $attributes['name']),
            'unit' => $unit->value,
            'description' => $description === '' ? null : $description,
        ];

        if (array_key_exists('reorder_level', $attributes)) {
            $values['reorder_level'] = $this->reorderLevel($unit, $attributes['reorder_level']);
        }

        if (array_key_exists('material_subtype_id', $attributes)) {
            $values['material_subtype_id'] = $this->subtypeOf($values['material_type_id'], $attributes['material_subtype_id']);
        }

        return $values;
    }

    /**
     * A sub-type has to be one of the chosen type's own: Split-Type is a kind
     * of aircon unit, not of copper tube.
     *
     * @throws RuntimeException
     */
    private function subtypeOf(int $typeId, mixed $subtypeId): ?int
    {
        if (! filled($subtypeId)) {
            return null;
        }

        $belongs = MaterialSubtype::query()
            ->whereKey((int) $subtypeId)
            ->where('material_type_id', $typeId)
            ->exists();

        if (! $belongs) {
            throw new RuntimeException('Choose a sub-type of this type.');
        }

        return (int) $subtypeId;
    }

    /**
     * A reorder level is a quantity like any other, held to the unit's rule
     * on fractions; blank means the material has none.
     *
     * @throws RuntimeException
     */
    private function reorderLevel(MaterialUnit $unit, mixed $level): ?float
    {
        if ($level === null || trim((string) $level) === '') {
            return null;
        }

        if (! is_numeric($level) || (float) $level < 0) {
            throw new RuntimeException('Enter a reorder level of zero or more.');
        }

        if ((float) $level == 0) {
            return 0.0;
        }

        $problem = $unit->quantityProblem($level);

        if ($problem !== null) {
            throw new RuntimeException('Reorder level: '.lcfirst($problem));
        }

        return round((float) $level, 2);
    }

    /**
     * Name, brand and unit together say which material this is, whatever
     * their casing. Enforced here rather than by an index, because brand is
     * nullable and a unique index never sees two nulls as the same.
     *
     * @param  array{name: string, brand_id: int|null, unit: string}  $values
     *
     * @throws RuntimeException
     */
    private function assertMaterialIsNew(array $values, ?Material $except = null): void
    {
        $taken = Material::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($values['name'])])
            ->where('unit', $values['unit'])
            ->when(
                $values['brand_id'] === null,
                fn (Builder $query) => $query->whereNull('brand_id'),
                fn (Builder $query) => $query->where('brand_id', $values['brand_id'])
            )
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->material_id))
            ->first();

        if ($taken !== null) {
            throw new RuntimeException(sprintf(
                "'%s' is already listed%s.",
                $taken->displayName(),
                $taken->is_active ? '' : ' (inactive)'
            ));
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertTypeNameIsFree(string $name, ?MaterialType $except = null): void
    {
        if ($name === '') {
            throw new RuntimeException('Enter a type name.');
        }

        $taken = MaterialType::query()
            ->whereRaw('LOWER(type_name) = ?', [mb_strtolower($name)])
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->material_type_id))
            ->exists();

        if ($taken) {
            throw new RuntimeException(sprintf("'%s' already exists.", $name));
        }
    }

    /**
     * Unique within its type - Fittings may be a kind of copper and a kind
     * of PVC at once.
     *
     * @throws RuntimeException
     */
    private function assertSubtypeNameIsFree(MaterialType $type, string $name, ?MaterialSubtype $except = null): void
    {
        if ($name === '') {
            throw new RuntimeException('Enter a sub-type name.');
        }

        $taken = MaterialSubtype::query()
            ->where('material_type_id', $type->material_type_id)
            ->whereRaw('LOWER(subtype_name) = ?', [mb_strtolower($name)])
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->material_subtype_id))
            ->exists();

        if ($taken) {
            throw new RuntimeException(sprintf("'%s' already exists.", $name));
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertBrandNameIsFree(string $name, ?Brand $except = null): void
    {
        if ($name === '') {
            throw new RuntimeException('Enter a brand name.');
        }

        $taken = Brand::query()
            ->whereRaw('LOWER(brand_name) = ?', [mb_strtolower($name)])
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->brand_id))
            ->exists();

        if ($taken) {
            throw new RuntimeException(sprintf("'%s' already exists.", $name));
        }
    }

    /**
     * Collapse runs of whitespace, so "Copper  Tube " and "Copper Tube" are
     * the same name.
     */
    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
