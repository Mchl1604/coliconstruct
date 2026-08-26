<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Whether an archived project's preserved schedule can come back into force,
 * answered range by range.
 *
 * The thing being restored is a schedule, and a schedule is a handful of date
 * ranges - "Aug 24-26" and "Sep 6-8", not fourteen loose days and not a list
 * of technicians. So that is the unit this reports in, and the unit the dialog
 * built on it edits: a range is available, or it is in conflict, or it is
 * already in the past, and the whole range carries that answer.
 *
 * Ranges that have entirely ended are the record of work that happened. They
 * are shown, because a project's schedule is not honest with its history
 * missing, but they are not screened and not editable: nobody's availability
 * today has anything to say about a week that is over, and Schedule::isLocked()
 * already draws exactly that line for every other screen.
 *
 * Nothing here decides availability. Every fact comes from
 * TechnicianAvailabilityService - findConflicts() per range, so a clash
 * belongs to the range that caused it, and blockedDatesInWindow() for the days
 * an edit may move a range onto. There is deliberately no second availability
 * calculation: a picker that greyed out a different set of days than the save
 * refuses would be worse than no picker.
 */
class RestoreScheduleConflicts
{
    /**
     * How far ahead the resolution date pickers offer, matching the Reopen
     * dialog's horizon.
     */
    private const PICKER_HORIZON_MONTHS = 12;

    public const STATE_PAST = 'past';

    public const STATE_AVAILABLE = 'available';

    public const STATE_CONFLICT = 'conflict';

    public function __construct(
        private readonly TechnicianAvailabilityService $availability,
        private readonly ScheduleModeRules $scheduleRules,
    ) {}

