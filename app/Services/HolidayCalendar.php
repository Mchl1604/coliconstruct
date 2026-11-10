<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Project;
use App\Models\Schedule;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

/**
 * The days nobody works, kept by the Super Admin in Configuration.
 *
 * A booking may not start or end on one - ScheduleModeRules refuses that - and a
 * range running across one simply leaves it out: skip() splits the saved row
 * around it, through the same ScheduleDateRemoval a date taken off the calendar
 * goes through, so the crew booked on the row stay booked on both halves.
 *
 * A holiday added later splits the bookings already running across it the
 * same way - see splitBookingsOn() - from tomorrow on only, so no day already
 * worked is rewritten.
 */
class HolidayCalendar
{
    public function __construct(
        private readonly ScheduleDateRemoval $removal,
        private readonly TaskScheduleRules $taskScheduleRules,
        private readonly ProjectStatusRules $statusRules,
    ) {}

    public function nameOf(CarbonImmutable $date): ?string
    {
        return Holiday::query()->whereDate('holiday_date', $date->toDateString())->value('name');
    }

    /**
     * The refusal for a booking that would start or end on a holiday, or null.
     */
    public function refusalFor(CarbonImmutable $date): ?string
    {
        $name = $this->nameOf($date);

        return $name === null
            ? null
            : sprintf('%s is a non-working day: %s.', $date->format(BusinessTime::DATE), $name);
    }

    /**
     * Every non-working day, date => name, for the calendars and pickers to
     * mark - past ones too, because a calendar can be paged back to them.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return Holiday::query()
            ->orderBy('holiday_date')
            ->get(['holiday_date', 'name'])
            ->mapWithKeys(fn (Holiday $holiday): array => [$holiday->holiday_date->toDateString() => $holiday->name])
            ->all();
    }

    /**
     * Leave the holidays out of a whole-day booking just saved.
     *
     * Last first, so the row in hand stays the head of what is left to split.
     */
    public function skip(Schedule $schedule): void
    {
        if ($schedule->isPartialDay()) {
            return;
        }

        $dates = Holiday::query()
            ->whereDate('holiday_date', '>=', $schedule->startsOn()->toDateString())
            ->whereDate('holiday_date', '<=', $schedule->endsOn()->toDateString())
            ->orderByDesc('holiday_date')
            ->pluck('holiday_date');

        foreach ($dates as $date) {
            $this->removal->remove($schedule, CarbonImmutable::parse($date));
            $schedule->refresh();
        }
    }

    /**
     * Leave a non-working day out of the whole-day ranges already booked across
     * it, the way skip() does for a booking being saved.
     *
     * Only a range that keeps a day either side is touched: a booking for that
     * day alone, or hours on it, is left for the office to move rather than
     * deleted. Past days and today are kept as they are, like any removal.
     * Open tasks left without a booked day are undated, and each project's
     * status follows its dates - the same as taking a date off by hand.
     *
     * @return int the bookings split or shortened
     */
    public function splitBookingsOn(CarbonImmutable $date): int
    {
        $day = $date->startOfDay();

        if (! Schedule::dateIsRemovable($day)) {
            return 0;
        }

        $schedules = Schedule::query()
            ->occupying()
            ->where('scheduling_mode', '!=', Schedule::MODE_PARTIAL_DAY)
            ->whereDate('start_datetime', '<=', $day->toDateString())
            ->whereDate('end_datetime', '>=', $day->toDateString())
            ->whereRaw('date(start_datetime) <> date(end_datetime)')
            ->get();

        foreach ($schedules as $schedule) {
            $this->removal->remove($schedule, $day);
        }

        Project::query()
            ->whereIn('project_id', $schedules->pluck('project_id')->unique())
            ->get()
            ->each(function (Project $project): void {
                $this->taskScheduleRules->unassignStrandedDates((int) $project->project_id);
                $this->statusRules->apply($project);
            });

        return $schedules->count();
    }
}
