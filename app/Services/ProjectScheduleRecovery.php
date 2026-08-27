<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Whether a project's preserved schedule can come back into force, answered
 * range by range - and how an administrator resolves it when it cannot.
 *
 * Three actions bring a project back: restoring it from the archive, reopening
 * one that is waiting on its client, and resuming one that was paused. All
 * three ask the same question of the calendar, and all three used to answer it
 * in their own controller method with their own copy of the rules. This is
 * that one answer, so the three cannot drift apart on any of it:
 *
 *   client type            Project::isResidential(), read from the project's
 *                          stored client record - never from a label on a
 *                          page or a value a browser sent back
 *   partial-day rules      ScheduleModeRules, the same validator the project
 *                          wizard, the schedules page and the calendar use
 *   start / end hours      Schedule::partialDayHourBounds(), which is
 *                          Configuration -> System Settings -> Project
 *                          Settings and nothing else
 *   date validation        ScheduleModeRules::validateEntry()
 *   availability           TechnicianAvailabilityService
 *   conflict detection     TechnicianAvailabilityService::findConflicts()
 *   conflict resolution    resolveRange(), below
 *
 * The thing being recovered is a schedule, and a schedule is a handful of date
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
 * calculation, and in particular no date-only one: a picker that greyed out a
 * different set of days than the save refuses would be worse than no picker,
 * and a partial-day range that slipped past a whole-day booking would be worse
 * still. A technician occupied for a whole day is occupied for every minute of
 * it - see scheduleOccupancy() there - so an hours-only range lands on that
 * occupancy exactly like anything else.
 */
class ProjectScheduleRecovery
{
    /**
     * How far ahead the resolution date pickers offer.
     */
    public const PICKER_HORIZON_MONTHS = 12;

    public const STATE_PAST = 'past';

    public const STATE_AVAILABLE = 'available';

    public const STATE_CONFLICT = 'conflict';

    /** Bringing an archived project back into the active list. */
    public const FLOW_RESTORE = 'restore';

    /** Lifting the pause on a project that was put on hold. */
    public const FLOW_RESUME = 'resume';

    /** Putting a project waiting on its client back to work. */
    public const FLOW_REOPEN = 'reopen';

    public function __construct(
        private readonly TechnicianAvailabilityService $availability,
        private readonly ScheduleModeRules $scheduleRules,
        private readonly ScheduleConsolidation $consolidation,
    ) {}

    // ------------------------------------------------------------------
    // Client type and the partial-day window
    // ------------------------------------------------------------------

    /**
     * Whether this project may book hours rather than whole days.
     *
     * Asked of the project's stored client record and of nothing else. A
     * client type read off a table cell, a badge, or a hidden input the
     * browser posted back is a claim about the project rather than a fact
     * about it, and this decides what the server will accept.
     */
    public function partialDayAllowed(Project $project): bool
    {
        return $project->isResidential();
    }

    /**
     * The configured Partial Day Start Hour and End Hour, as Project Settings
     * holds them.
     *
     * @return array{start: int, end: int, start_label: string, end_label: string}
     */
    public function partialDayWindow(): array
    {
        return Schedule::partialDayHourBounds();
    }

    /**
     * The hours a partial-day picker may offer: the configured window, widened
     * to keep whatever a booking already holds outside it.
     *
     * Narrowing the window in Project Settings must not blank a select that is
     * sitting on a promise already made. An hour only kept that way comes back
     * marked `outside`, so it stays selectable on the row that holds it and is
     * offered nowhere else.
     *
     * @return array<int, array{value: string, label: string, outside: bool}>
     */
    public function hourOptions(?string ...$keep): array
    {
        return Schedule::workingHourOptionsIncluding(...$keep);
    }

    // ------------------------------------------------------------------
    // The report
    // ------------------------------------------------------------------

