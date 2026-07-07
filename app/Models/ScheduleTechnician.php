<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleTechnician extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_schedule_technicians';

    protected $primaryKey = 'schedule_technician_id';

    protected $fillable = [
        'schedule_id',
        'project_technician_id',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id', 'schedule_id');
    }

    public function projectTechnician(): BelongsTo
    {
        return $this->belongsTo(ProjectTechnician::class, 'project_technician_id', 'project_technician_id');
    }
}
