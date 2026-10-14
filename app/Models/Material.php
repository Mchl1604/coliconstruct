<?php

namespace App\Models;

use App\Support\MaterialUnit;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of the material list.
 *
 * A reference, not stock: nothing here says how many the company holds. See
 * the table's migration.
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $table = 'tbl_materials';

    protected $primaryKey = 'material_id';

    protected $fillable = [
        'material_type_id',
        'name',
        'brand',
        'unit',
        'description',
        'is_active',
        'created_by',
        'created_from_project_id',
    ];

    protected $casts = [
        'unit' => MaterialUnit::class,
        'is_active' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class, 'material_type_id', 'material_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The project it was added from, when it was not added in System
     * Settings.
     */
    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'created_from_project_id', 'project_id');
    }

    public function projectMaterials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class, 'material_id', 'material_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The name as a list prints it: "Split-Type Inverter 1.5 HP (Daikin)".
     */
    public function displayName(): string
    {
        return $this->brand
            ? sprintf('%s (%s)', $this->name, $this->brand)
            : $this->name;
    }

    /**
     * Everything a picker needs to show and choose this material.
     *
     * @return array{material_id: int, name: string, brand: string|null, label: string, type: string|null, unit: string, unit_label: string, allows_fractions: bool, description: string|null, is_active: bool}
     */
    public function toOption(): array
    {
        return [
            'material_id' => (int) $this->material_id,
            'name' => $this->name,
            'brand' => $this->brand,
            'label' => $this->displayName(),
            'type' => $this->type?->type_name,
            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),
            'allows_fractions' => $this->unit->allowsFractions(),
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
