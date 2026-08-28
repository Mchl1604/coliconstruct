<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The dates one technician was actually on one project - resolved a day at a
 * time, and only then grouped back into ranges.
 *
 * A project's schedule says when the JOB is booked. It does not say who was
 * there, and the two drift apart the moment a crew changes mid-job: a range
 * running Aug 24-30 with one technician swapped out on the 27th is one row in
 * tbl_schedules and two different answers to "who was on site?". Handing that
 * range whole to whoever is on the team today - which is what the Technician
 * Schedule export used to do - says the newcomer worked days they were not
 * here for, and erases the days the person they replaced did work.
 *
 * So the walk is per date. Every date of every range is asked the two
 * questions separately:
 *
 *   1. is the project scheduled on this date?   the range covers it
 *   2. was THIS technician assigned on it?      Project::crewOn()
 *
 * and only a date answering yes to both belongs to that technician.
 *
 * The second question is not answered here. It is answered by
 * Project::crewOn(), which reads the membership spans in
 * tbl_project_technicians - the same call the Schedule page's day panel makes
 * to name the crew on a clicked day. That is deliberate and is the point of
 * routing through it: the export and the calendar must not be able to disagree
 * about who was on a project on a given date, and they cannot if only one of
 * them is deciding. See ProjectTechnician, where a membership is a span rather
 * than a fact, for why that answer is historically correct - somebody removed
 * today keeps the days they worked, and somebody who joined yesterday does not
 * acquire last month.
 *
 * tbl_schedule_technicians is deliberately NOT consulted. It is the booking
 * ledger the availability checker reads, and it is not a record of history:
 * ProjectTeam::detach() releases the links on ranges that have not ended, so a
 * technician taken off part-way through a running range loses the row for days
 * they had already worked. Reading it here would delete exactly the history
 * this class exists to preserve. The membership span is what carries that,
 * which is why it is the one source used.
 *
 * What comes back is runs of consecutive dates rather than loose days, because
 * a report listing thirty single dates is unreadable. The grouping is the last
 * step and never the first: a run is broken by a change of technician, of
 * project, of schedule row, or of which side of today the date falls on, and a
 * missing date breaks it too - so Aug 24-26 and Aug 28-30 stay two runs and
 * never become Aug 24-30.
 */
class TechnicianAssignedDates
{
    /**
     * The same guard HistoricalScheduleCorrection keeps on its day-by-day
     * walk: a range whose year was typed wrong must not spin this loop for an
     * unreasonable length of time.
     */
    private const MAX_DAYS_PER_RANGE = 3660;

    /**
     * Every run of consecutive dates a technician was assigned for, across the
     * given projects.
     *
     * `$today` is passed in rather than read here so the caller states the
     * boundary once and every run in one report is classified against the same
     * date - the application's business today, never the server's midnight.
     *
     * `$splitAtToday` decides whether that boundary also CUTS a run. The
     * Schedule section prints two tables and needs the cut: a booking running
     * across today is partly worked and partly promised, and those belong in
     * different halves of the report. Assigned Projects prints one cell per
     * assignment and must not have it, or a technician booked Aug 27-30 with
     * today on the 28th would read as two separate stretches of work where
     * there was one. Either way `is_past` says whether the run is behind us -
     * with the cut it is exact, without it means the whole run has been
     * worked.
     *
     * @param  Collection<int, Project>  $projects  with `schedules` and
     *                                              `teamHistory` loaded
     * @param  Collection<int, mixed>  $technicianIds  the report's directory;
     *                                                 anybody outside it is
     *                                                 not being asked about
     * @return Collection<int, array{
     *     technician_id: int,
     *     project_id: int,
     *     schedule_id: int,
     *     is_past: bool,
     *     dates: array<int, string>,
     *     start: string,
     *     end: string,
     *     label: string
     * }>
     */
    public function runs(
        Collection $projects,
        Collection $technicianIds,
        CarbonImmutable $today,
        bool $splitAtToday = true
    ): Collection {
        $wanted = $technicianIds->map(fn ($id): int => (int) $id)->flip();
        $todayString = $today->toDateString();

        // technician|project|schedule|side => the dates it holds. Keyed by all
        // four because those are exactly the things a run may not span.
        $buckets = [];
        $schedules = [];

        foreach ($projects as $project) {
            foreach ($project->schedules as $schedule) {
                $scheduleId = (int) $schedule->schedule_id;
                $schedules[$scheduleId] = $schedule;

                foreach ($this->datesOf($schedule) as $date) {
                    // Question 2, asked per date - and asked of the model the
                    // Schedule page asks.
                    foreach ($project->crewOn($date) as $assignment) {
                        $technicianId = (int) $assignment->technician_id;

                        if (! $wanted->has($technicianId)) {
                            continue;
                        }

                        // Today counts as future: it is being worked, not
                        // already worked.
                        $side = $splitAtToday && $date >= $todayString ? 'future' : 'past';

                        $buckets[$technicianId.'|'.((int) $project->project_id).'|'.$scheduleId.'|'.$side][] = $date;
                    }
                }
            }
        }

        return $this->groupRuns($buckets, $schedules, $todayString, $splitAtToday);
    }

