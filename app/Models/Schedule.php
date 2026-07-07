<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_schedule';

    protected $primaryKey = 'schedule_id';

    protected $fillable = [
        'project_id',
        'start_datetime',
        'end_datetime',
        'status',
        'remarks',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function scheduleTechnicians(): HasMany
    {
        return $this->hasMany(ScheduleTechnician::class, 'schedule_id', 'schedule_id');
    }
}
