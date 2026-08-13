<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Single source of truth for "can these technicians work this?".
 *
 * Every scheduling entry point (project creation, the schedules page, the
 * calendar, and the assigned-team editor) funnels through here so the rules
 * stay identical.
 *
 * A booking occupies a span of minutes on each of the days it touches. A
 * date-based booking takes the whole of every day from its start date through
 * its end date, which is how every schedule behaved before partial days
 * existed. A partial-day booking takes only its hours, on one date, leaving
 * the rest of that day free for other work.
 *
 * Two bookings clash when their occupied minutes actually overlap. Touching
 * endpoints do not: a technician finishing at 10:00 AM is free to start again
 * at 10:00 AM.
 *
 * The rule for a date-based request is unchanged and still continuous: the
 * range is only valid when every selected technician is free on EVERY calendar
 * day from the start date through the end date, inclusive. A range whose
 * endpoints happen to be free but which spans a busy day in the middle is
 * rejected.
 *
 * Callers describe what they want as ranges:
 *
 *   ['start' => CarbonImmutable, 'end' => CarbonImmutable]
 *       a whole-day range - every day from start to end
 *
 *   ['start' => CarbonImmutable, 'end' => CarbonImmutable,
 *    'mode' => Schedule::MODE_PARTIAL_DAY]
 *       an hours-only range - the times carried by start and end, on one date
 *
 * A range with no mode is whole-day, so callers written before partial days
 * existed keep asking, and keep being answered, exactly as they were.
 */
class TechnicianAvailabilityService
{
    /**
     * Safety valve so a nonsense range (a typo'd year, say) can never spin the
     * day-by-day loop for an unreasonable amount of time.
     */
    private const MAX_DAYS_PER_RANGE = 3660;

    private const MINUTES_PER_DAY = 1440;

    /**
     * Build a map of technician_id => set of unavailable 'Y-m-d' dates.
     *
     * A date counts as unavailable when the technician is occupied on it at
     * all, whether for the whole day or for part of it - which is the right
     * answer for the callers that book whole days.
     *
     * All rows are pulled in one query, eager loaded, and clamped to the
     * window actually being validated, so per-day checking happens purely in
     * memory. There is no query-per-date and no N+1.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     * @return array<int, array<string, true>>
     */
    public function unavailableDatesByTechnician(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId = null,
        array $excludeScheduleIds = []
    ): array {
        $owners = $this->unavailableDayOwners($technicianIds, $ranges, $excludeProjectId, $excludeScheduleIds);
        $unavailable = [];

        foreach ($owners as $technicianId => $days) {
            foreach ($days as $day => $projectIds) {
                $unavailable[(int) $technicianId][$day] = true;
            }
        }

        return $unavailable;
    }

    /**
     * Like unavailableDatesByTechnician(), but keeps track of WHICH project
     * booked each busy day.
     *
     * This is what makes bulk screening cheap: one query covers every
     * technician, and a caller can then ask "is this technician free for
     * project P?" by ignoring days that P itself booked - no per-project
     * query, no N+1.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     * @return array<int, array<string, array<int, true>>> technicianId => date => [projectId => true]
     */
    public function unavailableDayOwners(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId = null,
        array $excludeScheduleIds = []
    ): array {
        $intervals = $this->occupiedIntervals($technicianIds, $ranges, $excludeProjectId, $excludeScheduleIds);
        $owners = [];

        foreach ($intervals as $technicianId => $days) {
            foreach ($days as $day => $dayIntervals) {
                foreach ($dayIntervals as $interval) {
                    $owners[(int) $technicianId][$day][$interval['project_id']] = true;
                }
            }
        }

        return $owners;
    }

