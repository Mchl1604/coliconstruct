<?php

namespace App\Services;

use App\Models\Material;
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
 * The material list: the one place its rows and its groups are written, and
 * the suggestions a project's material picker offers from it.
 *
 * A reference list, not stock. It says what a material is called, who makes
 * it and what it is counted in - never how many the company has.
 *
 * Written from two places. The Super Admin keeps it in System Settings; a lead
 * or an administrator may add to it from a project when what they need is
 * missing, so the next project finds it. Both come through add(), so an entry
 * added from a project is held to exactly the rules one added in Settings is.
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
     * Every group, with how many materials are in it.
     *
     * @return Collection<int, array{material_type_id: int, type_name: string, material_count: int}>
     */
    public function types(): Collection
    {
        return MaterialType::query()
            ->withCount('materials')
            ->orderBy('type_name')
            ->get()
            ->map(fn (MaterialType $type): array => [
                'material_type_id' => (int) $type->material_type_id,
                'type_name' => $type->type_name,
                'material_count' => (int) $type->materials_count,
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
    // Materials
    // ------------------------------------------------------------------

    /**
     * Add a material to the list.
     *
     * @param  array{material_type_id: int, name: string, brand?: string|null, unit: string, description?: string|null}  $attributes
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
     * @param  array{material_type_id: int, name: string, brand?: string|null, unit: string, description?: string|null}  $attributes
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
            throw new RuntimeException('Used on projects. Deactivate it instead.');
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
                            ->orWhere('brand', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhereHas('type', fn (Builder $type) => $type->where('type_name', 'like', $like));
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
     * @param  array<string, mixed>  $attributes
     * @return array{material_type_id: int, name: string, brand: string|null, unit: string, description: string|null}
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

        $brand = $this->clean((string) ($attributes['brand'] ?? ''));
        $description = $this->clean((string) ($attributes['description'] ?? ''));

        return [
            'material_type_id' => (int) $attributes['material_type_id'],
            'name' => $this->clean((string) $attributes['name']),
            'brand' => $brand === '' ? null : $brand,
            'unit' => $unit->value,
            'description' => $description === '' ? null : $description,
        ];
    }

    /**
     * Name, brand and unit together say which material this is, whatever
     * their casing. Enforced here rather than by an index, because brand is
     * nullable and a unique index never sees two nulls as the same.
     *
     * @param  array{name: string, brand: string|null, unit: string}  $values
     *
     * @throws RuntimeException
     */
    private function assertMaterialIsNew(array $values, ?Material $except = null): void
    {
        $taken = Material::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($values['name'])])
            ->where('unit', $values['unit'])
            ->when(
                $values['brand'] === null,
                fn (Builder $query) => $query->whereNull('brand'),
                fn (Builder $query) => $query->whereRaw('LOWER(brand) = ?', [mb_strtolower((string) $values['brand'])])
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
     * Collapse runs of whitespace, so "Copper  Tube " and "Copper Tube" are
     * the same name.
     */
    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
