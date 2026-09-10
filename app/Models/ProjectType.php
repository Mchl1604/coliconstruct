<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectType extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_project_types';

    protected $primaryKey = 'type_id';

    protected $fillable = [
        'type_name',
    ];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'tbl_project_type_map',
            'type_id',
            'project_id',
            'type_id',
            'project_id'
        );
    }

    /**
     * The stages this type's work goes through - its half of a phase template.
     *
     * Unordered on purpose. Where a stage falls is a fact about the stage, not
     * about this type's opinion of it, so callers order by the stage's own
     * sort_order and a multi-type project has one ordering rather than several.
     */
    public function stages(): BelongsToMany
    {
        return $this->belongsToMany(
            PhaseStage::class,
            'tbl_project_type_stages',
            'type_id',
            'stage_id',
            'type_id',
            'stage_id'
        );
    }

    /**
     * The default tasks this type contributes, across every stage it uses.
     */
    public function stageTasks(): HasMany
    {
        return $this->hasMany(ProjectTypeStageTask::class, 'type_id', 'type_id');
    }

    /**
     * Whether anybody has written a phase template for this type yet.
     *
     * A type without one is not broken - it simply contributes nothing to the
     * projects it is on, and a project whose types all answer false here falls
     * back to the built-in suggestion. See PhaseTemplateMerger.
     */
    public function hasPhaseTemplate(): bool
    {
        return $this->stages()->exists();
    }
}
