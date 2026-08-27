<?php

namespace App\Models;

use App\Support\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $table = 'tbl_tasks';

    protected $primaryKey = 'task_id';

    /**
     * Statuses that still owe work, and therefore keep a project open.
     *
     * @var array<int, string>
     */
    public const OPEN_STATUSES = ['unassigned', 'pending', 'ongoing'];

    /**
     * Open work that somebody is actually holding.
     *
     * Narrower than OPEN_STATUSES, which includes unassigned: a task nobody
     * owns is still outstanding work on a project, but it is not a load on any
     * technician. This is what the "N Active Tasks" figure counts.
     *
     * @var array<int, string>
     */
    public const ACTIVE_STATUSES = ['pending', 'ongoing'];

    /**
     * A task nobody has been given.
     */
    public const GAP_TECHNICIAN = 'technician';

    /**
     * A task with an owner but no dates to do it between.
     */
    public const GAP_DATE = 'date';

    /**
     * Neither.
     */
    public const GAP_BOTH = 'both';

    /**
     * How each gap reads wherever it is printed - the row badge, the alert
     * chips, the dashboard.
     *
     * Named for what is actually wrong rather than all being called
     * "Unassigned": a task with an owner and no dates is not unassigned, and
     * telling somebody it is sends them to fix the wrong field.
     *
     * @var array<string, string>
     */
    public const GAP_LABELS = [
        self::GAP_TECHNICIAN => 'Missing Technician',
        self::GAP_DATE => 'Missing Date',
        self::GAP_BOTH => 'Missing Technician & Date',
    ];

    protected $fillable = [
        'project_id',
        'technician_id',
        'task_title',
        'task_description',
        'start_date',
        'due_date',
        'status',
        'completion_notes',
        'completed_at',
        'completed_by',
    ];

    /**
     * start_date and due_date are deliberately left uncast: the task forms
     * render them straight into date inputs, which need the raw 'Y-m-d'
     * string rather than a stringified Carbon instance.
     */
    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(TaskImage::class, 'task_id', 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    /**
     * The account that closed the task, which is not always the technician
     * holding it - an administrator or the project's lead may close it on
     * their behalf.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by', 'id');
    }

    /**
     * Narrow a task query to what this account is allowed to see.
     *
     * A plain technician's task board is their own work and nothing else: not
     * a colleague's task on the same project, and not one nobody has been
     * given yet. Everybody else - a lead running the board, an administrator,
     * the office - reads the whole board, so the scope adds nothing for them.
     *
     * Stated as a scope rather than repeated in each controller action so the
     * page, the JSON the schedule panel reads and the file routes are narrowed
     * by one rule: a technician cannot reach another technician's task by
     * asking a different endpoint for it.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null || ! $user->isTechnician()) {
            return $query;
        }

        $technicianId = $user->technicianId();

        // No technician record means no tasks of their own, and therefore
        // nothing to show - never the whole board.
        return $technicianId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('technician_id', $technicianId);
    }

    /**
     * Open work that cannot proceed because it is incomplete: nobody holds it,
     * it has no dates, or neither.
     *
     * THE rule. Both portals count and list from this one scope and then apply
     * their own permission filter on top - the Super Admin dashboard over
     * every project, a lead over the projects they are on - so the two can
     * never disagree about what counts as needing assignment.
     *
     * Narrowed to live, workable projects for the same reason the figure is
     * useful at all: it is a to-do list. A finished or cancelled project owes
     * nothing, an archived one is out of the way, and a project on hold has
     * been stopped deliberately - its tasks are refused edits until it
     * resumes (see TaskController), so listing them would be an alert nobody
     * is allowed to clear.
     *
     * Closed tasks are excluded by OPEN_STATUSES: a completed task that was
     * never given dates is a record of work that happened, not a job waiting
     * to be arranged.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsAssignment(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereHas('project', fn (Builder $project): Builder => $project
                ->whereIn('status', Project::ACTIVE_PROJECT_STATUSES)
                ->where('is_archived', false)
                ->where(fn (Builder $paused) => $paused->where('on_hold', false)->orWhereNull('on_hold')))
            ->where(fn (Builder $gap) => $gap
                ->whereNull('technician_id')
                ->orWhereNull('start_date')
                ->orWhereNull('due_date'));
    }

    /**
     * Narrow to one kind of gap. Layered on top of needsAssignment() rather
     * than standing on its own, so a caller cannot ask for "missing date" and
     * quietly get closed tasks on archived projects.
     *
     * An unrecognised value - including the "all" the alert chips use for no
     * narrowing at all - leaves the query alone.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithAssignmentGap(Builder $query, ?string $gap): Builder
    {
        $undated = fn (Builder $dates) => $dates->whereNull('start_date')->orWhereNull('due_date');

        return match ($gap) {
            self::GAP_TECHNICIAN => $query
                ->whereNull('technician_id')
                ->whereNotNull('start_date')
                ->whereNotNull('due_date'),
            self::GAP_DATE => $query
                ->whereNotNull('technician_id')
                ->where($undated),
            self::GAP_BOTH => $query
                ->whereNull('technician_id')
                ->where($undated),
            default => $query,
        };
    }

    /**
     * Nobody is holding this task.
     */
    public function missingTechnician(): bool
    {
        return $this->technician_id === null;
    }

    /**
     * A task is done between two days, so either one missing leaves it
     * without a date to work to.
     */
    public function missingDate(): bool
    {
        return $this->start_date === null || $this->due_date === null;
    }

    /**
     * Which gap this task has, or null when there is nothing wrong with it.
     *
     * The row-level twin of scopeNeedsAssignment(), and gated on isOpen() for
     * the same reason: closed work is a record, not a backlog. It deliberately
     * does not ask about the project - a task is only ever rendered on a page
     * that has already decided which projects belong there.
     */
    public function assignmentGap(): ?string
    {
        if (! $this->isOpen()) {
            return null;
        }

        return match (true) {
            $this->missingTechnician() && $this->missingDate() => self::GAP_BOTH,
            $this->missingTechnician() => self::GAP_TECHNICIAN,
            $this->missingDate() => self::GAP_DATE,
            default => null,
        };
    }

    /**
     * "Missing Technician", "Missing Date", "Missing Technician & Date", or
     * null when the task is complete enough to proceed.
     */
    public function assignmentGapLabel(): ?string
    {
        return self::GAP_LABELS[$this->assignmentGap()] ?? null;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Whether this account is the technician the task is assigned to.
     *
     * The one question that decides both who may close a task without being
     * asked for notes, and how the completion panel reads afterwards.
     */
    public function isAssignedTo(?User $user): bool
    {
        if ($user === null || $this->technician_id === null) {
            return false;
        }

        $technicianId = $user->technicianId();

        return $technicianId !== null && (int) $this->technician_id === $technicianId;
    }

    /**
     * Closed by somebody other than the technician who held it. Those closures
     * are allowed to arrive with no notes and no photos.
     */
    public function wasClosedOnBehalf(): bool
    {
        return $this->isCompleted()
            && $this->completed_by !== null
            && ! $this->isAssignedTo($this->completedBy);
    }

    /**
     * Still owes work. Cancelled tasks are closed, not open.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * The state this task is actually in: 'pending', 'overdue',
     * 'finished_late' and the rest.
     *
     * Derived from the stored status, the due date and the completion instant
     * rather than read off the status column - see TaskStatus, which is the
     * one place that decides it for every portal, page, modal and report. The
     * column itself is never rewritten to suit a display.
     */
    public function derivedStatus(): string
    {
        return TaskStatus::for($this);
    }

    /**
     * Unfinished, with the due date behind us. Becomes true on its own as the
     * office date rolls over; nobody has to set it.
     */
    public function isOverdue(): bool
    {
        return TaskStatus::overdue($this);
    }

    /**
     * Finished, but after the deadline. Still a completion - isCompleted() is
     * true for these - and deliberately told apart from one that landed on
     * time.
     */
    public function wasFinishedLate(): bool
    {
        return TaskStatus::finishedLate($this);
    }

    /**
     * How the state reads: "Pending", "Overdue", "Finished Late".
     */
    public function statusLabel(): string
    {
        return TaskStatus::label($this);
    }

    /**
     * The badge class matching statusLabel().
     */
    public function statusBadgeClass(): string
    {
        return TaskStatus::badgeClass($this);
    }

    /**
     * The derived state as JSON, for the panels drawn in the browser.
     *
     * @return array{status: string, status_key: string, status_label: string, status_badge_class: string}
     */
    public function statusPayload(): array
    {
        return TaskStatus::payload($this);
    }
}
