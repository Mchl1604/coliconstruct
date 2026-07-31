<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Single source of truth for "can these technicians work this date range?".
 *
 * Every scheduling entry point (project creation, the schedules page, and the
 * assigned-team editor) funnels through here so the rules stay identical.
 *
 * The rule is continuous availability: a range is only valid when every
 * selected technician is free on EVERY calendar day from the start date
 * through the end date, inclusive. A range whose endpoints happen to be free
 * but which spans a busy day in the middle is rejected.
 */
class TechnicianAvailabilityService
{
    /**
     * Safety valve so a nonsense range (a typo'd year, say) can never spin the
     * day-by-day loop for an unreasonable amount of time.
     */
    private const MAX_DAYS_PER_RANGE = 3660;

    /**
     * Build a map of technician_id => set of unavailable 'Y-m-d' dates.
     *
     * All rows are pulled in one query, eager loaded, and clamped to the
     * window actually being validated, so per-day checking happens purely in
     * memory. There is no query-per-date and no N+1.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $ranges
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
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     * @return array<int, array<string, array<int, true>>>  technicianId => date => [projectId => true]
     */
    public function unavailableDayOwners(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId = null,
        array $excludeScheduleIds = []
    ): array {
        $technicianIds = collect($technicianIds)
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->unique()
            ->values();

        if ($technicianIds->isEmpty() || $ranges === []) {
            return [];
        }

        $windowStart = collect($ranges)->min(fn (array $range) => $range['start']->toDateString());
        $windowEnd = collect($ranges)->max(fn (array $range) => $range['end']->toDateString());

        $busySchedules = $this->busySchedulesQuery(
            $technicianIds,
            $windowStart,
            $windowEnd,
            $excludeProjectId,
            $excludeScheduleIds
        );

        $owners = [];

        foreach ($busySchedules as $schedule) {
            $scheduleStart = CarbonImmutable::parse($schedule->start_datetime)->toDateString();
            $scheduleEnd = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->toDateString();

            // Clamp to the window under validation so we never expand days
            // that could not possibly matter.
            $from = max($scheduleStart, $windowStart);
            $to = min($scheduleEnd, $windowEnd);

            if ($from > $to) {
                continue;
            }

            $days = $this->eachDate($from, $to);
            $projectId = (int) $schedule->project_id;

            foreach ($schedule->scheduleTechnicians as $scheduleTechnician) {
                $technicianId = $scheduleTechnician->projectTechnician?->technician_id;

                if (! $technicianId || ! $technicianIds->contains((int) $technicianId)) {
                    continue;
                }

                foreach ($days as $day) {
                    $owners[(int) $technicianId][$day][$projectId] = true;
                }
            }
        }

        // Extension point: when leave / absence tables land, merge their dates
        // in here and every scheduling screen picks the rule up for free.
        // Leave is owned by no project, so it blocks every project equally.
        foreach ($this->unavailableDatesFromLeave($technicianIds, $windowStart, $windowEnd) as $technicianId => $dates) {
            foreach ($dates as $day => $ignored) {
                $owners[(int) $technicianId][$day][0] = true;
            }
        }

        return $owners;
    }

    /**
     * Walk every calendar day of every submitted range and report which
     * technicians are unavailable on which specific dates.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     * @param  array<int, int>  $excludeScheduleIds
     * @return Collection<int, array{technician_id: int, technician_name: string, dates: array<int, string>}>
     */
    public function findConflicts(
        Collection|array $technicianIds,
        array $ranges,
        ?int $excludeProjectId = null,
        array $excludeScheduleIds = []
    ): Collection {
        $technicianIds = collect($technicianIds)
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->unique()
            ->values();

        if ($technicianIds->isEmpty() || $ranges === []) {
            return collect();
        }

        $unavailable = $this->unavailableDatesByTechnician(
            $technicianIds,
            $ranges,
            $excludeProjectId,
            $excludeScheduleIds
        );

        if ($unavailable === []) {
            return collect();
        }

        $conflicts = [];

        foreach ($technicianIds as $technicianId) {
            $busyDates = $unavailable[$technicianId] ?? [];

            if ($busyDates === []) {
                continue;
            }

            $hits = [];

            foreach ($ranges as $range) {
                foreach ($this->eachDate($range['start']->toDateString(), $range['end']->toDateString()) as $day) {
                    if (isset($busyDates[$day])) {
                        $hits[$day] = true;
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
     * Throw with a per-technician, per-date message when the range is not
     * continuously available.
     *
     * @param  Collection<int, int>|array<int, int>  $technicianIds
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $ranges
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
     * @param  Collection<int, array{technician_id: int, technician_name: string, dates: array<int, string>}>  $conflicts
     */
    public function conflictMessage(Collection $conflicts): string
    {
        if ($conflicts->isEmpty()) {
            return '';
        }

        $parts = $conflicts->map(function (array $conflict): string {
            return sprintf(
                'Technician %s is unavailable on %s.',
                $conflict['technician_name'],
                $this->formatDateList($conflict['dates'])
            );
        })->all();

        return implode(' ', $parts)
            . ' Please select a continuous date range where all selected technicians are available.';
    }

    /**
     * Busy schedules for the given technicians inside the validation window.
     *
     * Only projects whose status still counts as active block a technician.
     * Completed, cancelled, archived, on-hold and not-yet-scheduled projects
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
            ->get(['schedule_id', 'project_id', 'start_datetime', 'end_datetime']);
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
            return $labels[0] . ' and ' . $labels[1];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ', and ' . $last;
    }
}
