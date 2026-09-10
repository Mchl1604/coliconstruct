<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One default task a project type contributes to one stage.
 *
 * The per-type half of a template. The stage says what the phase is called and
 * where it falls; these say what that type actually does during it, and they
 * are the rows that differ between two types sharing a stage.
 *
 * Copied onto a project at phase setup and never linked to it afterwards -
 * editing one of these has no effect on any project that has already been set
 * up, which is the whole reason templates are safe to change.
 */
class ProjectTypeStageTask extends Model
{
    protected $table = 'tbl_project_type_stage_tasks';

    /**
     * How many default tasks one type may attach to one stage.
     *
     * The same kind of ceiling as ProjectPhase::MAX_PHASES and for the same
     * reason: it is here so a stuck key cannot write two hundred rows, not
     * because anybody has an opinion about 50. A project that is three types
     * can still legitimately reach 150 tasks in a stage, which is why the cap
     * is per type per stage rather than on the merged result - refusing a merge
     * would punish the project for the templates being large.
     */
    public const MAX_PER_STAGE = 50;

    protected $fillable = [
        'type_id',
        'stage_id',
        'sequence',
        'title',
        'description',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'type_id', 'type_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PhaseStage::class, 'stage_id', 'stage_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('id');
    }
}
