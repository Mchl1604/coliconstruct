<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One material on a project's list, with the quantity planned at setup.
 *
 * What has happened to it since - added, used, returned, left over - is in its
 * entries, and the running figures are worked out from them by
 * ProjectMaterialLedger rather than stored here.
 */
class ProjectMaterial extends Model
{
    protected $table = 'tbl_project_materials';

    protected $primaryKey = 'project_material_id';

    protected $fillable = [
        'project_id',
        'material_id',
        'quantity_needed',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity_needed' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ProjectMaterialEntry::class, 'project_material_id', 'project_material_id');
    }
}