    /**
     * Which projects stand in the way, per technician, for the requested
     * window.
     *
     * This is what makes bulk screening cheap and correct at once: one query
     * covers every technician on every candidate project, and a caller decides
     * whether project P is free by ignoring P's own bookings. Unlike the
     * day-level map it compares actual times, so a technician booked for a
     * morning is still offered for an afternoon.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     * @return array<int, array<int, true>> technicianId => [projectId => true]
     */
    public function conflictingProjectsByTechnician(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId = null,
        array $excludeScheduleIds = []
    ): array {
        $requests = $this->normaliseRanges($ranges);
        $intervals = $this->occupiedIntervals($technicianIds, $ranges, $excludeProjectId, $excludeScheduleIds);

        $blockers = [];

        foreach ($intervals as $technicianId => $days) {
            foreach ($requests as $request) {
                foreach ($this->eachDate($request['start'], $request['end']) as $day) {
                    foreach ($days[$day] ?? [] as $interval) {
                        if ($this->overlaps($request, $interval)) {
                            $blockers[(int) $technicianId][$interval['project_id']] = true;
                        }
                    }
                }
            }
        }

        return $blockers;
    }

    /**
     * Walk every requested range and report which technicians are occupied
     * when, and by what.
     *
     * `dates` is the list of calendar days that clashed, which is what the
     * team picker reads. `busy` describes the clash on each of those days for
     * an hours-only request, so the message can name the hours rather than
     * only the date.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     * @return Collection<int, array{technician_id: int, technician_name: string, dates: array<int, string>, busy: array<string, array<int, string>>, partial: bool}>
     */
    public function findConflicts(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId = null,
        array $excludeScheduleIds = []
    ): Collection {
        $technicianIds = $this->normaliseTechnicianIds($technicianIds);

        if ($technicianIds->isEmpty() || $ranges === []) {
            return collect();
        }

        $requests = $this->normaliseRanges($ranges);

        if ($requests === []) {
            return collect();
        }

        $intervals = $this->occupiedIntervals($technicianIds, $ranges, $excludeProjectId, $excludeScheduleIds);

        if ($intervals === []) {
            return collect();
        }

        $conflicts = [];

        foreach ($technicianIds as $technicianId) {
            $busyDays = $intervals[$technicianId] ?? [];

            if ($busyDays === []) {
                continue;
            }

            $hits = [];
            $busy = [];
            // Only when every clash came from an hours-only request can the
            // message talk about times; otherwise it has to speak in days.
            $partialOnly = true;

            foreach ($requests as $request) {
                foreach ($this->eachDate($request['start'], $request['end']) as $day) {
                    foreach ($busyDays[$day] ?? [] as $interval) {
                        if (! $this->overlaps($request, $interval)) {
                            continue;
                        }

                        $hits[$day] = true;

                        if (! $request['partial']) {
                            $partialOnly = false;

                            continue;
                        }

                        $busy[$day][$this->describeInterval($interval)] = true;
                    }
                }
            }

            if ($hits === []) {
                continue;
            }

            $dates = array_keys($hits);
            sort($dates);

            $conflicts[] = [
                'technician_id' => $technicianId,
                'technician_name' => '',
                'dates' => $dates,
                'busy' => $this->tidyBusyLabels($busy),
                'partial' => $partialOnly && $busy !== [],
            ];
        }

        if ($conflicts === []) {
            return collect();
        }

        $names = $this->technicianNames(array_column($conflicts, 'technician_id'));

        return collect($conflicts)->map(function (array $conflict) use ($names): array {
            $conflict['technician_name'] = $names[$conflict['technician_id']] ?? 'A selected technician';

            return $conflict;
        })->values();
    }

    /**
     * Throw with a per-technician message when the request cannot be met.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     *
     * @throws RuntimeException
     */
    public function assertContinuouslyAvailable(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId = null,
        array $excludeScheduleIds = []
    ): void {
        $conflicts = $this->findConflicts($technicianIds, $ranges, $excludeProjectId, $excludeScheduleIds);

        if ($conflicts->isEmpty()) {
            return;
        }

        throw new RuntimeException($this->conflictMessage($conflicts));
    }

