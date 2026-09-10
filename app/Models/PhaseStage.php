<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stage in the company's vocabulary of project stages.
 *
 * Read the table's own migration for why this is a shared catalogue when
 * tbl_project_phases pointedly is not. The short version: it is what lets two
 * project types on one project agree that they mean the same Site Preparation,
 * and it is the only place the order of stages is stated.
 *
 * A stage is not a phase. A phase is a row on a project, copied from a stage
 * and editable forever afterwards.
 */
class PhaseStage extends Model
{
    protected $table = 'tbl_phase_stages';

    protected $primaryKey = 'stage_id';

    /**
     * The gap left between one stage's sort_order and the next.
     *
     * Ten rather than one so a stage can be inserted between two existing ones
     * by picking a number in the gap, instead of rewriting every row after it.
     */
    public const SORT_STEP = 10;

    /**
     * What the vocabulary is seeded with: the four stages
     * ProjectPhase::SUGGESTED_PHASES has always offered.
     *
     * Kept here rather than read out of that constant because the two are
     * different things that happen to agree today - this is the starting
     * catalogue, editable the moment the page loads, and that is the hardcoded
     * fallback for a project whose types have no template at all.
     *
     * @var array<int, array{name: string, default_description: string}>
     */
    public const STARTING_VOCABULARY = [
        ['name' => 'Site Preparation', 'default_description' => 'Prepare the work area before installation.'],
        ['name' => 'Installation', 'default_description' => 'Install the required equipment.'],
        ['name' => 'Testing', 'default_description' => 'Test the completed installation.'],
        ['name' => 'Final Inspection', 'default_description' => 'Perform the final inspection and verification.'],
    ];

    protected $fillable = [
        'name',
        'default_description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * The types that use this stage.
     */
    public function projectTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectType::class,
            'tbl_project_type_stages',
            'stage_id',
            'type_id',
            'stage_id',
            'type_id'
        );
    }

    /**
     * Every type's default tasks for this stage, across all types.
     */
    public function stageTasks(): HasMany
    {
        return $this->hasMany(ProjectTypeStageTask::class, 'stage_id', 'stage_id');
    }

    /**
     * The order a merged structure comes out in. Ties broken by name so the
     * list is stable rather than left to the database's insertion order.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The sort_order a stage added to the end of the vocabulary should get.
     */
    public static function nextSortOrder(): int
    {
        return ((int) static::query()->max('sort_order')) + self::SORT_STEP;
    }
}
