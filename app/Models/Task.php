<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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
     * A task only reads as late while it is still open - finished work
     * cannot be late, however long it took.
     */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_date !== null
            && CarbonImmutable::parse($this->due_date)->startOfDay()->lt(CarbonImmutable::today());
    }

    /**
     * One place decides how a task's state reads, so every table, panel and
     * JSON payload agrees. Overdue wins over the underlying status, matching
     * how Project::statusLabel() behaves.
     */
    public function statusLabel(): string
    {
        if ($this->isOverdue()) {
            return 'Overdue';
        }

        return match ($this->status) {
            'unassigned' => 'Unassigned',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst((string) $this->status),
        };
    }

    /**
     * Bootstrap background class matching statusLabel().
     */
    public function statusBadgeClass(): string
    {
        if ($this->isOverdue()) {
            return 'badge-overdue';
        }

        return match ($this->status) {
            'unassigned' => 'bg-warning text-dark',
            'pending' => 'bg-secondary',
            'ongoing' => 'bg-primary',
            'completed' => 'bg-success',
            'cancelled' => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