    /**
     * Turn conflicts into the user-facing sentence.
     *
     * An hours-only request is told which hours are taken; anything involving
     * whole days keeps the wording it has always had.
     *
     * @param  Collection<int, array{technician_id: int, technician_name: string, dates: array<int, string>, busy?: array<string, array<int, string>>, partial?: bool}>  $conflicts
     */
    public function conflictMessage(Collection $conflicts): string
    {
        if ($conflicts->isEmpty()) {
            return '';
        }

        $isTimed = fn (array $conflict): bool => ($conflict['partial'] ?? false)
            && ($conflict['busy'] ?? []) !== [];

        $parts = $conflicts->map(function (array $conflict) use ($isTimed): string {
            if ($isTimed($conflict)) {
                return sprintf(
                    'Technician %s is already booked %s.',
                    $conflict['technician_name'],
                    $this->describeBusy($conflict['busy'])
                );
            }

            return sprintf(
                'Technician %s is unavailable on %s.',
                $conflict['technician_name'],
                $this->formatDateList($conflict['dates'])
            );
        })->all();

        $closing = $conflicts->every($isTimed)
            ? ' Please choose a time when every selected technician is free.'
            : ' Please select a continuous date range where all selected technicians are available.';

        return implode(' ', $parts).$closing;
    }

    /**
     * "August 1 and August 2" - the same phrasing the conflict message uses,
     * for callers that want the dates without the surrounding sentence.
     *
     * @param  array<int, string>  $dates
     */
    public function describeDates(array $dates): string
    {
        return $this->formatDateList($dates);
    }

    /**
     * Every minute each technician is spoken for, inside the window the
     * request covers.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     * @return array<int, array<string, array<int, array{project_id: int, from: int, to: int}>>>
     */
    private function occupiedIntervals(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId,
        array $excludeScheduleIds
    ): array {
        $technicianIds = $this->normaliseTechnicianIds($technicianIds);
        $requests = $this->normaliseRanges($ranges);

        if ($technicianIds->isEmpty() || $requests === []) {
            return [];
        }

        $windowStart = collect($requests)->min(fn (array $request): string => $request['start']);
        $windowEnd = collect($requests)->max(fn (array $request): string => $request['end']);

        $busySchedules = $this->busySchedulesQuery(
            $technicianIds,
            $windowStart,
            $windowEnd,
            $excludeProjectId,
            $excludeScheduleIds
        );

        $intervals = [];

        foreach ($busySchedules as $schedule) {
            $days = $this->scheduleOccupancy($schedule, $windowStart, $windowEnd);

            if ($days === []) {
                continue;
            }

            $projectId = (int) $schedule->project_id;

            foreach ($schedule->scheduleTechnicians as $scheduleTechnician) {
                $technicianId = $scheduleTechnician->projectTechnician?->technician_id;

                if (! $technicianId || ! $technicianIds->contains((int) $technicianId)) {
                    continue;
                }

                foreach ($days as $day => $window) {
                    $intervals[(int) $technicianId][$day][] = [
                        'project_id' => $projectId,
                        'from' => $window['from'],
                        'to' => $window['to'],
                    ];
                }
            }
        }

        // Extension point: when leave / absence tables land, merge their dates
        // in here and every scheduling screen picks the rule up for free.
        // Leave is owned by no project, so it blocks every project equally,
        // and it takes the whole day.
        foreach ($this->unavailableDatesFromLeave($technicianIds, $windowStart, $windowEnd) as $technicianId => $dates) {
            foreach ($dates as $day => $ignored) {
                $intervals[(int) $technicianId][$day][] = [
                    'project_id' => 0,
                    'from' => 0,
                    'to' => self::MINUTES_PER_DAY,
                ];
            }
        }

        return $intervals;
    }

