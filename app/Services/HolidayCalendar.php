<?php

namespace App\Services;

use App\Models\Holiday;
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
 * Only what is being booked now is touched. Adding a holiday moves nothing
 * already on the calendar.
 */
class HolidayCalendar
{
    public function __construct(private readonly ScheduleDateRemoval $removal) {}

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
}
