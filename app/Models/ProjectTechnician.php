<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTechnician extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_project_technicians';

    protected $primaryKey = 'project_technician_id';

    protected $fillable = [
        'project_id',
        'technician_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    public function scheduleTechnicians(): HasMany
    {
        return $this->hasMany(ScheduleTechnician::class, 'project_technician_id', 'project_technician_id');
    }
}