    /**
     * The minutes a stored schedule occupies on each day it touches, clamped
     * to the window under validation so days that could not possibly matter
     * are never expanded.
     *
     * @return array<string, array{from: int, to: int}>
     */
    private function scheduleOccupancy(Schedule $schedule, string $windowStart, string $windowEnd): array
    {
        $scheduleStart = CarbonImmutable::parse($schedule->start_datetime);
        $scheduleEnd = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

        if ($schedule->isPartialDay()) {
            $day = $scheduleStart->toDateString();

            if ($day < $windowStart || $day > $windowEnd) {
                return [];
            }

            return [$day => [
                'from' => $this->minuteOfDay($scheduleStart),
                'to' => $this->minuteOfDay($scheduleEnd),
            ]];
        }

        $from = max($scheduleStart->toDateString(), $windowStart);
        $to = min($scheduleEnd->toDateString(), $windowEnd);

        if ($from > $to) {
            return [];
        }

        $occupancy = [];

        foreach ($this->eachDate($from, $to) as $day) {
            $occupancy[$day] = ['from' => 0, 'to' => self::MINUTES_PER_DAY];
        }

        return $occupancy;
    }

    /**
     * Reduce every caller's range to the days it covers and the minutes it
     * wants on them.
     *
     * A partial-day request sits on a single date by definition, so only its
     * start date is honoured; the validation layer is what guarantees the two
     * agree before it ever reaches here.
     *
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @return array<int, array{start: string, end: string, from: int, to: int, partial: bool}>
     */
    private function normaliseRanges(array $ranges): array
    {
        $requests = [];

        foreach ($ranges as $range) {
            if (! isset($range['start'], $range['end'])) {
                continue;
            }

            $start = CarbonImmutable::parse($range['start']);
            $end = CarbonImmutable::parse($range['end']);

            if (($range['mode'] ?? Schedule::MODE_DATE_BASED) === Schedule::MODE_PARTIAL_DAY) {
                $requests[] = [
                    'start' => $start->toDateString(),
                    'end' => $start->toDateString(),
                    'from' => $this->minuteOfDay($start),
                    'to' => $this->minuteOfDay($end),
                    'partial' => true,
                ];

                continue;
            }

            $requests[] = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'from' => 0,
                'to' => self::MINUTES_PER_DAY,
                'partial' => false,
            ];
        }