    /**
     * The project's whole schedule, with an answer against each range.
     *
     * `blocked` is the only thing the restore itself has to read: true when
     * some current or future range cannot come back. Past ranges never set it,
     * however far the calendar has moved since - they are not coming back into
     * force, they already happened.
     *
     * @return array{project: array<string, mixed>, blocked: bool, ranges: array<int, array<string, mixed>>, message: ?string, earliest_date: string, checked_at: string}
     */
    public function report(Project $project): array
    {
        // Read from the table rather than from a loaded relation: the rows are
        // what the restore is about, and a relation loaded earlier in the
        // request would be a picture rather than the record. It is also what
        // makes this safe to call again after an edit - see the recheck the
        // dialog runs before every restore.
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get();

        $technicianIds = $this->technicianIdsOf((int) $project->project_id);
        $today = Schedule::businessToday();

        // Only work that is actually booked claims anybody. A completed,
        // cancelled or paused record keeps its schedule for the history and
        // has never counted against availability, so its ranges are all read
        // as history here too - there is nothing to screen and nothing to fix.
        $screened = $project->restoreWouldClaimDates() && $technicianIds->isNotEmpty();

        $blockedDates = $screened
            ? $this->teamBlockedDates($technicianIds, (int) $project->project_id, $today)
            : ['whole_day' => [], 'partial_day' => []];

        $ranges = [];
        $allConflicts = collect();

        foreach ($schedules as $schedule) {
            // "Completely in the past" is not a new idea to invent here:
            // Schedule::lockState() has drawn that line for the calendar, the
            // editor and the validator all along, and locked means ended.
            $past = $schedule->isLocked();

            $conflicts = ($screened && ! $past)
                ? $this->availability->findConflicts(
                    $technicianIds,
                    [$schedule->toAvailabilityRange()],
                    (int) $project->project_id
                )
                : collect();

            if ($conflicts->isNotEmpty()) {
                $allConflicts = $allConflicts->concat($conflicts);
            }

            $ranges[] = $this->rangePayload(
                $project,
                $schedule,
                $schedules,
                $past,
                $screened,
                $conflicts,
                $blockedDates,
                $today
            );
        }

        $blocked = collect($ranges)->contains(
            fn (array $range): bool => $range['state'] === self::STATE_CONFLICT
        );

        return [
            'project' => $this->projectPayload($project, $schedules, $technicianIds),
            'blocked' => $blocked,
            'ranges' => $ranges,
            'message' => $blocked ? $this->summaryMessage($allConflicts) : null,
            'earliest_date' => $today->toDateString(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The one-sentence version, word for word what restore() has always
     * flashed, so the path without JavaScript still says what it said.
     */
    public function summary(Project $project): ?string
    {
        $report = $this->report($project);

        return $report['blocked'] ? $report['message'] : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $conflicts
     */
    private function summaryMessage(Collection $conflicts): string
    {
        // One line per technician however many ranges they turned up in: the
        // same person named twice reads as two problems.
        $merged = $conflicts
            ->groupBy('technician_id')
            ->map(function (Collection $forTechnician): array {
                $first = $forTechnician->first();
                $dates = $forTechnician->flatMap(fn (array $conflict): array => $conflict['dates'])
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $first['dates'] = $dates;
                $first['projects'] = $forTechnician
                    ->flatMap(fn (array $conflict): array => $conflict['projects'] ?? [])
                    ->unique()
                    ->values()
                    ->all();

                return $first;
            })
            ->values();

        return 'Unable to restore - the dates this project still holds are now booked elsewhere. '
            .$this->availability->conflictMessage(
                $merged,
                ' Reschedule that work or remove them from this team, then restore it again.'
            );
    }

    /**
     * @return Collection<int, int>
     */
    private function technicianIdsOf(int $projectId): Collection
    {
        return ProjectTechnician::query()
            ->where('project_id', $projectId)
            ->pluck('technician_id')
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, Schedule>  $schedules
     * @param  Collection<int, int>  $technicianIds
     * @return array<string, mixed>
     */
    private function projectPayload(
        Project $project,
        Collection $schedules,
        Collection $technicianIds
    ): array {
        $returnsAs = $project->statusToRestore();

        return [
            'project_id' => (int) $project->project_id,
            'code' => $project->displayCode(),
            'reference_no' => $project->reference_no ?? $project->name,
            'name' => $project->name,
            'returns_as' => $returnsAs ? Project::statusLabelFor($returnsAs) : 'Unscheduled',
            'claims_dates' => $project->restoreWouldClaimDates(),
            'partial_day_allowed' => $project->isResidential(),
            'technician_count' => $technicianIds->count(),
            'team' => $project->loadMissing('projectTechnicians.technician')->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?string => $assignment->technician?->name)
                ->filter()
                ->values()
                ->all(),
            'schedule_label' => $schedules->isEmpty()
                ? 'No dates'
                : $schedules->map(fn (Schedule $schedule): string => $schedule->describe())->implode(', '),
            'update_url' => route('super-admin.projects.restore-schedule', $project->project_id),
        ];
    }

    /**
     * One range of the project's schedule, with its verdict and everything an
     * edit of it needs.
     *
     * @param  Collection<int, Schedule>  $schedules
     * @param  Collection<int, array<string, mixed>>  $conflicts
     * @param  array{whole_day: array<int, string>, partial_day: array<int, string>}  $blockedDates
     * @return array<string, mixed>
     */
    private function rangePayload(
        Project $project,
        Schedule $schedule,
        Collection $schedules,
        bool $past,
        bool $screened,
        Collection $conflicts,
        array $blockedDates,
        CarbonImmutable $today
    ): array {
        // Asked of the rules that decide it rather than re-derived, so a range
        // this draws as movable is one the save will accept moving. A range
        // that has ended comes back editable=false, which is the whole of the
        // past-range rule.
        $limits = $this->scheduleRules->editabilityOf($schedule);

        $state = $past
            ? self::STATE_PAST
            : ($conflicts->isNotEmpty() ? self::STATE_CONFLICT : self::STATE_AVAILABLE);

        return [
            'schedule_id' => (int) $schedule->schedule_id,
            'label' => $schedule->describe(),
            'scheduling_mode' => $schedule->scheduling_mode ?? Schedule::MODE_DATE_BASED,
            'partial_day' => $schedule->isPartialDay(),
            'start_date' => $schedule->startsOn()->toDateString(),
            'end_date' => $schedule->endsOn()->toDateString(),
            'project_date' => $schedule->startsOn()->toDateString(),
            'start_time' => CarbonImmutable::parse($schedule->start_datetime)->format('H:i'),
            'end_time' => CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('H:i'),
            // The hours this range may be moved to: the configured partial-day
            // window, widened to keep whatever this booking already holds. Sent
            // rather than built in the browser so the dialog offers exactly
            // what the save will accept, and so no second copy of the window
            // lives in a script - see Schedule::partialDayHourBounds().
            'hour_options' => $schedule->isPartialDay()
                ? Schedule::workingHourOptionsIncluding(
                    CarbonImmutable::parse($schedule->start_datetime)->format('H:i'),
                    CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('H:i')
                )
                : [],
            'state' => $state,
            'state_label' => match ($state) {
                self::STATE_PAST => 'Past',
                self::STATE_CONFLICT => 'Schedule Conflict',
                default => 'Available',
            },
            'past' => $past,
            // Screened work only. A completed record's dates are not coming
            // back into force, so there is nothing about them to edit here.
            'editable' => ! $past && $screened && (bool) $limits['editable'],
            'removable' => ! $past && $screened,
            'start_frozen' => (bool) $limits['startFrozen'],
            // Never earlier than today whatever the row says: a restore books
            // work still to come, and a past date is not that.
            'earliest_start' => $this->laterOf($limits['earliestStart'], $today),
            'earliest_end' => $this->laterOf($limits['earliestEnd'], $today),
            'conflict' => $conflicts->isEmpty() ? null : $this->conflictDetail($conflicts),
            // The days an edit of THIS range may not land on: what the team is
            // spoken for on elsewhere, plus the days this project's own other
            // ranges already hold.
            'blocked_dates' => $past
                ? ['whole_day' => [], 'partial_day' => []]
                : $this->withSiblingRanges($blockedDates, $schedules, $schedule, $today),
        ];
    }

    /**
     * Why a range is refused, as supporting detail rather than as the subject.
     *
     * The subject is the range. This is the answer to "why?" for somebody who
     * asks it - who is unavailable, and what has them - which is what turns a
     * red badge into something a person can act on.
     *
     * @param  Collection<int, array<string, mixed>>  $conflicts
     * @return array<string, mixed>
     */
    private function conflictDetail(Collection $conflicts): array
    {
        $dates = $conflicts
            ->flatMap(fn (array $conflict): array => $conflict['dates'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'summary' => 'This schedule conflicts with the current availability of one or more team members.',
            'overlap_label' => $this->availability->describeDates($dates),
            'technicians' => $conflicts->pluck('technician_name')->unique()->values()->all(),
            'projects' => $conflicts
                ->flatMap(fn (array $conflict): array => $conflict['projects'] ?? [])
                ->unique()
                ->values()
                ->all(),
            'details' => $conflicts->map(fn (array $conflict): array => [
                'technician_name' => $conflict['technician_name'],
                'dates_label' => $this->availability->describeDates($conflict['dates']),
                'projects' => $conflict['projects'] ?? [],
            ])->values()->all(),
        ];
    }

    /**
     * The days this project's team is spoken for, over the window a picker
     * offers.
     *
     * The project's own bookings are left out, which is what makes the
     * question answerable at all: every day being asked about is one it holds
     * itself, so without the exclusion every range would read as its own
     * blocker. Its other ranges are added back per range, where they belong -
     * see withSiblingRanges().
     *
     * @param  Collection<int, int>  $technicianIds
     * @return array{whole_day: array<int, string>, partial_day: array<int, string>}
     */
    private function teamBlockedDates(
        Collection $technicianIds,
        int $projectId,
        CarbonImmutable $today
    ): array {
        return $this->availability->blockedDatesInWindow(
            $technicianIds,
            $today,
            $today->addMonths(self::PICKER_HORIZON_MONTHS),
            $projectId
        );
    }

    /**
     * The team's busy days, plus the days the project's OTHER ranges hold.
     *
     * A project may not book itself twice over the same time - the same
     * self-overlap rule the schedules page and the reopen dialog enforce - so
     * a range being moved has to see its siblings as taken. Its own current
     * days stay open, which is what lets a range be shortened or nudged.
     *
     * @param  array{whole_day: array<int, string>, partial_day: array<int, string>}  $blocked
     * @param  Collection<int, Schedule>  $schedules
     * @return array{whole_day: array<int, string>, partial_day: array<int, string>}
     */
    private function withSiblingRanges(
        array $blocked,
        Collection $schedules,
        Schedule $editing,
        CarbonImmutable $today
    ): array {
        $wholeDay = array_flip($blocked['whole_day']);
        $partialDay = array_flip($blocked['partial_day']);

        foreach ($schedules as $schedule) {
            if ((int) $schedule->schedule_id === (int) $editing->schedule_id) {
                continue;
            }

            foreach ($this->datesOf($schedule) as $date) {
                if ($date < $today->toDateString()) {
                    continue;
                }

                $wholeDay[$date] = true;

                if (! $schedule->isPartialDay()) {
                    $partialDay[$date] = true;
                }
            }
        }

        $wholeDay = array_keys($wholeDay);
        $partialDay = array_keys($partialDay);

        sort($wholeDay);
        sort($partialDay);

        return ['whole_day' => $wholeDay, 'partial_day' => $partialDay];
    }

    private function laterOf(?CarbonImmutable $bound, CarbonImmutable $today): string
    {
        return $bound && $bound->gt($today)
            ? $bound->toDateString()
            : $today->toDateString();
    }

    /**
     * Every calendar day one range touches.
     *
     * @return array<int, string>
     */
    private function datesOf(Schedule $schedule): array
    {
        $dates = [];
        $end = $schedule->endsOn();

        for ($day = $schedule->startsOn(); $day->lte($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }
}
