<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stage of a project, and the work booked against it.
 *
 * A phase has no stored status column. Whether it is finished is
 * `completed_at`, and whether it is the one being worked on is decided by the
 * phases around it - see ProjectPhaseProgress, which is the only place that
 * question is answered. A status column would be a second copy of both facts,
 * and the first thing to fall out of step with them.
 */
class ProjectPhase extends Model
{
    protected $table = 'tbl_project_phases';

    protected $primaryKey = 'phase_id';

    /**
     * Finished. Every task on it is closed, or a Super Admin said to close it
     * anyway and wrote down why.
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * The earliest phase that is not finished: the one the project is on.
     * Exactly one phase of a live project carries this.
     */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * Everything after the current phase.
     */
    public const STATUS_NOT_STARTED = 'not_started';

    /**
     * An open phase on a project that will never work through it: cancelled,
     * or archived after being cancelled.
     *
     * Not the same as Not Started, which is a phase still to come. This one is
     * a phase that never will be, and calling it "Not Started" on a project
     * nobody is going back to reads as a promise. Completed projects do not
     * reach this - ProjectCompletion closes what is left open on them.
     */
    public const STATUS_NOT_COMPLETED = 'not_completed';

    /**
     * How each state reads, and the badge it is printed in - the same shape
     * Project::STATUS_TABS uses, so a phase badge and a project badge are
     * built the same way.
     *
     * @var array<string, array{label: string, badge: string}>
     */
    public const STATUSES = [
        self::STATUS_COMPLETED => ['label' => 'Completed', 'badge' => 'bg-success'],
        self::STATUS_IN_PROGRESS => ['label' => 'In Progress', 'badge' => 'bg-primary'],
        self::STATUS_NOT_STARTED => ['label' => 'Not Started', 'badge' => 'bg-secondary'],
        self::STATUS_NOT_COMPLETED => ['label' => 'Not Completed', 'badge' => 'bg-secondary'],
    ];

    /**
     * The structure a project is offered when its setup screen is opened for
     * the first time.
     *
     * A starting point, not a default that gets saved behind anybody's back:
     * the setup screen renders these as editable rows and nothing is written
     * until somebody presses Save or Finalize. Most jobs this company does
     * genuinely have these four stages, and a person who agrees with all four
     * should not have to type them out.
     *
     * @var array<int, array{title: string, description: string}>
     */
    public const SUGGESTED_PHASES = [
        ['title' => 'Site Preparation', 'description' => 'Prepare the work area before installation.'],
        ['title' => 'Installation', 'description' => 'Install the required equipment.'],
        ['title' => 'Testing', 'description' => 'Test the completed installation.'],
        ['title' => 'Final Inspection', 'description' => 'Perform the final inspection and verification.'],
    ];

    /**
     * How many phases a project may be given.
     *
     * The floor is one because a project with no phases has no structure to
     * monitor, and finalizing an empty structure would produce "0/0 Phases".
     * The ceiling is arbitrary and generous: it exists so a stuck key cannot
     * write two hundred rows, not because anybody has an opinion about 21.
     */
    public const MIN_PHASES = 1;

    public const MAX_PHASES = 20;

    protected $fillable = [
        'project_id',
        'stage_id',
        'sequence',
        'title',
        'description',
        'completed_at',
        'completed_by',
        'closed_with_project',
        'completion_override_reason',
        'completion_overridden_by',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'completed_at' => 'datetime',
        'closed_with_project' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'phase_id', 'phase_id');
    }

    /**
     * Tasks typed against this phase while the project is still being set up.
     *
     * Empty on every finalized project: finalization turns them into real tasks
     * and deletes them in the same transaction.
     */
    public function draftTasks(): HasMany
    {
        return $this->hasMany(ProjectPhaseDraftTask::class, 'phase_id', 'phase_id');
    }

    /**
     * The vocabulary stage this phase was suggested from, when it was
     * suggested from one at all.
     *
     * Provenance for the reports module. Nothing about how this phase behaves
     * or reads comes from here - the title and description on the row are what
     * everybody sees, and somebody edited them.
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PhaseStage::class, 'stage_id', 'stage_id');
    }

    /**
     * Who pressed Complete Phase. Null on a phase closed by the backfill,
     * which nobody pressed anything for.
     */
    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by', 'id');
    }

    public function completionOverriddenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_overridden_by', 'id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('sequence');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Whether the system closed this phase because the project finished,
     * rather than somebody pressing Complete Phase.
     *
     * What stops the panel crediting a person with a decision they never took:
     * a phase closed this way names nobody, and without this it would read the
     * same as one the backfill closed.
     */
    public function wasClosedWithProject(): bool
    {
        return (bool) $this->closed_with_project;
    }

    /**
     * Whether this phase was closed with work still outstanding on it.
     */
    public function completionWasOverridden(): bool
    {
        return $this->completion_overridden_by !== null
            || $this->completion_override_reason !== null;
    }

    /**
     * "Phase 2 - Installation", which is how a phase is named everywhere it is
     * referred to rather than displayed: the task dialogs' Phase select, the
     * activity log, a removal refusal.
     */
    public function label(): string
    {
        return sprintf('Phase %d - %s', $this->sequence, $this->title);
    }

    public function numberLabel(): string
    {
        return 'Phase '.$this->sequence;
    }
}