        return $requests;
    }

    /**
     * Half-open comparison, so a booking that ends at 10:00 AM and one that
     * starts at 10:00 AM do not clash.
     *
     * @param  array{from: int, to: int}  $request
     * @param  array{from: int, to: int}  $interval
     */
    private function overlaps(array $request, array $interval): bool
    {
        return $request['from'] < $interval['to'] && $interval['from'] < $request['to'];
    }

    private function minuteOfDay(CarbonImmutable $moment): int
    {
        return ($moment->hour * 60) + $moment->minute;
    }

    /**
     * "8:00 AM - 10:00 AM", or "for the whole day" when the booking leaves no
     * part of the day free.
     *
     * @param  array{from: int, to: int}  $interval
     */
    private function describeInterval(array $interval): string
    {
        if ($interval['from'] <= 0 && $interval['to'] >= self::MINUTES_PER_DAY) {
            return 'for the whole day';
        }

        $midnight = CarbonImmutable::today();

        return $midnight->addMinutes($interval['from'])->format('g:i A')
            .' - '
            .$midnight->addMinutes($interval['to'])->format('g:i A');
    }

    /**
     * Deduplicate the labels gathered per date, and let a whole-day booking
     * stand alone: naming the hours as well would only repeat it.
     *
     * @param  array<string, array<string, true>>  $busy
     * @return array<string, array<int, string>>
     */
    private function tidyBusyLabels(array $busy): array
    {
        $tidied = [];

        ksort($busy);

        foreach ($busy as $day => $labels) {
            $labels = array_keys($labels);

            $tidied[$day] = in_array('for the whole day', $labels, true)
                ? ['for the whole day']
                : $labels;
        }

        return $tidied;
    }

    /**
     * "8:00 AM - 10:00 AM on August 6", across however many dates clashed.
     *
     * @param  array<string, array<int, string>>  $busy
     */
    private function describeBusy(array $busy): string
    {
        $clauses = [];

        foreach ($busy as $day => $labels) {
            $clauses[] = implode(' and ', $labels).' on '.$this->formatDateList([$day]);
        }

        return implode('; ', $clauses);
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @return Collection<int, int>
     */
    private function normaliseTechnicianIds(Collection|array $technicianIds): Collection
    {
        return collect($technicianIds)
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->unique()
            ->values();
    }

    /**
     * Busy schedules for the given technicians inside the validation window.
     *
     * Only projects whose status still counts as active block a technician.
     * Completed, cancelled, archived, on-hold and unscheduled projects
     * are ignored, matching the rules already used across the app.
     *
     * @param  Collection<int, int>  $technicianIds
     * @param  array<int, int>  $excludeScheduleIds
     * @return \Illuminate\Database\Eloquent\Collection<int, Schedule>
     */
    private function busySchedulesQuery(
        Collection $technicianIds,
        string $windowStart,
        string $windowEnd,
        ?int $excludeProjectId,
        array $excludeScheduleIds
    ) {
        return Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', Project::ACTIVE_PROJECT_STATUSES)
                    ->where('is_archived', false);
            })
            ->when($excludeProjectId !== null, function ($query) use ($excludeProjectId): void {
                $query->where('project_id', '!=', $excludeProjectId);
            })
            ->when($excludeScheduleIds !== [], function ($query) use ($excludeScheduleIds): void {
                $query->whereNotIn('schedule_id', $excludeScheduleIds);
            })
            // Only schedules that actually intersect the window can matter.
            ->whereDate('start_datetime', '<=', $windowEnd)
            ->whereDate('end_datetime', '>=', $windowStart)
            ->whereHas('scheduleTechnicians.projectTechnician', function ($query) use ($technicianIds): void {
                $query->whereIn('technician_id', $technicianIds->all());
            })
            ->with([
                'scheduleTechnicians:schedule_technician_id,schedule_id,project_technician_id',
                'scheduleTechnicians.projectTechnician:project_technician_id,technician_id',
            ])
            ->get(['schedule_id', 'project_id', 'start_datetime', 'end_datetime', 'scheduling_mode']);
    }

    /**
     * Reserved for approved leave / absence records. No such table exists in
     * the schema yet, so this returns nothing and every caller behaves exactly
     * as before. Wire the query up here and all scheduling screens inherit it.
     *
     * @param  Collection<int, int>  $technicianIds
     * @return array<int, array<string, true>>
     */
    private function unavailableDatesFromLeave(
        Collection $technicianIds,
        string $windowStart,
        string $windowEnd
    ): array {
        return [];
    }

    /**
     * @param  array<int, int>  $technicianIds
     * @return array<int, string>
     */
    private function technicianNames(array $technicianIds): array
    {
        return Technician::query()
            ->with('account:id,name')
            ->whereIn('technician_id', $technicianIds)
            ->get()
            ->mapWithKeys(fn (Technician $technician): array => [
                (int) $technician->technician_id => $technician->name ?: 'A selected technician',
            ])
            ->all();
    }

    /**
     * Inclusive list of 'Y-m-d' strings between two dates.
     *
     * @return array<int, string>
     */
    private function eachDate(string $from, string $to): array
    {
        $cursor = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $dates = [];
        $guard = 0;

        while ($cursor->lte($end) && $guard < self::MAX_DAYS_PER_RANGE) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
            $guard++;
        }

        return $dates;
    }

    /**
     * "August 1", "August 1 and August 2", "August 1, August 2, and August 3".
     *
     * @param  array<int, string>  $dates
     */
    private function formatDateList(array $dates): string
    {
        if ($dates === []) {
            return '';
        }

        $currentYear = CarbonImmutable::now()->year;
        $allCurrentYear = collect($dates)->every(
            fn (string $date): bool => CarbonImmutable::parse($date)->year === $currentYear
        );
        $format = $allCurrentYear ? 'F j' : 'F j, Y';

        $shown = array_slice($dates, 0, 8);
        $remaining = count($dates) - count($shown);

        $labels = array_map(
            fn (string $date): string => CarbonImmutable::parse($date)->format($format),
            $shown
        );

        if ($remaining > 0) {
            $labels[] = sprintf('%d more date%s', $remaining, $remaining === 1 ? '' : 's');
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        if (count($labels) === 2) {
            return $labels[0].' and '.$labels[1];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).', and '.$last;
    }
}
