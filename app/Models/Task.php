<?php

namespace App\Models;

use App\Support\TaskStatus;
use Carbon\CarbonImmutable;
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
     * An owner and dates, but the owner has a day off inside them: a day the
     * project is scheduled to work that they are not on its team for. A gap in
     * the team over days nobody works is not one. See holderHasDayOffInDates().
     *
     * Usually the trace of a technician taken off the project, for some days
     * or for good, while the task stayed theirs, or of dates moved after the
     * task was given out. Derived like the others, so it clears the moment the
     * task or the team is put right.
     */
    public const GAP_OFF_TEAM = 'off_team';

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
        self::GAP_OFF_TEAM => 'Technician Not Assigned for Dates',
    ];

    /**
     * Why a task that has not reached its start date is refused, in the one
     * sentence every surface says it in: the button's tooltip, the schedule
     * panel, and the response to a request that arrived without one.
     */
    public const NOT_STARTED_REFUSAL = 'This task cannot be completed before its start date.';

    protected $fillable = [
        'project_id',
        'phase_id',
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

    /**
     * The stage of the project this task belongs to.
     *
     * Required of every task created since project phases arrived, and the
     * create dialogs refuse to submit without it. Still nullable, because the
     * tasks that predate phases were placed by a backfill rather than by
     * anybody choosing - and because a task whose phase somehow went missing
     * should be visible and fixable rather than deleted. See the migration
     * that adds the column.
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id', 'phase_id');
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
                ->orWhereNull('due_date')
                ->orWhere(fn (Builder $offTeam) => $this->holderHasDayOffInDatesScope($offTeam)));
    }

    /**
     * Held, dated, and the holder has a day off inside those dates - see
     * holderDayOffSql().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    private function holderHasDayOffInDatesScope(Builder $query): Builder
    {
        return $query->whereRaw(self::holderDayOffSql());
    }

    /**
     * The day-off rule as one SQL condition on a tbl_tasks row, so the row
     * badge, the attention chips and the dashboard count all ask the same
     * question.
     *
     * A day off is a day from the task's start to its due date that the
     * project is scheduled to work (any day, for a project with no schedule
     * yet) and that no span of the holder's on the project covers. Days
     * cannot be listed in SQL, but the first day of any such run of days is
     * always one of three: the task's own start, the first day of one of the
     * project's schedules, or the day one of the holder's spans ends. So only
     * those are asked.
     */
    private static function holderDayOffSql(): string
    {
        $scheduled = fn (string $day, string $alias): string => "(not exists (select 1 from tbl_schedule {$alias}_any "
            ."where {$alias}_any.project_id = tbl_tasks.project_id) "
            ."or exists (select 1 from tbl_schedule {$alias}_on "
            ."where {$alias}_on.project_id = tbl_tasks.project_id "
            ."and date({$alias}_on.start_datetime) <= {$day} "
            ."and date(coalesce({$alias}_on.end_datetime, {$alias}_on.start_datetime)) >= {$day}))";

        $covered = fn (string $day, string $alias): string => "exists (select 1 from tbl_project_technicians {$alias}_span "
            ."where {$alias}_span.project_id = tbl_tasks.project_id "
            ."and {$alias}_span.technician_id = tbl_tasks.technician_id "
            ."and ({$alias}_span.joined_at is null or date({$alias}_span.joined_at) <= {$day}) "
            ."and ({$alias}_span.removed_at is null or date({$alias}_span.removed_at) > {$day}))";

        $start = 'date(tbl_tasks.start_date)';
        $due = 'date(tbl_tasks.due_date)';

        return 'tbl_tasks.technician_id is not null and tbl_tasks.start_date is not null and tbl_tasks.due_date is not null and ('
            // The task's first day.
            .'('.$scheduled($start, 'first').' and not '.$covered($start, 'first').')'
            // The first day of a schedule inside the task's dates.
            .' or exists (select 1 from tbl_schedule booked where booked.project_id = tbl_tasks.project_id '
            ."and date(booked.start_datetime) between {$start} and {$due} "
            .'and not '.$covered('date(booked.start_datetime)', 'booked').')'
            // The day one of the holder's spans ends.
            .' or exists (select 1 from tbl_project_technicians ended where ended.project_id = tbl_tasks.project_id '
            .'and ended.technician_id = tbl_tasks.technician_id and ended.removed_at is not null '
            ."and date(ended.removed_at) between {$start} and {$due} "
            .'and '.$scheduled('date(ended.removed_at)', 'ended')
            .' and not '.$covered('date(ended.removed_at)', 'ended').')'
            .')';
    }

    /**
     * Load, alongside each task, whether its holder has a day off inside its
     * dates - so a board of tasks can flag them without a query per row. See
     * holderHasDayOffInDates().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithHolderCoverage(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('tbl_tasks.*');
        }

        return $query->selectRaw('case when '.self::holderDayOffSql().' then 1 else 0 end as holder_has_day_off');
    }

    /**
     * Whether whoever holds this task has a day off inside its dates: a day the
     * project is scheduled to work on (any day, for a project with no schedule
     * yet) that they are not on its team for. A day nobody works is not a day
     * off, so a gap in the team over unscheduled dates does not count. False
     * when there is nobody or no dates to measure - those are the other gaps.
     */
    public function holderHasDayOffInDates(): bool
    {
        if ($this->technician_id === null || $this->start_date === null || $this->due_date === null) {
            return false;
        }

        if (array_key_exists('holder_has_day_off', $this->attributes)) {
            return (bool) $this->attributes['holder_has_day_off'];
        }

        $project = $this->project()->with('schedules')->first();

        $spans = ProjectTechnician::query()
            ->where('project_id', $this->project_id)
            ->where('technician_id', $this->technician_id)
            ->get();

        $day = CarbonImmutable::parse($this->start_date)->startOfDay();
        $due = CarbonImmutable::parse($this->due_date)->startOfDay();

        for (; $day->lte($due); $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($project && $project->schedules->isNotEmpty() && ! $project->isScheduledOn($date)) {
                continue;
            }

            if (! $spans->contains(fn (ProjectTechnician $span): bool => $span->coversPeriod($date, $date))) {
                return true;
            }
        }

        return false;
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
            self::GAP_OFF_TEAM => $this->holderHasDayOffInDatesScope($query),
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
            $this->holderHasDayOffInDates() => self::GAP_OFF_TEAM,
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
     * The work is not due to begin yet, so there is nothing that could have
     * been finished.
     *
     * Becomes false on its own as the office date rolls over, exactly as
     * isOverdue() becomes true - see TaskStatus, which decides both against
     * the same clock. A task with no start date is never early.
     */
    public function startsInFuture(): bool
    {
        return TaskStatus::startsInFuture($this);
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
