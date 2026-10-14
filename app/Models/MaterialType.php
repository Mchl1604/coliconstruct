<?php

namespace App\Models;

use Database\Factories\MaterialTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One group of the material list - Aircon Units, Refrigerants, Electrical.
 */
class MaterialType extends Model
{
    /** @use HasFactory<MaterialTypeFactory> */
    use HasFactory;

    protected $table = 'tbl_material_types';

    protected $primaryKey = 'material_type_id';

    protected $fillable = [
        'type_name',
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class, 'material_type_id', 'material_type_id');
    }
}
