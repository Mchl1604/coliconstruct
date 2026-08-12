<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    protected $table = 'tbl_projects';

    protected $primaryKey = 'project_id';

    /**
     * Statuses that count as "locked" / view-only records.
     *
     * @var array<int, string>
     */
    public const READ_ONLY_STATUSES = ['completed', 'cancelled', 'archived'];

    /**
     * Statuses that DO count as a scheduling conflict for a technician.
     * Everything else (not_yet_scheduled, on_hold, completed, cancelled,
     * archived) must be ignored by the technician availability checker.
     *
     * @var array<int, string>
     */
    public const ACTIVE_PROJECT_STATUSES = ['pending', 'ongoing'];

    /**
     * Statuses a project can be in and still go overdue. A finished or
     * abandoned project can't be late, and a paused one is late on purpose.
     *
     * @var array<int, string>
     */
    public const OVERDUE_CANDIDATE_STATUSES = ['pending', 'ongoing'];

    /**
     * Orange, reserved for overdue. Bootstrap has no orange background
     * utility, so `badge-overdue` is defined in superAdminNav.css.
     */
    public const OVERDUE_COLOR = '#fd7e14';

    protected $fillable = [
        'reference_no',
        'name',
        'status',
        'quotation',
        'address',
        'description',
        'on_hold',
        'is_archived',
        'completed_at',
        'completion_summary',
        'completion_remarks',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_remarks',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'quotation' => 'decimal:2',
        'on_hold' => 'boolean',
        'is_archived' => 'boolean',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'project_id', 'project_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id', 'project_id');
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(Schedule::class, 'project_id', 'project_id')
            ->orderBy('start_datetime');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'project_id', 'project_id')
            ->orderBy('start_datetime');
    }

    public function projectTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectType::class,
            'tbl_project_type_map',
            'project_id',
            'type_id',
            'project_id',
            'type_id'
        );
    }

    public function projectTechnicians(): HasMany
    {
        return $this->hasMany(ProjectTechnician::class, 'project_id', 'project_id');
    }

    public function completionPhotos(): HasMany
    {
        return $this->hasMany(ProjectCompletionPhoto::class, 'project_id', 'project_id');
    }

    /**
     * Progress and incident reports filed by the technicians on site. This is
     * what a client follows a project by.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(TechnicianReport::class, 'project_id', 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id', 'project_id');
    }

    public function archivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'id');
    }

    /**
     * Residential or Commercial, as recorded on the project's client.
     *
     * There is no type column on the project itself: the distinction is set
     * once when the wizard writes the client row and is never editable
     * afterwards. Callers that ask this in a loop should eager load `clients`,
     * as the projects and schedules listings already do.
     */
    public function clientType(): ?string
    {
        return $this->clients->first()?->client_type;
    }

    /**
     * Partial-day scheduling is offered on Residential work only. Commercial
     * projects keep the whole-day workflow they have always had.
     */
    public function isResidential(): bool
    {
        return mb_strtolower(trim((string) $this->clientType())) === 'residential';
    }

    /**
     * Whether this project is locked for editing (historical record).
     */
    public function isReadOnly(): bool
    {
        return in_array($this->status, self::READ_ONLY_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived' || (bool) $this->is_archived;
    }

    /**
     * The last day the project is scheduled for, across every date range.
     */
    public function scheduleEndsOn(): ?CarbonImmutable
    {
        $end = $this->schedules->max('end_datetime');

        return $end ? CarbonImmutable::parse($end)->startOfDay() : null;
    }

    /**
     * Overdue means the project should have finished by now: its last
     * scheduled day has passed but it is still open.
     *
     * Derived, never stored - a project stops being overdue the moment its
     * schedule is extended or it is completed, with nothing to migrate.
     */
    public function isOverdue(): bool
    {
        if ($this->isReadOnly() || $this->isArchived() || $this->on_hold) {
            return false;
        }

        if (! in_array($this->status, self::OVERDUE_CANDIDATE_STATUSES, true)) {
            return false;
        }

        $endsOn = $this->scheduleEndsOn();

        return $endsOn !== null && $endsOn->lt(CarbonImmutable::today());
    }

    /**
     * Overdue projects, resolved in SQL for lists and counts.
     *
     * "Has schedules, but none of them reach today" is the same thing as
     * "the latest end date is in the past", without a subquery.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::OVERDUE_CANDIDATE_STATUSES)
            ->where('is_archived', false)
            ->where(function (Builder $holdQuery): void {
                $holdQuery->where('on_hold', false)->orWhereNull('on_hold');
            })
            ->whereHas('schedules')
            ->whereDoesntHave('schedules', function (Builder $scheduleQuery): void {
                $scheduleQuery->whereDate('end_datetime', '>=', CarbonImmutable::today()->toDateString());
            });
    }

    /**
     * One place decides how a project's state reads, so the projects table,
     * the tasks table, the calendars and the JSON payloads never disagree.
     */
    public function statusLabel(): string
    {
        if ($this->on_hold) {
            return 'On Hold';
        }

        if ($this->isOverdue()) {
            return 'Overdue';
        }

        return match ($this->status) {
            'not_yet_scheduled' => 'Not Yet Scheduled',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'archived' => 'Archived',
            default => ucfirst((string) $this->status),
        };
    }

    /**
     * Bootstrap background class matching statusLabel().
     */
    public function statusBadgeClass(): string
    {
        if ($this->on_hold) {
            return 'bg-secondary';
        }

        if ($this->isOverdue()) {
            return 'badge-overdue';
        }

        return match ($this->status) {
            'not_yet_scheduled' => 'bg-info text-dark',
            'pending' => 'bg-warning',
            'ongoing' => 'bg-primary',
            'completed' => 'bg-success',
            'cancelled' => 'bg-danger',
            'archived' => 'bg-dark',
            default => 'bg-secondary',
        };
    }

    /**
     * Colour for this project's calendar events.
     */
    public function calendarColor(): string
    {
        if ($this->isOverdue()) {
            return self::OVERDUE_COLOR;
        }

        return match ($this->status) {
            'pending' => '#f0ad4e',
            'ongoing' => '#0d6efd',
            'completed' => '#198754',
            default => '#0d6efd',
        };
    }

    /**
     * Cancelled and on-hold work is kept out of every calendar.
     */
    public function showsOnCalendar(): bool
    {
        return ! $this->isArchived()
            && ! $this->on_hold
            && $this->status !== 'cancelled';
    }
}