    /**
     * The project's whole schedule, with an answer against each range.
     *
     * `blocked` is the only thing the recovery itself has to read: true when
     * some current or future range cannot come back. Past ranges never set it,
     * however far the calendar has moved since - they are not coming back into
     * force, they already happened.
     *
     * @return array{flow: array<string, mixed>, project: array<string, mixed>, blocked: bool, ranges: array<int, array<string, mixed>>, message: ?string, earliest_date: string, checked_at: string}
     */
    public function report(Project $project, string $flow = self::FLOW_RESTORE): array
    {
        // Read from the table rather than from a loaded relation: the rows are
        // what the recovery is about, and a relation loaded earlier in the
        // request would be a picture rather than the record. It is also what
        // makes this safe to call again after an edit - see the recheck the
        // dialog runs before every attempt.
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get();

        $technicianIds = $this->technicianIdsOf((int) $project->project_id);
        $today = Schedule::businessToday();

        // Only work that would actually come back onto the calendar claims
        // anybody. A completed or cancelled record keeps its schedule for the
        // history and has never counted against availability, so its ranges
        // are all read as history here too - there is nothing to screen and
        // nothing to fix.
        $screened = $this->wouldClaimDates($project, $flow) && $technicianIds->isNotEmpty();

        $blockedDates = $screened
            ? $this->teamBlockedDates($technicianIds, (int) $project->project_id, $today)
            : ['whole_day' => [], 'partial_day' => []];

        $partialDayAllowed = $this->partialDayAllowed($project);

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
                $schedule,
                $schedules,
                $past,
                $screened,
                $partialDayAllowed,
                $conflicts,
                $blockedDates,
                $today
            );
        }

        $blocked = collect($ranges)->contains(
            fn (array $range): bool => $range['state'] === self::STATE_CONFLICT
        );

        return [
            'flow' => $this->flowPayload($project, $flow),
            'project' => $this->projectPayload($project, $flow, $schedules, $technicianIds, $partialDayAllowed),
            'blocked' => $blocked,
            'ranges' => $ranges,
            'message' => $blocked ? $this->summaryMessage($flow, $allConflicts) : null,
            'earliest_date' => $today->toDateString(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The one-sentence version, word for word what the flows have always
     * flashed, so the path without JavaScript still says what it said.
     */
    public function summary(Project $project, string $flow = self::FLOW_RESTORE): ?string
    {
        $report = $this->report($project, $flow);

        return $report['blocked'] ? $report['message'] : null;
    }

    /**
     * The days a picker booking a BRAND NEW range must refuse - the Reopen
     * dialog's case, where there is no existing row to move.
     *
     * Two lists, because there are two kinds of booking: a whole-day range
     * needs every minute of every day it covers, while an hours-only one needs
     * only a free hour of its single date. The project's own bookings are
     * excluded from the availability answer - it can never be its own blocker
     * - and then added back, because a project may not book itself twice over
     * the same time.
     *
     * @return array{whole_day: array<int, string>, partial_day: array<int, string>}
     */
    public function blockedDatesForNewRange(
        Project $project,
        int $horizonMonths = self::PICKER_HORIZON_MONTHS
    ): array {
        $today = Schedule::businessToday();
        $horizon = $today->addMonths($horizonMonths);

        $technicianIds = $this->technicianIdsOf((int) $project->project_id);

        $blocked = $technicianIds->isEmpty()
            ? ['whole_day' => [], 'partial_day' => []]
            : $this->availability->blockedDatesInWindow(
                $technicianIds,
                $today,
                $horizon,
                (int) $project->project_id
            );

        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get();

        return $this->withOwnRanges($blocked, $schedules, null, $today, $horizon);
    }

    // ------------------------------------------------------------------
    // Resolving one range
    // ------------------------------------------------------------------

    /**
     * Move or drop one range of a project's schedule, from the Schedule
     * Conflict dialog of whichever flow opened it.
     *
     * The thing being resolved is THIS project's schedule. A clash is two
     * pieces of work wanting the same person on the same day, and either of
     * them could move in principle - but the other one is live work somebody
     * is already expecting, and a recovery is no reason to rewrite it. So this
     * project's own range is what moves here, and the other project is not
     * touched at all.
     *
     * Nothing is invented. Every rule that decides whether a range is allowed
     * is asked of the service that owns it:
     *
     *   ScheduleModeRules              what a range may say, including whether
     *                                  a partial day is allowed for this
     *                                  client at all and whether its hours sit
     *                                  inside the configured window
     *   TechnicianAvailabilityService  whether the team is free for it, by
     *                                  actual occupied minutes
     *   ScheduleConsolidation          ranges that run into each other are one
     *   Schedule::isLocked()           a range that has ended is history
     *
     * A range that is entirely in the past is refused outright. It is not
     * coming back into force, it never counted against this check, and
     * changing it would be rewriting a record of work that happened.
     *
     * Nothing is written until every check has passed, so a refusal leaves the
     * schedule exactly as it stood - a conflicting range is never silently
     * overwritten on the way to reporting the conflict.
     *
     * @param  array<string, mixed>  $input  the validated request payload
     * @return array{action: string, before: string, after: ?string} what happened, for the caller's audit line
     *
     * @throws RuntimeException when the change cannot be made
     */
    public function resolveRange(Project $project, array $input): array
    {
        $schedule = Schedule::query()
            ->where('project_id', $project->project_id)
            ->where('schedule_id', (int) ($input['schedule_id'] ?? 0))
            ->first();

        if (! $schedule) {
            throw new RuntimeException('That schedule range does not belong to this project.');
        }

        if ($schedule->isLocked()) {
            throw new RuntimeException(
                'This schedule range has already ended. It is part of the project\'s history and cannot be changed.'
            );
        }

        $before = $schedule->describe();

        if (($input['action'] ?? 'update') === 'remove') {
            $schedule->delete();

            return ['action' => 'remove', 'before' => $before, 'after' => null];
        }

        $range = $this->interpret($project, $schedule, $input);

        // Its own other ranges, which it may not be booked over: the same
        // self-overlap rule the schedules page and the reopen dialog apply.
        $overlaps = Schedule::query()
            ->where('project_id', $project->project_id)
            ->where('schedule_id', '!=', $schedule->schedule_id)
            ->get()
            ->contains(
                fn (Schedule $other): bool => $this->scheduleRules->overlaps($range, $other->occupiedInterval())
            );

        if ($overlaps) {
            throw new RuntimeException('This project already has a schedule range covering that time.');
        }

        // And the question the whole dialog exists for, asked of the one
        // service every other booking screen asks. The project's own bookings
        // are excluded, so it can never read as its own blocker - and an
        // hours-only range is measured in minutes against whatever actually
        // occupies the day, so it cannot slip past a technician who is booked
        // for the whole of it.
        $this->availability->assertContinuouslyAvailable(
            $this->technicianIdsOf((int) $project->project_id),
            [$range],
            (int) $project->project_id
        );

        $schedule->update([
            'start_datetime' => $range['start'],
            'end_datetime' => $range['end'],
            'scheduling_mode' => $range['mode'],
        ]);

        // Ranges that now run into each other are one booking, which is the
        // existing system's own rule about what counts as a separate range;
        // ranges that merely sit near each other are left alone.
        $this->consolidation->consolidate($project);

        return [
            'action' => 'update',
            'before' => $before,
            'after' => $schedule->refresh()->describe(),
        ];
    }

    /**
     * What one submitted range means, checked by the rules that own the
     * question.
     *
     * @param  array<string, mixed>  $input
     * @return array{mode: string, start: CarbonImmutable, end: CarbonImmutable}
     *
     * @throws RuntimeException
     */
    private function interpret(Project $project, Schedule $schedule, array $input): array
    {
        $validator = Validator::make([], []);

        $range = $this->scheduleRules->validateEntry(
            $validator,
            $input,
            '',
            $this->partialDayAllowed($project),
            true,
            $schedule
        );

        if (! $range) {
            throw new RuntimeException($validator->errors()->first() ?: 'Those dates cannot be used.');
        }

        // Changing what kind of booking a saved range is has its own rules.
        // This dialog always submits the whole new range - a partial day
        // arrives with the single date it is for - so there is no day left to
        // guess at, which is the thing that rule exists to refuse.
        $this->scheduleRules->assertConvertible($schedule, $range['mode'], true);

        return $range;
    }

    // ------------------------------------------------------------------
    // Payloads
    // ------------------------------------------------------------------

    /**
     * What the dialog calls itself, and where its buttons post.
     *
     * Carried in the report rather than written into the page, so one dialog
     * and one script serve every flow and no page holds a second opinion about
     * which endpoint resolves what.
     *
     * @return array<string, mixed>
     */
    private function flowPayload(Project $project, string $flow): array
    {
        $id = (int) $project->project_id;

        return match ($flow) {
            self::FLOW_RESUME => [
                'key' => self::FLOW_RESUME,
                'eyebrow' => 'Resume blocked',
                'action_label' => 'Resume Project',
                'action_icon' => 'bi-play-circle',
                'commit_url' => route('super-admin.projects.resume', $id),
                'conflicts_url' => route('super-admin.projects.resume-conflicts', $id),
                'blocked_summary' => 'This project\'s proposed schedule conflicts with the current availability '
                    .'of its team. Review the affected schedule ranges before resuming the project.',
                'clear_summary' => 'Every current and future schedule range is available. '
                    .'This project can be resumed.',
                'clear_note' => 'No conflicts remain - this project can be resumed.',
                'blocked_note' => 'There are still schedule conflicts to resolve.',
                'failure' => 'Unable to resume project. Nothing was changed.',
            ],
            default => [
                'key' => self::FLOW_RESTORE,
                'eyebrow' => 'Restore blocked',
                'action_label' => 'Restore Project',
                'action_icon' => 'bi-arrow-counterclockwise',
                'commit_url' => route('super-admin.projects.restore', $id),
                'conflicts_url' => route('super-admin.projects.restore-conflicts', $id),
                'blocked_summary' => 'This project\'s schedule conflicts with the current availability of its '
                    .'team. Review the affected schedule ranges before restoring the project.',
                'clear_summary' => 'Every current and future schedule range is available. '
                    .'This project can be restored.',
                'clear_note' => 'No conflicts remain - this project can be restored.',
                'blocked_note' => 'There are still schedule conflicts to resolve.',
                'failure' => 'Unable to restore project. Nothing was changed.',
            ],
        };
    }

    /**
     * @param  Collection<int, Schedule>  $schedules
     * @param  Collection<int, int>  $technicianIds
     * @return array<string, mixed>
     */
    private function projectPayload(
        Project $project,
        string $flow,
        Collection $schedules,
        Collection $technicianIds,
        bool $partialDayAllowed
    ): array {
        $window = $this->partialDayWindow();

        return [
            'project_id' => (int) $project->project_id,
            'code' => $project->displayCode(),
            'reference_no' => $project->reference_no ?? $project->name,
            'name' => $project->name,
            'heading' => $flow === self::FLOW_RESUME ? 'Resuming Project' : 'Restoring Project',
            'returns_as' => $this->returnsAsLabel($project, $flow),
            'claims_dates' => $this->wouldClaimDates($project, $flow),
            // Read off the project's client record, which is the only place
            // the answer actually lives. Sent so the dialog can say WHY the
            // Partial Day option is or is not there, never so the browser can
            // decide it.
            'client_type' => $project->clientType(),
            'partial_day_allowed' => $partialDayAllowed,
            'partial_day_window' => [
                'start' => sprintf('%02d:00', $window['start']),
                'end' => sprintf('%02d:00', $window['end']),
                'start_label' => $window['start_label'],
                'end_label' => $window['end_label'],
            ],
            'technician_count' => $technicianIds->count(),
            'team' => $project->loadMissing('projectTechnicians.technician')->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?string => $assignment->technician?->name)
                ->filter()
                ->values()
                ->all(),
            'schedule_label' => $schedules->isEmpty()
                ? 'No dates'
                : $schedules->map(fn (Schedule $schedule): string => $schedule->describe())->implode(', '),
            'update_url' => $flow === self::FLOW_RESUME
                ? route('super-admin.projects.resume-schedule', $project->project_id)
                : route('super-admin.projects.restore-schedule', $project->project_id),
        ];
    }

    private function returnsAsLabel(Project $project, string $flow): string
    {
        if ($flow === self::FLOW_RESUME) {
            // A resume works the status out from the dates that are left - see
            // ProjectStatusRules - so there is no single answer to promise
            // here, and inventing one would be the dialog guessing.
            return 'Its status is worked out from the dates below';
        }

        $returnsAs = $project->statusToRestore();

        return $returnsAs ? Project::statusLabelFor($returnsAs) : 'Unscheduled';
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
        Schedule $schedule,
        Collection $schedules,
        bool $past,
        bool $screened,
        bool $partialDayAllowed,
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

        $startTime = CarbonImmutable::parse($schedule->start_datetime)->format('H:i');
        $endTime = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('H:i');
        $window = $this->partialDayWindow();

        return [
            'schedule_id' => (int) $schedule->schedule_id,
            'label' => $schedule->describe(),
            'scheduling_mode' => $schedule->scheduling_mode ?? Schedule::MODE_DATE_BASED,
            'partial_day' => $schedule->isPartialDay(),
            // Whether this row may be booked as hours. Residential only, the
            // same rule every other scheduling screen applies, answered from
            // the project's stored client record.
            'partial_day_allowed' => $partialDayAllowed,
            'start_date' => $schedule->startsOn()->toDateString(),
            'end_date' => $schedule->endsOn()->toDateString(),
            'project_date' => $schedule->startsOn()->toDateString(),
            'start_time' => $schedule->isPartialDay() ? $startTime : null,
            'end_time' => $schedule->isPartialDay() ? $endTime : null,
            // The configured Partial Day Start Hour and End Hour, offered on
            // every range rather than only on one that already holds hours:
            // turning a whole-day range into a partial day is exactly what
            // this dialog is for, and the hours have to be there to pick.
            // Sent rather than built in the browser so the dialog offers
            // precisely what the save will accept, and so no second copy of
            // the working day lives in a script.
            'hour_options' => $schedule->isPartialDay()
                ? $this->hourOptions($startTime, $endTime)
                : $this->hourOptions(),
            // What a range being converted starts on, so the selects are never
            // empty and the administrator sees the configured window at once.
            // They may still pick any other hour the window offers - see
            // ScheduleModeRules, which is what decides whether it is accepted.
            'default_start_time' => $schedule->isPartialDay()
                ? $startTime
                : sprintf('%02d:00', $window['start']),
            'default_end_time' => $schedule->isPartialDay()
                ? $endTime
                : sprintf('%02d:00', $window['end']),
            'partial_day_start_label' => $window['start_label'],
            'partial_day_end_label' => $window['end_label'],
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
            // Never earlier than today whatever the row says: a recovery books
            // work still to come, and a past date is not that.
            'earliest_start' => $this->laterOf($limits['earliestStart'], $today),
            'earliest_end' => $this->laterOf($limits['earliestEnd'], $today),
            'conflict' => $conflicts->isEmpty() ? null : $this->conflictDetail($conflicts),
            // The days an edit of THIS range may not land on: what the team is
            // spoken for on elsewhere, plus the days this project's own other
            // ranges already hold.
            'blocked_dates' => $past
                ? ['whole_day' => [], 'partial_day' => []]
                : $this->withOwnRanges($blockedDates, $schedules, $schedule, $today, null),
        ];
    }

    /**
     * Why a range is refused, as supporting detail rather than as the subject.
     *
     * The subject is the range. This is the answer to "why?" for somebody who
     * asks it - who is unavailable, when, and what has them - which is what
     * turns a red badge into something a person can act on.
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
                // Which hours of those days are taken, when the clash is
                // between two hours-only bookings. This is what stops
                // "unavailable on Aug 6" reading as a whole day gone when only
                // the morning is, and it is what makes moving the range to the
                // afternoon an obvious move rather than a guess.
                'busy_label' => ($conflict['partial'] ?? false) && ($conflict['busy'] ?? []) !== []
                    ? $this->availability->describeBusy($conflict['busy'])
                    : null,
                'projects' => $conflict['projects'] ?? [],
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $conflicts
     */
    private function summaryMessage(string $flow, Collection $conflicts): string
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

        [$opening, $closing] = $flow === self::FLOW_RESUME
            ? [
                'Unable to resume - the days this project still holds are now booked elsewhere. ',
                ' Reschedule that work or remove them from this team.',
            ]
            : [
                'Unable to restore - the dates this project still holds are now booked elsewhere. ',
                ' Reschedule that work or remove them from this team, then restore it again.',
            ];

        return $opening.$this->availability->conflictMessage($merged, $closing);
    }

    /**
     * Whether the ranges below would start occupying technicians again.
     */
    private function wouldClaimDates(Project $project, string $flow): bool
    {
        return match ($flow) {
            // A held project is coming back to work by definition. Which
            // status it lands on is derived from its dates afterwards, and
            // every one of those books the crew - see ProjectStatusRules.
            self::FLOW_RESUME => (bool) $project->on_hold && ! $project->isReadOnly(),
            default => $project->restoreWouldClaimDates(),
        };
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
     * The days this project's team is spoken for, over the window a picker
     * offers.
     *
     * The project's own bookings are left out, which is what makes the
     * question answerable at all: every day being asked about is one it holds
     * itself, so without the exclusion every range would read as its own
     * blocker. Its other ranges are added back per range, where they belong -
     * see withOwnRanges().
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
     * The team's busy days, plus the days the project's OWN ranges hold.
     *
     * A project may not book itself twice over the same time - the same
     * self-overlap rule the schedules page and the reopen dialog enforce - so
     * a range being moved has to see its siblings as taken, and a brand new
     * range has to see all of them. The range being edited keeps its own days
     * open, which is what lets it be shortened or nudged.
     *
     * A whole-day booking of its own takes every hour of the days it covers
     * and so blocks an hours-only booking too; a partial-day one leaves the
     * rest of that day open, so it closes only the whole-day list.
     *
     * @param  array{whole_day: array<int, string>, partial_day: array<int, string>}  $blocked
     * @param  Collection<int, Schedule>  $schedules
     * @return array{whole_day: array<int, string>, partial_day: array<int, string>}
     */
    private function withOwnRanges(
        array $blocked,
        Collection $schedules,
        ?Schedule $editing,
        CarbonImmutable $today,
        ?CarbonImmutable $horizon
    ): array {
        $wholeDay = array_flip($blocked['whole_day']);
        $partialDay = array_flip($blocked['partial_day']);
        $floor = $today->toDateString();
        $ceiling = $horizon?->toDateString();

        foreach ($schedules as $schedule) {
            if ($editing && (int) $schedule->schedule_id === (int) $editing->schedule_id) {
                continue;
            }

            foreach ($this->datesOf($schedule) as $date) {
                if ($date < $floor || ($ceiling !== null && $date > $ceiling)) {
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
