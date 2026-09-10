<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task typed on the phase setup screen, on a project that is not finalized
 * yet.
 *
 * Everything about it is provisional. It becomes a real Task at finalization
 * and is deleted in the same transaction, so nothing in the application reads
 * one except the setup screen that wrote it.
 *
 * See the table's migration for why these are not simply early rows in
 * tbl_tasks.
 */
class ProjectPhaseDraftTask extends Model
{
    protected $table = 'tbl_project_phase_draft_tasks';

    protected $primaryKey = 'draft_task_id';

    protected $fillable = [
        'phase_id',
        'sequence',
        'title',
        'description',
        'technician_id',
        'start_date',
        'due_date',
    ];

    /**
     * The dates are left uncast for the same reason Task leaves its own
     * uncast: they are rendered straight into date inputs, which want the raw
     * 'Y-m-d' string rather than a stringified Carbon instance.
     */
    protected $casts = [
        'sequence' => 'integer',
    ];

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id', 'phase_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('draft_task_id');
    }
}
