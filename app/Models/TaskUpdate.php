<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day's progress on a task - see the table's migration and TaskProgress,
 * the only thing that writes one.
 */
class TaskUpdate extends Model
{
    protected $table = 'tbl_task_updates';

    protected $primaryKey = 'task_update_id';

    protected $fillable = [
        'task_id',
        'technician_id',
        'update_date',
        'notes',
        'posted_by',
        'posted_by_name',
    ];

    protected $casts = [
        'update_date' => 'date',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id', 'task_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by', 'id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(TaskUpdateImage::class, 'task_update_id', 'task_update_id');
    }

    /**
     * Who pressed Post, as the timeline prints it.
     */
    public function posterName(): string
    {
        return $this->poster?->fullName() ?? $this->posted_by_name ?? 'Someone';
    }

    /**
     * Whether a lead posted it for the technician whose day it was.
     */
    public function wasPostedOnBehalf(): bool
    {
        return $this->posted_by !== null
            && $this->technician?->account_id !== null
            && (int) $this->technician->account_id !== (int) $this->posted_by;
    }
}
