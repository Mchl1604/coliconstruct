<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One maker on the material list - Daikin, Carrier, Mueller. Kept by
 * Purchasing beside the material types, so every material spells its brand
 * the same way.
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    protected $table = 'tbl_brands';

    protected $primaryKey = 'brand_id';

    protected $fillable = [
        'brand_name',
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class, 'brand_id', 'brand_id');
    }
}