    /**
     * Every calendar date one booking occupies, ascending.
     *
     * A partial day books hours on a single date and so is exactly one; a
     * date-based range is every day from its start to its end inclusive.
     *
     * @return array<int, string>
     */
    public function datesOf(Schedule $schedule): array
    {
        $start = $schedule->startsOn();

        if ($schedule->isPartialDay()) {
            return [$start->toDateString()];
        }

        $end = $schedule->endsOn();

        if ($end->lt($start)) {
            return [$start->toDateString()];
        }

        $dates = [];
        $date = $start;

        for ($guard = 0; $date->lte($end) && $guard < self::MAX_DAYS_PER_RANGE; $guard++) {
            $dates[] = $date->toDateString();
            $date = $date->addDay();
        }

        return $dates;
    }

    /**
     * Loose dates back into runs, breaking wherever a day is missing.
     *
     * The buckets already separate the things a run may not span, so the only
     * break left to find is a gap in the dates themselves - a technician on
     * the 24th to the 26th and again on the 28th is two runs, and joining them
     * would claim a day they were not assigned.
     *
     * @param  array<string, array<int, string>>  $buckets
     * @param  array<int, Schedule>  $schedules
     * @return Collection<int, array<string, mixed>>
     */
    private function groupRuns(array $buckets, array $schedules, string $today, bool $splitAtToday): Collection
    {
        $runs = collect();

        foreach ($buckets as $key => $dates) {
            [$technicianId, $projectId, $scheduleId, $side] = explode('|', $key);

            $dates = array_values(array_unique($dates));
            sort($dates);

            $current = [];

            foreach ($dates as $date) {
                if ($current !== [] && ! $this->followsOn(end($current), $date)) {
                    $runs->push($this->run($technicianId, $projectId, $scheduleId, $side, $current, $schedules, $today, $splitAtToday));
                    $current = [];
                }

                $current[] = $date;
            }

            if ($current !== []) {
                $runs->push($this->run($technicianId, $projectId, $scheduleId, $side, $current, $schedules, $today, $splitAtToday));
            }
        }

        return $runs
            ->sortBy(fn (array $run): string => $run['start'].$run['end'])
            ->values();
    }

    /**
     * Whether the second date is the day after the first.
     */
    private function followsOn(string $previous, string $date): bool
    {
        return CarbonImmutable::parse($previous)->addDay()->toDateString() === $date;
    }

    /**
     * @param  array<int, string>  $dates  ascending and consecutive
     * @param  array<int, Schedule>  $schedules
     * @return array<string, mixed>
     */
    private function run(
        string $technicianId,
        string $projectId,
        string $scheduleId,
        string $side,
        array $dates,
        array $schedules,
        string $today,
        bool $splitAtToday
    ): array {
        $start = $dates[0];
        $end = $dates[count($dates) - 1];

        return [
            'technician_id' => (int) $technicianId,
            'project_id' => (int) $projectId,
            'schedule_id' => (int) $scheduleId,
            // With the cut, the bucket already settled it. Without it, a run
            // is behind us only when the last of its days is.
            'is_past' => $splitAtToday ? $side === 'past' : $end < $today,
            'dates' => $dates,
            'start' => $start,
            'end' => $end,
            'label' => $this->describe($schedules[(int) $scheduleId] ?? null, $start, $end),
        ];
    }

    /**
     * How a run reads on the page.
     *
     * Deliberately the shape Schedule::describe() uses, over the run's own
     * dates rather than the booking's: a technician who worked three days of a
     * seven-day range is shown the three, or the report would be printing the
     * very claim this class exists to stop. A partial day keeps its hours,
     * because the hours are what that booking is.
     */
    public function describe(?Schedule $schedule, string $start, string $end): string
    {
        $from = CarbonImmutable::parse($start)->format(BusinessTime::DATE);

        if ($schedule?->isPartialDay()) {
            $times = $schedule->timeRange();

            return $times ? $from.' · '.$times : $from;
        }

        $to = CarbonImmutable::parse($end)->format(BusinessTime::DATE);

        return $from === $to ? $from : $from.' - '.$to;
    }
}
