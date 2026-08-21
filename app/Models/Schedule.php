<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    /**
     * A whole-day booking. The technicians on it are occupied for every
     * calendar day from start_datetime to end_datetime, inclusive - which is
     * how every schedule in the system worked before partial days existed.
     */
    public const MODE_DATE_BASED = 'date_based';

    /**
     * An hours-only booking on a single date. The technicians on it are
     * occupied for those hours and free for the rest of that day.
     */
    public const MODE_PARTIAL_DAY = 'partial_day';

    /**
     * @var array<int, string>
     */
    public const SCHEDULING_MODES = [
        self::MODE_DATE_BASED,
        self::MODE_PARTIAL_DAY,
    ];

    /**
     * A range whose last day has gone by. History, not a promise.
     */
    public const LOCK_LOCKED = 'locked';

    /**
     * A range that started before today and has not finished. Part worked,
     * part still to come.
     */
    public const LOCK_ACTIVE = 'active';

    /**
     * A range that has not started. Entirely a promise, and entirely
     * changeable.
     */
    public const LOCK_FUTURE = 'future';

    /**
     * The company's timezone, used for scheduling only.
     *
     * The application itself runs on UTC and its timestamps are written that
     * way, so that is deliberately left alone. But a schedule is a promise
     * about the working day at the office: "8 AM" means 8 AM in Manila
     * whatever the server thinks the hour is. Every business-hours bound and
     * every "has this time already passed?" check goes through here.
     */
    public const BUSINESS_TIMEZONE = 'Asia/Manila';

    /** First hour of the working day, 8:00 AM. */
    public const WORKING_HOUR_START = 8;

    /** Last hour of the working day, 5:00 PM. Nothing may end after it. */
    public const WORKING_HOUR_END = 17;

    public $timestamps = false;

    protected $table = 'tbl_schedule';

    protected $primaryKey = 'schedule_id';

    protected $fillable = [
        'project_id',
        'start_datetime',
        'end_datetime',
        'scheduling_mode',
        'status',
        'remarks',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
    ];

    protected $attributes = [
        'scheduling_mode' => self::MODE_DATE_BASED,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function scheduleTechnicians(): HasMany
    {
        return $this->hasMany(ScheduleTechnician::class, 'schedule_id', 'schedule_id');
    }

    /**
     * A row written before scheduling modes existed has no mode recorded, and
     * every one of those is a whole-day range - so the absence of a mode reads
     * as date-based rather than as unknown.
     */
    public function isPartialDay(): bool
    {
        return $this->scheduling_mode === self::MODE_PARTIAL_DAY;
    }

    public function isDateBased(): bool
    {
        return ! $this->isPartialDay();
    }

    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start_datetime)->startOfDay();
    }

    /**
     * end_datetime is nullable, and a row without one is a single day.
     */
    public function endsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->end_datetime ?? $this->start_datetime)->startOfDay();
    }

    /**
     * Where this booking sits relative to today, which is what decides how
     * much of it may still be changed.
     *
     * The line is the one ScheduleHoldCutoff already draws: a range that has
     * ended is the record of work that happened, and a range still to come is
     * a promise that can be withdrawn. Answered here so the editor, the
     * calendar panel, the validator and the reports cannot disagree about
     * which of a project's ranges are settled.
     *
     * A partial day needs no special case. It occupies a single date, so it is
     * future until that date, active on it, and locked from the day after.
     */
    public function lockState(): string
    {
        $today = self::businessToday();

        if ($this->endsOn()->lt($today)) {
            return self::LOCK_LOCKED;
        }

        return $this->startsOn()->lt($today) ? self::LOCK_ACTIVE : self::LOCK_FUTURE;
    }

    /**
     * Whether this booking has ended. A locked range is view-only: it may not
     * be edited, deleted, or have a date taken off it, except by a Super Admin
     * who has confirmed the override - see ScheduleModeRules.
     */
    public function isLocked(): bool
    {
        return $this->lockState() === self::LOCK_LOCKED;
    }

    /**
     * Whether this booking is under way: it began before today and has not
     * finished. Its start is already worked and is frozen; its end may still
     * move, but never back past today.
     */
    public function isActive(): bool
    {
        return $this->lockState() === self::LOCK_ACTIVE;
    }

    /**
     * Whether this booking has not started yet, and is therefore entirely
     * changeable.
     */
    public function isFuture(): bool
    {
        return $this->lockState() === self::LOCK_FUTURE;
    }

    /**
     * Whether the given date may be taken off a schedule.
     *
     * Tomorrow onwards. A day already worked is part of the record, and today
     * is being worked - taking it away would discard a day the crew is on
     * site for. A Super Admin may still remove a past date through the
     * override; today is refused whoever is asking.
     */
    public static function dateIsRemovable(CarbonImmutable $date): bool
    {
        return $date->startOfDay()->gt(self::businessToday());
    }

    /**
     * Whether this row occupies exactly one calendar day. A partial day always
     * does; a date-based range does when its endpoints fall on the same date.
     *
     * This is the test that decides whether a date-based row may be converted
     * to a partial day: hours cannot be attached to a range covering several
     * days without inventing which of those days was meant.
     */
    public function spansSingleDay(): bool
    {
        return $this->startsOn()->equalTo($this->endsOn());
    }

    /**
     * This row expressed as a range TechnicianAvailabilityService understands,
     * so no caller has to work out for itself whether the stored times mean
     * anything.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, mode: string}
     */
    public function toAvailabilityRange(): array
    {
        if ($this->isPartialDay()) {
            return [
                'start' => CarbonImmutable::parse($this->start_datetime),
                'end' => CarbonImmutable::parse($this->end_datetime ?? $this->start_datetime),
                'mode' => self::MODE_PARTIAL_DAY,
            ];
        }

        return [
            'start' => $this->startsOn(),
            'end' => $this->endsOn(),
            'mode' => self::MODE_DATE_BASED,
        ];
    }

    /**
     * The actual stretch of time this row occupies, for comparing one
     * schedule against another directly.
     *
     * Distinct from toAvailabilityRange(), whose date-based end is a date the
     * availability service reads by day. This one runs to the end of that day,
     * so a whole-day row and a partial-day row on the same date are seen to
     * collide.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function occupiedInterval(): array
    {
        if ($this->isPartialDay()) {
            return [
                'start' => CarbonImmutable::parse($this->start_datetime),
                'end' => CarbonImmutable::parse($this->end_datetime ?? $this->start_datetime),
            ];
        }

        return [
            'start' => $this->startsOn(),
            'end' => $this->endsOn()->endOfDay(),
        ];
    }

    /**
     * How this row is handed to FullCalendar.
     *
     * A whole-day row stays an all-day event, whose end date FullCalendar
     * treats as exclusive - hence the extra day. A partial day becomes a timed
     * event, which is what makes the calendar print the hours on the bar
     * instead of hiding them in a tooltip.
     *
     * The timed values deliberately carry NO timezone offset. Schedules store
     * wall-clock time, and an offset would invite the browser to convert it -
     * turning an 8 AM booking into 4 PM for anyone sitting in Manila. Written
     * this way FullCalendar reads them as-is, which is what they mean.
     *
     * @return array{start: string, end: string, allDay: bool}
     */
    public function toCalendarTimes(): array
    {
        if ($this->isPartialDay()) {
            return [
                'start' => CarbonImmutable::parse($this->start_datetime)->format('Y-m-d\TH:i:s'),
                'end' => CarbonImmutable::parse($this->end_datetime ?? $this->start_datetime)
                    ->format('Y-m-d\TH:i:s'),
                'allDay' => false,
            ];
        }

        return [
            'start' => $this->startsOn()->toDateString(),
            'end' => $this->endsOn()->addDay()->toDateString(),
            'allDay' => true,
        ];
    }

    /**
     * "8:00 AM - 12:00 PM", or null for a whole-day row, whose stored times
     * are midnight-to-midnight padding rather than a booked window.
     */
    public function timeRange(): ?string
    {
        if (! $this->isPartialDay()) {
            return null;
        }

        return CarbonImmutable::parse($this->start_datetime)->format('g:i A')
            .' - '
            .CarbonImmutable::parse($this->end_datetime ?? $this->start_datetime)->format('g:i A');
    }

    /**
     * How this row reads to a person, in one place so the project page, the
     * technician portal, the reports and the calendars cannot disagree:
     *
     *   partial day    "Aug 6, 2026 · 8:00 AM - 12:00 PM"
     *   one day        "Aug 6, 2026"
     *   several days   "Aug 6, 2026 - Aug 9, 2026"
     *
     * The date format is a parameter because the screens that show this do not
     * all agree on one, and this is not the change that settles that.
     */
    public function describe(string $dateFormat = 'M j, Y'): string
    {
        $start = $this->startsOn()->format($dateFormat);

        if ($this->isPartialDay()) {
            return $start.' · '.$this->timeRange();
        }

        $end = $this->endsOn()->format($dateFormat);

        return $start === $end ? $start : $start.' - '.$end;
    }

    /**
     * Wall-clock "now" at the office, as a value that compares correctly
     * against what is stored.
     *
     * Schedules hold the wall-clock time the scheduler picked, carrying no
     * offset - the same way the date-only ranges have always been stored.
     * Measuring those against a UTC now() is what would put a 4 PM Manila
     * booking in the past at 8 AM UTC, so the office clock is read here and
     * handed back stripped of its zone, in the shape the stored values are in.
     */
    public static function businessNow(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            CarbonImmutable::now(self::BUSINESS_TIMEZONE)->format('Y-m-d H:i:s')
        );
    }

    /**
     * Today at the office. This is the "today" every scheduling date is
     * measured against, not the server's.
     */
    public static function businessToday(): CarbonImmutable
    {
        return self::businessNow()->startOfDay();
    }

    /**
     * The bookable hours, in the order they are offered:
     * 8:00 AM through 5:00 PM, on the hour.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function workingHourOptions(): array
    {
        $options = [];

        for ($hour = self::WORKING_HOUR_START; $hour <= self::WORKING_HOUR_END; $hour++) {
            $options[] = [
                'value' => sprintf('%02d:00', $hour),
                'label' => CarbonImmutable::today()->setTime($hour, 0)->format('g:i A'),
            ];
        }

        return $options;
    }

    /**
     * Whether 'HH:MM' is one of the bookable hours. Anything off the hour, or
     * outside 8 AM to 5 PM, is not.
     */
    public static function isWorkingHour(?string $time): bool
    {
        if (! is_string($time) || ! preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return false;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        return $minute === 0
            && $hour >= self::WORKING_HOUR_START
            && $hour <= self::WORKING_HOUR_END;
    }
}
