<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value a project's target completion date has held.
 *
 * Written by TargetDateChange inside the same transaction as the date it
 * describes, so a save that rolls back leaves no row claiming it happened.
 */
class TargetDateHistory extends Model
{
    /**
     * created_at is set explicitly and there is nothing to update: a change
     * is a fact about a moment, not a record that changes.
     */
    public $timestamps = false;

    protected $table = 'tbl_target_date_history';

    protected $primaryKey = 'target_date_history_id';

    protected $fillable = [
        'project_id',
        'previous_date',
        'new_date',
        'reason',
        'actor_id',
        'actor_name',
        'actor_role',
        'created_at',
    ];

    protected $casts = [
        'previous_date' => 'date',
        'new_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The entry the project was created with, rather than a change to it.
     */
    public function isInitial(): bool
    {
        return $this->previous_date === null;
    }
}
