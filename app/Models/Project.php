<?php

namespace App\Models;

use App\Support\DisplayCode;
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
     * The work is finished and the client has been asked to confirm it.
     *
     * Deliberately a status of its own rather than a flag on Completed. The
     * two mean different things and grant different powers: this one can be
     * reopened by an administrator, and Completed can never be.
     */
    public const STATUS_AWAITING_CLIENT_CONFIRMATION = 'awaiting_client_confirmation';

    /**
     * How long a client has to confirm before the system closes the project
     * for them, and when they are reminded that the clock is running.
     */
    public const COMPLETION_CONFIRMATION_DAYS = 7;

    public const COMPLETION_REMINDER_DAYS = 5;

    /**
     * How a project came to be Completed.
     *
     * Recorded rather than inferred: "the client agreed" and "nobody answered
     * for a week" are the same status and very different facts, and only the
     * stored method can tell them apart afterwards.
     */
    public const METHOD_CLIENT_CONFIRMED = 'client_confirmed';

    public const METHOD_AUTO_COMPLETED = 'auto_completed';

    /**
     * Statuses that count as "locked" / view-only records.
     *
     * Awaiting Client Confirmation is one of them, and that single line is
     * what locks the project everywhere: every controller guard, every policy
     * and every page already asks isReadOnly() rather than naming statuses of
     * its own. Reopening is the one action that looks past it, and it does so
     * by asking for this status by name.
     *
     * @var array<int, string>
     */
    public const READ_ONLY_STATUSES = [
        self::STATUS_AWAITING_CLIENT_CONFIRMATION,
        'completed',
        'cancelled',
        'archived',
    ];

    /**
     * Statuses that DO count as a scheduling conflict for a technician.
     * Everything else (unscheduled, on_hold, awaiting_client_confirmation,
     * completed, cancelled, archived) must be ignored by the technician
     * availability checker.
     *
     * Awaiting Client Confirmation is excluded on purpose. Asking for
     * completion is what releases the dates past the completion date, so the
     * crew is free from that moment - waiting on the client's reply must not
     * keep them booked.
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

    /**
     * The order statuses are reported in: roughly the order work moves
     * through, with the two endings last. Alphabetical would put Cancelled
     * first and Ongoing in the middle, which is no order at all.
     *
     * Archived is absent on purpose - it never appears in a report.
     *
     * @var array<int, string>
     */
    public const REPORT_STATUS_ORDER = [
        'unscheduled',
        self::STATUS_AWAITING_CLIENT_CONFIRMATION,
        'pending',
        'ongoing',
        'on_hold',
        'overdue',
        'cancelled',
        'completed',
    ];

    /**
     * Every status as a printed badge: background, then the ink that stays
     * legible on it.
     *
     * The backgrounds are the colours the application already uses - the
     * calendar's, the dashboard breakdown's, OVERDUE_COLOR - gathered in one
     * place so the pie, the badges and the exported PDF cannot drift into
     * three different colour systems. Only the ink is chosen here, and only
     * because a fill picked to sit behind white lettering is not always one
     * white lettering can sit on: amber and cyan need dark ink to stay
     * readable, which is the same call `bg-info text-dark` makes on screen.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const STATUS_COLORS = [
        'unscheduled' => ['#0dcaf0', '#053b45'],
        self::STATUS_AWAITING_CLIENT_CONFIRMATION => ['#6ea67f', '#0f2e1c'],
        'pending' => ['#f0ad4e', '#4a2c00'],
        'ongoing' => ['#0d6efd', '#ffffff'],
        'on_hold' => ['#6c757d', '#ffffff'],
        'overdue' => [self::OVERDUE_COLOR, '#4a2100'],
        'cancelled' => ['#dc3545', '#ffffff'],
        'completed' => ['#198754', '#ffffff'],
        'archived' => ['#212529', '#ffffff'],
    ];

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
        'completion_requested_at',
        'completion_requested_by',
        'completion_reminder_sent_at',
        'client_confirmed_at',
        'client_confirmed_by',
        'completion_method',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
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
        'completion_requested_at' => 'datetime',
        'completion_reminder_sent_at' => 'datetime',
        'client_confirmed_at' => 'datetime',
        'reopened_at' => 'datetime',
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
     * Whoever pressed Complete Project - a lead technician, an admin or a
     * super admin. Null on work completed before this workflow existed.
     */
    public function completionRequestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_requested_by', 'id');
    }

    /**
     * The client account that confirmed. Null when the seven days ran out and
     * the system closed the project instead - which is exactly the case
     * completion_method is there to distinguish.
     */
    public function clientConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_confirmed_by', 'id');
    }

    public function reopenedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by', 'id');
    }

    /**
     * How the project's key is printed, e.g. PROJ-0007.
     *
     * Not the same thing as reference_no, which the client quotes and which
     * carries the date the project was booked; this is the row's own id in a
     * form somebody can read back.
     */
    public function displayCode(): string
    {
        return DisplayCode::format(DisplayCode::PROJECT, $this->project_id);
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

    public function isAwaitingClientConfirmation(): bool
    {
        return $this->status === self::STATUS_AWAITING_CLIENT_CONFIRMATION;
    }

    /**
     * Whether the work is finished, whichever side of the client's reply the
     * project currently sits on.
     *
     * What the two states share is that the job is done - which is what the
     * projects tables, the client's own list and the reports group on. What
     * they do not share is whether anything can still be done about it, and
     * that question is isAwaitingClientConfirmation() / canBeReopened().
     */
    public function isWorkFinished(): bool
    {
        return $this->isCompleted() || $this->isAwaitingClientConfirmation();
    }

    /**
     * Only a project still waiting on its client may be reopened.
     *
     * A Completed project never can be, by either route into it: the client
     * agreed, or the seven days ran out and the system closed it. Either way
     * it is a historical record, and further work is a new project.
     */
    public function canBeReopened(): bool
    {
        return $this->isAwaitingClientConfirmation();
    }

    /**
     * The moment the system will complete this project if nobody answers.
     *
     * Measured from when completion was requested, never from the completion
     * date: work often finishes days before anybody records it, and the client
     * cannot be held to a clock that started before they were told.
     */
    public function confirmationDeadline(): ?CarbonImmutable
    {
        if (! $this->completion_requested_at) {
            return null;
        }

        return CarbonImmutable::parse($this->completion_requested_at)
            ->addDays(self::COMPLETION_CONFIRMATION_DAYS);
    }

    /**
     * Whole days left before the project completes itself, floored at zero -
     * a deadline that has passed but not yet been swept up reads as "0 days
     * left" rather than as a negative number.
     */
    public function confirmationDaysRemaining(): ?int
    {
        $deadline = $this->confirmationDeadline();

        if (! $deadline) {
            return null;
        }

        return max(0, (int) ceil(CarbonImmutable::now()->diffInDays($deadline, false)));
    }

    /**
     * How the confirmation window reads to the client: "3 days left", and the
     * two ends of the range spelled out rather than left to arithmetic.
     */
    public function confirmationCountdown(): ?string
    {
        $remaining = $this->confirmationDaysRemaining();

        if ($remaining === null) {
            return null;
        }

        return match (true) {
            $remaining === 0 => 'Less than a day left',
            $remaining === 1 => '1 day left',
            default => $remaining.' days left',
        };
    }

    /**
     * Whether this project has a completion report worth showing - which is
     * true from the moment completion is requested, not only once it is
     * confirmed. The client is being asked to review that report, so they have
     * to be able to read it first.
     */
    public function hasCompletionReport(): bool
    {
        return $this->completed_at !== null || filled($this->completion_summary);
    }

    /**
     * "Confirmed by the client" / "Completed automatically after 7 days", for
     * the project pages and the reports.
     */
    public function completionMethodLabel(): ?string
    {
        return match ($this->completion_method) {
            self::METHOD_CLIENT_CONFIRMED => 'Confirmed by the client',
            self::METHOD_AUTO_COMPLETED => sprintf(
                'Completed automatically after %d days without a reply',
                self::COMPLETION_CONFIRMATION_DAYS
            ),
            default => null,
        };
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
            'unscheduled' => 'Unscheduled',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            self::STATUS_AWAITING_CLIENT_CONFIRMATION => 'Awaiting Client Confirmation',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'archived' => 'Archived',
            default => ucfirst((string) $this->status),
        };
    }

    /**
     * The state this project reads as, as a key rather than a sentence.
     *
     * The same precedence statusLabel() uses - paused beats late, late beats
     * the stored status - so a label and a key never describe two different
     * things. Reports group, order and colour by this.
     */
    public function statusKey(): string
    {
        // A safety net rather than a real case: archived work is filtered out
        // of every report before this is asked. It is here so that a project
        // that somehow reaches a table cannot borrow another status's colour.
        if ($this->isArchived()) {
            return 'archived';
        }

        if ($this->on_hold) {
            return 'on_hold';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return (string) $this->status;
    }

    /**
     * The background and ink a status is printed with.
     *
     * @return array{0: string, 1: string}
     */
    public static function statusColor(string $key): array
    {
        return self::STATUS_COLORS[$key] ?? ['#6c757d', '#ffffff'];
    }

    /**
     * The same state in the few places a full sentence will not fit - a table
     * cell, a card header, a calendar tooltip.
     */
    public function shortStatusLabel(): string
    {
        if ($this->isAwaitingClientConfirmation() && ! $this->on_hold && ! $this->isOverdue()) {
            return 'Awaiting Confirmation';
        }

        return $this->statusLabel();
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
            'unscheduled' => 'bg-info text-dark',
            'pending' => 'bg-warning',
            'ongoing' => 'bg-primary',
            // A lighter green than Completed: the work is done, but the
            // project is not closed yet, and the two must not look identical
            // at a glance.
            self::STATUS_AWAITING_CLIENT_CONFIRMATION => 'bg-success-subtle text-success-emphasis border border-success-subtle',
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
            self::STATUS_AWAITING_CLIENT_CONFIRMATION => '#6ea67f',
            'completed' => '#198754',
            default => '#0d6efd',
        };
    }

    /**
     * The darker cut of each status colour, for use as ink rather than as a
     * fill.
     *
     * A colour chosen to sit BEHIND white lettering is the wrong colour to
     * write with. The fills above were picked on exactly that basis, and used
     * unchanged as an outline they come out weak - the amber especially, which
     * is close to invisible as a hairline on white. These are the same hues
     * taken down to roughly 5:1 against white, which is what an outlined
     * booking needs to read at a glance.
     *
     * @var array<string, string>
     */
    public const CALENDAR_INK = [
        'pending' => '#b26b00',
        'ongoing' => '#0a58ca',
        'overdue' => '#b45309',
        self::STATUS_AWAITING_CLIENT_CONFIRMATION => '#3f7d53',
        'completed' => '#146c43',
    ];

    /**
     * The colour this project's bookings are drawn WITH, as opposed to filled
     * with - see CALENDAR_INK.
     */
    public function calendarInkColor(): string
    {
        if ($this->isOverdue()) {
            return self::CALENDAR_INK['overdue'];
        }

        return self::CALENDAR_INK[$this->status] ?? self::CALENDAR_INK['ongoing'];
    }

    /**
     * How a booking is painted on a calendar.
     *
     * A whole-day booking is filled: it owns every hour of the days it
     * covers, and a solid bar says so at a glance. A partial day is outlined,
     * because it does not - it books a few hours and leaves the rest of that
     * date free, and a solid bar would claim the whole of it. The two are
     * different kinds of booking, so they are drawn as different kinds of
     * mark rather than as the same mark in different colours.
     *
     * Stated here rather than in each of the three calendars so they cannot
     * drift into looking like three different products.
     *
     * @param  bool  $filled  whether this booking occupies whole days
     * @return array{backgroundColor: string, borderColor: string, textColor: string}
     */
    public function calendarEventColors(bool $filled = false): array
    {
        $ink = $this->calendarInkColor();

        if ($filled) {
            return [
                'backgroundColor' => $ink,
                'borderColor' => $ink,
                // The ink colours sit at roughly 5:1 against white, so white
                // lettering on them is comfortably readable.
                'textColor' => '#ffffff',
            ];
        }

        return [
            // Transparent rather than white: the day cell behind it may be
            // tinted - today's cell is - and a white bar would punch a hole
            // in that tint.
            'backgroundColor' => 'transparent',
            'borderColor' => $ink,
            'textColor' => $ink,
        ];
    }

    /**
     * The calendar legend: what each colour means, in the order it is read.
     *
     * Stated here so the key and the bookings cannot disagree. It was written
     * out as four hard-coded hex values in the schedules page, which is how it
     * came to be missing Awaiting Client Confirmation entirely.
     *
     * @return array<int, array{label: string, colour: string}>
     */
    public static function calendarLegend(): array
    {
        return [
            ['label' => 'Pending', 'colour' => self::CALENDAR_INK['pending']],
            ['label' => 'Ongoing', 'colour' => self::CALENDAR_INK['ongoing']],
            ['label' => 'Overdue', 'colour' => self::CALENDAR_INK['overdue']],
            [
                'label' => 'Awaiting Confirmation',
                'colour' => self::CALENDAR_INK[self::STATUS_AWAITING_CLIENT_CONFIRMATION],
            ],
            ['label' => 'Completed', 'colour' => self::CALENDAR_INK['completed']],
        ];
    }

    /**
     * Cancelled and on-hold work is kept out of every calendar.
     *
     * A project awaiting confirmation stays on it. Its remaining dates are the
     * days the crew actually worked, and a calendar that quietly dropped them
     * the moment completion was requested would misreport what happened that
     * week - which is the same reason completed work is still drawn.
     */
    public function showsOnCalendar(): bool
    {
        return ! $this->isArchived()
            && ! $this->on_hold
            && $this->status !== 'cancelled';
    }
}
