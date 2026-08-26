<?php

namespace App\Models;

use App\Services\SystemContentService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The settings the partial-day window is stored under.
     *
     * Configuration -> System Settings -> Project Settings. They bound a
     * PARTIAL-DAY booking and nothing else: a whole-day range runs from
     * midnight to the end of the day and has no hours to bound, so moving
     * these never touches one.
     */
    public const SETTING_PARTIAL_DAY_START = 'project_settings.partial_day_start_hour';

    public const SETTING_PARTIAL_DAY_END = 'project_settings.partial_day_end_hour';

    /**
     * The window the system ships with: 8:00 AM to 5:00 PM.
     *
     * The numbers it starts with, not the numbers it uses. Everything that
     * needs the window asks partialDayHourBounds() rather than reading these,
     * so an administrator's setting reaches every picker, every validator and
     * every availability check at once. These are what that method falls back
     * to on a fresh installation, and on one where the setting has been
     * cleared or written to something impossible.
     *
     * Stored as 'HH:MM' because that is what the setting itself holds and what
     * a time input hands back, so the shipped default and the saved value are
     * the same kind of thing.
     */
    public const DEFAULT_PARTIAL_DAY_START = '08:00';

    public const DEFAULT_PARTIAL_DAY_END = '17:00';

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
     * The same range, narrowed to the part of it that has not been worked yet
     * - or null when there is no such part.
     *
     * toAvailabilityRange() answers "when is this booking?", which is the
     * right question for a schedule being written or a conflict being
     * reviewed. It is the wrong question for staffing: asking whether somebody
     * may join a project's crew is a question about the work still to come,
     * and a range that finished last month is a record of what happened rather
     * than a claim on anybody's diary. Screened against it, a technician who
     * was busy back then reads as unavailable for a project whose only
     * remaining dates are weeks away.
     *
     * Two rules, and no more than two:
     *
     *   - A range that has completely ended - Schedule::isLocked(), the same
     *     line the editor, the calendar and the validator already draw - is
     *     dropped. It cannot make anybody unavailable for anything.
     *
     *   - A range that began in the past and is still running is kept, clamped
     *     to start today. Its remaining days are still a real claim, so they
     *     still have to be free; the days already worked are not, and would
     *     only refuse a technician over a week nobody can change.
     *
     * A partial day needs no clamping. It occupies one date, so it is either
     * already over - and dropped by the first rule - or entirely still to
     * come, hours and all, which is what keeps the time-based conflict rules
     * working exactly as they do everywhere else.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, mode: string}|null
     */
    public function toUpcomingAvailabilityRange(): ?array
    {
        if ($this->isLocked()) {
            return null;
        }

        $range = $this->toAvailabilityRange();

        if ($this->isPartialDay()) {
            return $range;
        }

        $today = self::businessToday();

        return [
            'start' => $range['start']->lt($today) ? $today : $range['start'],
            'end' => $range['end'],
            'mode' => $range['mode'],
        ];
    }

    /**
     * Every one of a project's ranges that still has days to come, in the
     * shape TechnicianAvailabilityService reads.
     *
     * Deliberately returns them ALL rather than merging them into one span:
     * a project running Aug 24-26 and again Sep 6-8 has to have both weeks
     * checked, and a technician free for one but not the other is not free for
     * the project. Merging would also invent a three-week booking out of two
     * short ones and refuse everybody in between.
     *
     * @param  iterable<int, Schedule>  $schedules
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode: string}>
     */
    public static function upcomingAvailabilityRanges(iterable $schedules): array
    {
        $ranges = [];

        foreach ($schedules as $schedule) {
            $range = $schedule->toUpcomingAvailabilityRange();

            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return $ranges;
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
    /**
     * Whether any part of this range falls on or before the given day.
     *
     * A range starting after a project's cancellation is days the job never
     * reached, and there is nothing of it to draw.
     */
    public function startsOnOrBefore(?CarbonImmutable $cutoff): bool
    {
        return $cutoff === null || $this->startsOn()->lte($cutoff);
    }

    /**
     * This range as a calendar event, stopped at the given day.
     *
     * A whole-day range that crosses the cutoff is drawn short: Aug 1 - Aug 10
     * on a project cancelled on the 4th is four days on the calendar, because
     * four days is what was worked. The stored row is untouched - it is the
     * record of what was booked, and the cutoff is a statement about what
     * happened, not a correction of what was agreed.
     *
     * A partial day occupies a single date, so it is drawn whole or not at
     * all: it cannot straddle a line it starts on the far side of.
     *
     * Null cutoff draws the range as it stands, which is every project bar a
     * cancelled one - see Project::calendarCutoff().
     *
     * @return array<string, mixed>
     */
    public function toCalendarTimesThrough(?CarbonImmutable $cutoff): array
    {
        $times = $this->toCalendarTimes();

        if ($cutoff === null || $this->isPartialDay() || $this->endsOn()->lte($cutoff)) {
            return $times;
        }

        // FullCalendar reads an all-day `end` as exclusive, which is why
        // toCalendarTimes() adds a day to it - so the last day drawn is the
        // cutoff itself.
        $times['end'] = $cutoff->addDay()->toDateString();

        return $times;
    }

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
     * The date format used to be a parameter because the screens that show
     * this did not agree on one. They do now - BusinessTime::DATE is the only
     * shape a date is ever shown in - so there is nothing left to pass.
     */
    public function describe(): string
    {
        $start = $this->startsOn()->format(BusinessTime::DATE);

        if ($this->isPartialDay()) {
            return $start.' · '.$this->timeRange();
        }

        $end = $this->endsOn()->format(BusinessTime::DATE);

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
     * The window a partial-day booking may be made in, as configured.
     *
     * The single place the hours are decided. Every picker, every validator,
     * every availability check and the two scheduling scripts read them from
     * here - through the payload the pages hand over - so changing the setting
     * changes all of them at once and none of them can drift.
     *
     * A pair that does not describe a window - unparseable, off the hour,
     * outside the clock, or an end at or before the start - falls back to the
     * shipped pair rather than being passed on. Validation stops such a pair
     * being saved; this stops one mattering if it ever gets in, which is the
     * same stance SystemContentService::number() takes for the numeric
     * settings. Both halves fall back together: half a configured window and
     * half a default one is a third window nobody chose.
     *
     * @return array{start: int, end: int, start_label: string, end_label: string}
     */
    public static function partialDayHourBounds(): array
    {
        $content = app(SystemContentService::class);

        $start = self::hourFromSetting($content->get(self::SETTING_PARTIAL_DAY_START, self::DEFAULT_PARTIAL_DAY_START));
        $end = self::hourFromSetting($content->get(self::SETTING_PARTIAL_DAY_END, self::DEFAULT_PARTIAL_DAY_END));

        if ($start === null || $end === null || $start >= $end) {
            $start = self::hourFromSetting(self::DEFAULT_PARTIAL_DAY_START);
            $end = self::hourFromSetting(self::DEFAULT_PARTIAL_DAY_END);
        }

        return [
            'start' => $start,
            'end' => $end,
            'start_label' => self::hourLabel($start),
            'end_label' => self::hourLabel($end),
        ];
    }

    /**
     * The first hour a partial day may start at.
     */
    public static function partialDayStartHour(): int
    {
        return self::partialDayHourBounds()['start'];
    }

    /**
     * The last hour a partial day may end at. Nothing may end after it.
     */
    public static function partialDayEndHour(): int
    {
        return self::partialDayHourBounds()['end'];
    }

    /**
     * An hour of the clock as the interface says it: 17 reads as "5:00 PM".
     */
    public static function hourLabel(int $hour): string
    {
        return CarbonImmutable::today()->setTime($hour, 0)->format('g:i A');
    }

    /**
     * 'HH:MM' as the hour it names, or null when it is not an hour of the
     * clock on the hour.
     *
     * Deliberately strict about the minutes: the whole of scheduling is hour
     * granular - the pickers offer whole hours, availability is counted in
     * whole-hour slots - so "08:30" is a mistake rather than half past eight.
     */
    private static function hourFromSetting(?string $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches)) {
            return null;
        }

        $hour = (int) $matches[1];

        return (int) $matches[2] === 0 && $hour >= 0 && $hour <= 23 ? $hour : null;
    }

    /**
     * The bookable hours, in the order they are offered: the configured start
     * through the configured end, on the hour.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function workingHourOptions(): array
    {
        ['start' => $start, 'end' => $end] = self::partialDayHourBounds();

        $options = [];

        for ($hour = $start; $hour <= $end; $hour++) {
            $options[] = [
                'value' => sprintf('%02d:00', $hour),
                'label' => self::hourLabel($hour),
            ];
        }

        return $options;
    }

    /**
     * Whether this booking's hours fall outside the window as it stands now.
     *
     * Nothing is wrong with such a booking - it was made under the window in
     * force at the time, and narrowing that window does not move work already
     * promised. It is worth being able to find, though, which is what this is
     * for: see needsHourCorrection(), the question the dashboard actually
     * asks.
     *
     * Whole-day ranges are never outside anything. They run midnight to
     * midnight and have no hours to bound.
     */
    public function isOutsidePartialDayHours(): bool
    {
        if (! $this->isPartialDay()) {
            return false;
        }

        ['start' => $windowStart, 'end' => $windowEnd] = self::partialDayHourBounds();

        $start = CarbonImmutable::parse($this->start_datetime);
        $end = CarbonImmutable::parse($this->end_datetime ?? $this->start_datetime);

        return $start->hour * 60 + $start->minute < $windowStart * 60
            || $end->hour * 60 + $end->minute > $windowEnd * 60;
    }

    /**
     * Whether somebody still needs to do something about those hours.
     *
     * Only work still to come. A partial day that has already been worked
     * outside the current window is the record of a day that happened, and
     * there is nothing to correct about it - putting it on a to-do list would
     * be asking for a booking to be rewritten after the fact, which is the one
     * thing the schedule lock exists to prevent.
     *
     * The single definition behind both the dashboard's count and the flag on
     * the row itself, so the two can never disagree about which bookings are
     * meant.
     */
    public function needsHourCorrection(): bool
    {
        return $this->isOutsidePartialDayHours()
            && $this->startsOn()->gte(self::businessToday());
    }

    /**
     * Partial-day bookings that have not happened yet - the set
     * needsHourCorrection() is then asked of.
     *
     * A coarse narrowing done in SQL, with the hour test left to PHP: the
     * comparison is stated once, in isOutsidePartialDayHours(), rather than a
     * second time in a dialect both MySQL and SQLite would have to agree on.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpcomingPartialDay(Builder $query): Builder
    {
        return $query
            ->where('scheduling_mode', self::MODE_PARTIAL_DAY)
            ->whereDate('start_datetime', '>=', self::businessToday()->toDateString());
    }

    /**
     * The bookable hours, widened to keep any hour a saved booking already
     * holds.
     *
     * Narrowing the window must not rewrite work that is already promised. A
     * range booked 8:00 AM - 12:00 PM under an eight-o'clock window is still
     * booked for those hours after the window moves to nine, so the row that
     * shows it has to be able to show 8:00 AM - a select whose value is not
     * among its options selects nothing, and saving the form would silently
     * move the booking.
     *
     * The extra hours come back flagged rather than blended in, so the row can
     * offer them as kept and not as choosable: the same stance the assigned
     * team takes towards a technician whose account was switched off, which
     * can be kept but never re-added.
     *
     * @return array<int, array{value: string, label: string, outside: bool}>
     */
    public static function workingHourOptionsIncluding(?string ...$times): array
    {
        $options = collect(self::workingHourOptions())
            ->map(fn (array $option): array => $option + ['outside' => false])
            ->keyBy('value');

        foreach ($times as $time) {
            $hour = self::hourFromSetting($time);

            if ($hour === null || $options->has(sprintf('%02d:00', $hour))) {
                continue;
            }

            $options->put(sprintf('%02d:00', $hour), [
                'value' => sprintf('%02d:00', $hour),
                'label' => self::hourLabel($hour),
                'outside' => true,
            ]);
        }

        return $options->sortKeys()->values()->all();
    }

    /**
     * Whether 'HH:MM' names an hour of the clock, on the hour.
     *
     * Separate from isWorkingHour() because the two rules have different
     * reach. Nothing anywhere may book half past eight - the pickers offer
     * whole hours and availability is counted in whole-hour slots - while
     * whether eight o'clock itself is bookable is a setting, and a booking
     * made before that setting changed still holds the hour it was given.
     */
    public static function isOnTheHour(?string $time): bool
    {
        return self::hourFromSetting($time) !== null;
    }

    /**
     * Whether 'HH:MM' is one of the bookable hours. Anything off the hour, or
     * outside the configured window, is not.
     */
    public static function isWorkingHour(?string $time): bool
    {
        $hour = self::hourFromSetting($time);

        if ($hour === null) {
            return false;
        }

        ['start' => $start, 'end' => $end] = self::partialDayHourBounds();

        return $hour >= $start && $hour <= $end;
    }
}
