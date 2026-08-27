<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ProjectStatusRules;
use App\Services\ProjectTeam;
use App\Services\ScheduleConsolidation;
use App\Services\ScheduleDateRemoval;
use App\Services\ScheduleModeRules;
use App\Services\TaskScheduleRules;
use App\Services\TechnicianAvailabilityService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class ScheduleController extends Controller
{
    /**
     * Project statuses whose schedules count as "busy" time for a technician.
     *
     * @var array<int, string>
     */
    private const ACTIVE_PROJECT_STATUSES = ['pending', 'ongoing'];

    /**
     * Statuses that can be booked onto a date from the calendar.
     *
     * Wider than ACTIVE_PROJECT_STATUSES on purpose: an unscheduled
     * project is the main thing you would want to schedule. Restoring an
     * archived project leaves it in exactly that state, with its schedule
     * released, so it has to be reachable here.
     *
     * @var array<int, string>
     */
    private const SCHEDULABLE_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly ProjectTeam $projectTeam,
        private readonly TaskScheduleRules $taskScheduleRules
    ) {}

    public function index()
    {
        $projects = Project::query()
            ->with([
                'clients',
                'schedules',
                'projectTechnicians.technician.account',
            ])
            ->where('is_archived', false)
            // The working set: everything this page can still act on. Cancelled
            // work is not in it, because the table's Edit Schedule and the
            // needs-scheduling panel are both offers to change dates and a
            // called-off job has none to change. It is fetched separately
            // below for the calendar, which only reports what happened.
            ->where('status', '!=', 'cancelled')
            ->orderBy('project_id', 'desc')
            ->get();

        // A project holding no dates is Unscheduled: there is nothing to draw
        // on the calendar and nothing to list, so it appears on neither. Its
        // edit modal is still rendered - that is what the Update Schedule link
        // on the project's own page opens - which is how it gets dates again.
        $scheduledProjects = $projects
            ->filter(fn (Project $project): bool => $project->schedules->isNotEmpty())
            ->values();

        // The other half: work that is not on the calendar where it should be.
        // Two kinds sit here, and both are put right the same way - by giving
        // the project dates it does not currently hold:
        //
        //   Unscheduled - no dates at all, so there is nothing to draw.
        //   Overdue     - every date it holds has passed while the work is
        //                 still open, so the calendar shows it only in the past.
        //
        // On-hold projects are left out of both - a hold releases the dates and
        // the team, and the project has to be resumed before it can be booked,
        // which is the project page's to do.
        $needsSchedulingProjects = $projects
            ->filter(fn (Project $project): bool => ! $project->on_hold
                && ! $project->isReadOnly()
                && ($project->schedules->isEmpty() || $project->isOverdue()))
            // Overdue first: a date already missed is the more pressing of the
            // two, and within each group the names read alphabetically.
            ->sortBy(fn (Project $project): string => ($project->isOverdue() ? '0' : '1').mb_strtolower($project->name))
            ->values();

        // Cancelled work reaches the calendar and nothing else on this page.
        // The table lists what can still be scheduled and the needs-scheduling
        // panel lists what is waiting to be; a job that was called off is
        // neither, and putting it in either would offer an action that cannot
        // be taken. The calendar is a different kind of thing - a record of
        // what happened on which day - and weeks that were worked before a
        // cancellation read as empty without it.
        //
        // Only the days actually worked are drawn. cancel() keeps the whole
        // schedule for the record rather than trimming it, so the bars are
        // stopped at the cancellation date by buildCalendarEvents() - see
        // Project::calendarCutoff().
        $cancelledProjects = Project::query()
            ->with([
                'clients',
                'schedules',
                'projectTechnicians.technician.account',
            ])
            ->where('is_archived', false)
            ->where('status', 'cancelled')
            // Nothing to trim a schedule against, so nothing that can be drawn
            // honestly. Left off, exactly as dateDetails() leaves it off.
            ->whereNotNull('cancelled_at')
            ->has('schedules')
            ->orderBy('project_id', 'desc')
            ->get();

        // Everything the calendar draws a bar for. Clicking a bar opens that
        // project's schedule panel, so the view has to render one for each of
        // these or a cancelled bar would be a click that does nothing.
        $calendarProjects = $scheduledProjects->concat($cancelledProjects);

        $calendarEvents = $this->buildCalendarEvents($calendarProjects);
        $technicianSchedules = $this->buildTechnicianSchedules();
        $technicianNames = $this->buildTechnicianNames();
        // project_id => reference number, so a clash can say WHICH job is
        // holding the technician. Without it the browser can only report that
        // somebody is unavailable, which reads as an unexplained refusal - and
        // when the date falls inside the range being edited, reads as the
        // project blocking itself.
        $projectLabels = $projects
            ->mapWithKeys(fn (Project $project): array => [
                (int) $project->project_id => $project->reference_no ?: $project->name,
            ])
            ->all();
        // Stated by the model so the dropdowns and the server agree on which
        // hours exist without the list being written out twice. The bounds go
        // with them for the page's own availability narrowing, which works in
        // hours rather than in options - one setting, read once.
        $workingHours = Schedule::workingHourOptions();
        $partialDayHours = Schedule::partialDayHourBounds();

        // Whether this reader may correct a booking that has already ended.
        // Decided once here rather than re-derived per row in the view, and
        // re-checked on the way in - this only governs what is drawn.
        $mayOverrideLock = (bool) request()->user()?->isSuperAdmin();

        return view('super-admin.schedule', compact(
            'projects',
            'scheduledProjects',
            'needsSchedulingProjects',
            'calendarProjects',
            'calendarEvents',
            'technicianSchedules',
            'technicianNames',
            'projectLabels',
            'workingHours',
            'partialDayHours',
            'mayOverrideLock'
        ));
    }

    /**
     * Projects whose schedule covers the clicked calendar date.
     *
     * Cancelled work is listed here and nowhere else on this page. The
     * calendar draws none of it - see Project::showsOnCalendar() - because a
     * bar spanning a fortnight would go on advertising dates the job was
     * called off before reaching. A single day is a different question: it
     * either was worked or it was not, and the days a cancelled project worked
     * before it stopped are part of the record of that day.
     *
     * Archived work stays out. An archive is reversible - restoring puts the
     * dates back, which is what ProjectScheduleRecovery exists to police - so
     * its ranges are dormant rather than finished, and a panel that reads as
     * history is the wrong place to show them.
     */
    public function dateDetails(string $date)
    {
        try {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } catch (Throwable $e) {
            return response()->json(['error' => 'Invalid date.'], 422);
        }

        $dayString = $day->toDateString();

        // Cancelling does not shorten a schedule the way completing or holding
        // one does. ProjectCompletion::releaseFutureSchedules() and
        // ScheduleHoldCutoff both cut the rows off at a line; cancel()
        // deliberately keeps every range intact for the record and leaves the
        // technicians free by another route. So a cancelled project still
        // holds the dates it would have worked, and the line has to be drawn
        // here or the panel would show a crew on site for a job that had
        // already stopped.
        //
        // Two bounds, and the nearer one wins. The cancellation date, because
        // nothing was worked after the job was called off. And today, because
        // a day that has not arrived cannot have been worked either - and the
        // cancellation date is typed in by hand rather than derived, so
        // nothing stops it being tomorrow.
        $listsCancelled = $dayString <= Schedule::businessToday()->toDateString();

        $projects = Project::query()
            ->with([
                'clients',
                'schedules',
                // The whole membership history, not the team as it stands:
                // this panel is asked about one day, and the answer is who was
                // on the project THAT day. See the technicians key below.
                'teamHistory.technician.account',
            ])
            ->where('is_archived', false)
            ->where(function ($query) use ($dayString, $listsCancelled): void {
                $query->where('status', '!=', 'cancelled');

                if (! $listsCancelled) {
                    return;
                }

                $query->orWhere(function ($cancelled) use ($dayString): void {
                    $cancelled->where('status', 'cancelled')
                        // A project cancelled before the date was recorded
                        // says nothing about when its work stopped. Left out
                        // rather than guessed at: showing it would be a claim
                        // about the day that nothing in the row supports.
                        ->whereNotNull('cancelled_at')
                        ->whereDate('cancelled_at', '>=', $dayString);
                });
            })
            ->whereHas('schedules', function ($query) use ($dayString): void {
                $query->whereDate('start_datetime', '<=', $dayString)
                    ->whereDate('end_datetime', '>=', $dayString);
            })
            ->orderBy('project_id', 'desc')
            ->get();

        return response()->json([
            'date' => $dayString,
            'label' => $day->format(BusinessTime::DATE),
            'projects' => $projects->map(function (Project $project) use ($dayString): array {
                // Only the range(s) that actually cover the clicked day.
                $covering = $project->schedules->filter(function (Schedule $schedule) use ($dayString): bool {
                    $start = CarbonImmutable::parse($schedule->start_datetime)->toDateString();
                    $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->toDateString();

                    return $start <= $dayString && $end >= $dayString;
                })->values();

                return [
                    'project_id' => $project->project_id,
                    'reference_no' => $project->reference_no,
                    'name' => $project->name,
                    'client' => $project->clients->first()?->fullname
                        ?? $project->clients->first()?->company_name,
                    'status' => $project->status,
                    'status_label' => $this->statusLabel($project),
                    'on_hold' => (bool) $project->on_hold,
                    // A cancelled project reaches this panel only for days it
                    // worked, so the badge alone would read as though the job
                    // was called off on the day being looked at. The date says
                    // otherwise, and it is the one thing that explains why a
                    // cancelled job is on a calendar day at all.
                    'is_cancelled' => $project->isCancelled(),
                    'cancelled_on' => $project->cancelled_at
                        ? CarbonImmutable::parse($project->cancelled_at)->format(BusinessTime::DATE)
                        : null,
                    // Completed work is a historical record and paused work is
                    // waiting to be resumed: both are listed here, and neither
                    // one's dates can be changed - exactly as on the calendar
                    // and in the table. Asked of the model so this panel and
                    // the endpoint behind Remove This Date agree.
                    'read_only' => ! $project->scheduleIsEditable(),
                    'url' => route('super-admin.projects.show', $project->project_id),
                    // The crew as this day had it, not as the project has it
                    // now. Somebody taken off last week still belongs against
                    // the days they were here for, and somebody who joined
                    // yesterday does not belong against last month - the two
                    // halves of the same question, and the second is the one
                    // nobody notices until an audit asks.
                    //
                    // A closed membership is listed with the date it closed
                    // rather than dropped, because "who was on site" and "who
                    // is on the team" are different questions and this panel
                    // is only ever asking the first.
                    'technicians' => $project->crewOn($dayString)
                        ->filter(fn (ProjectTechnician $assignment): bool => $assignment->technician !== null)
                        ->map(fn (ProjectTechnician $assignment): array => [
                            'name' => $assignment->technician->name,
                            'removed_on' => $assignment->isRemoved()
                                ? CarbonImmutable::parse($assignment->removed_at)->format(BusinessTime::DATE)
                                : null,
                        ])
                        ->values()
                        ->all(),
                    // One entry per booking covering the day, each removable on
                    // its own: a Residential project can hold two partial days
                    // on one date, and "remove this date" has to say which.
                    'ranges' => $covering->map(fn (Schedule $schedule): array => [
                        'schedule_id' => $schedule->schedule_id,
                        'start' => $schedule->startsOn()->toDateString(),
                        'end' => $schedule->endsOn()->toDateString(),
                        'label' => $schedule->describe(),
                        'remove_url' => route('super-admin.schedules.dates.destroy', [
                            'schedule' => $schedule->schedule_id,
                            'date' => $dayString,
                        ]),
                    ])->all(),
                ];
            })->values(),
        ]);
    }

    /**
     * Projects that can take on the given date range.
     *
     * A project qualifies when it is schedulable (unscheduled, pending
     * or ongoing, and neither on hold nor archived), none of its own ranges
     * already cover these dates, and every technician on it is continuously
     * free for the whole range.
     *
     * The response also carries each project's technician ids so the browser
     * can grey out projects that share a technician the moment one is picked,
     * without another round trip.
     */
    public function assignableProjects(Request $request)
    {
        // Validated by hand rather than via $request->validate(): the app only
        // renders exceptions as JSON for api/* paths (see bootstrap/app.php),
        // so a thrown ValidationException here would redirect with HTML.
        $scheduleRules = app(ScheduleModeRules::class);
        $validator = Validator::make($request->all(), $scheduleRules->rules());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        // Screening only reports who could take the slot; whether the slot is
        // itself in the past is assign()'s to refuse, exactly as before.
        $entry = $scheduleRules->validateEntry(
            $validator,
            $request->only([
                'scheduling_mode',
                'start_date',
                'end_date',
                'project_date',
                'start_time',
                'end_time',
            ]),
            '',
            true,
            true
        );

        if (! $entry) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $isPartialDay = $entry['mode'] === Schedule::MODE_PARTIAL_DAY;

        $candidates = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->whereIn('status', self::SCHEDULABLE_STATUSES)
            ->where('is_archived', false)
            ->where(function ($query): void {
                $query->where('on_hold', false)->orWhereNull('on_hold');
            })
            ->orderBy('name')
            ->get();

        // One query covers availability for every technician on every
        // candidate project, tagged with the project standing in the way.
        $allTechnicianIds = $candidates
            ->flatMap(fn (Project $project) => $project->projectTechnicians->pluck('technician_id'))
            ->filter()
            ->unique()
            ->values();

        $blockers = app(TechnicianAvailabilityService::class)
            ->conflictingProjectsByTechnician($allTechnicianIds, [$entry]);

        $eligible = [];
        $blocked = [];

        foreach ($candidates as $project) {
            $projectId = (int) $project->project_id;

            // Partial days are a Residential offering. Commercial work is
            // listed as unavailable rather than quietly dropped, so a project
            // somebody expected to see is never simply missing.
            if ($isPartialDay && ! $project->isResidential()) {
                $blocked[] = $this->projectPayload(
                    $project,
                    'Partial Day scheduling is for Residential projects only.'
                );

                continue;
            }

            // A project can't be booked over time it already occupies.
            $selfOverlap = $project->schedules->contains(
                fn (Schedule $schedule): bool => $scheduleRules->overlaps($entry, $schedule->occupiedInterval())
            );

            if ($selfOverlap) {
                $blocked[] = $this->projectPayload(
                    $project,
                    $isPartialDay
                        ? 'Already scheduled at this time.'
                        : 'Already scheduled during these dates.'
                );

                continue;
            }

            $unavailableNames = [];

            foreach ($project->projectTechnicians as $projectTechnician) {
                $owners = $blockers[(int) $projectTechnician->technician_id] ?? [];

                // Time this same project booked doesn't count against it.
                unset($owners[$projectId]);

                if ($owners === []) {
                    continue;
                }

                $name = $projectTechnician->technician?->name;

                if ($name) {
                    $unavailableNames[$name] = true;
                }
            }

            if ($unavailableNames !== []) {
                $blocked[] = $this->projectPayload(
                    $project,
                    implode(', ', array_keys($unavailableNames))
                        .(count($unavailableNames) === 1 ? ' is' : ' are')
                        .($isPartialDay
                            ? ' booked on another project at this time.'
                            : ' booked on another project during these dates.')
                );

                continue;
            }

            $eligible[] = $this->projectPayload($project);
        }

        return response()->json([
            'start_date' => $entry['start']->toDateString(),
            'end_date' => $entry['end']->toDateString(),
            'scheduling_mode' => $entry['mode'],
            'schedule_label' => $this->describeRangeEntry($entry),
            'projects' => $eligible,
            'blocked' => $blocked,
        ]);
    }

    /**
     * Book the chosen projects into the given date range.
     *
     * Everything the browser already checked is re-checked here, so a stale
     * page or a simultaneous edit by someone else can't slip a conflicting
     * booking through.
     */
    public function assign(Request $request)
    {
        // Hand-rolled for the same reason as assignableProjects(): this
        // endpoint must always answer with JSON, never an HTML redirect.
        $scheduleRules = app(ScheduleModeRules::class);

        $validator = Validator::make($request->all(), [
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['required', 'integer', 'exists:tbl_projects,project_id'],
            ...$scheduleRules->rules(),
        ], [
            'project_ids.required' => 'Select at least one project to schedule.',
            'project_ids.min' => 'Select at least one project to schedule.',
            ...$scheduleRules->messages(),
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();

        $entry = $scheduleRules->validateEntry(
            $validator,
            $request->only([
                'scheduling_mode',
                'start_date',
                'end_date',
                'project_date',
                'start_time',
                'end_time',
            ])
        );

        if (! $entry) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $scheduleLabel = $this->describeRangeEntry($entry);

        $projects = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->whereIn('project_id', $validated['project_ids'])
            ->get();

        $availability = app(TechnicianAvailabilityService::class);

        try {
            DB::transaction(function () use ($projects, $entry, $scheduleLabel, $scheduleRules, $availability): void {
                $claimedTechnicians = [];

                foreach ($projects as $project) {
                    $this->assertProjectSchedulable($project);
                    $this->assertPartialDayAllowed($project, $entry['mode']);
                    $this->assertNoSelfOverlap($project, $entry, $scheduleRules);

                    $technicianIds = $project->projectTechnicians
                        ->pluck('technician_id')
                        ->filter()
                        ->unique()
                        ->values();

                    // Conflicts against everything already in the database.
                    $availability->assertContinuouslyAvailable(
                        $technicianIds,
                        [$entry],
                        $project->project_id
                    );

                    // Conflicts between the projects being saved right now,
                    // which share no rows yet and so can't be caught above.
                    foreach ($technicianIds as $technicianId) {
                        if (isset($claimedTechnicians[$technicianId])) {
                            throw new RuntimeException(sprintf(
                                '%s is on both %s and %s and cannot share this schedule.',
                                $project->projectTechnicians
                                    ->firstWhere('technician_id', $technicianId)?->technician?->name
                                    ?? 'A technician',
                                $claimedTechnicians[$technicianId],
                                $project->name
                            ));
                        }

                        $claimedTechnicians[$technicianId] = $project->name;
                    }

                    $schedule = Schedule::create([
                        'project_id' => $project->project_id,
                        'start_datetime' => $entry['start'],
                        'end_datetime' => $entry['end'],
                        'scheduling_mode' => $entry['mode'],
                        'status' => 'scheduled',
                        'remarks' => 'Added from the schedules calendar',
                    ]);

                    $this->projectTeam->linkScheduleToTeam($schedule, $project);

                    // A date booked from the calendar often butts straight onto
                    // what the project already holds, which is one booking
                    // rather than two.
                    app(ScheduleConsolidation::class)->consolidate($project);

                    $this->syncStatusWithSchedule($project);

                    // Queued inside the transaction but only written once it
                    // commits, so a later project failing takes this with it.
                    $this->activityLogger->record(
                        ActivityLog::PROJECT_RESCHEDULED,
                        null,
                        sprintf(
                            "Scheduled '%s' for %s from the calendar.",
                            $project->reference_no ?? $project->name,
                            $scheduleLabel
                        ),
                        $project
                    );

                    $this->notifications->projectScheduled($project, $scheduleLabel);
                }
            });
        } catch (Throwable $e) {
            return response()->json([
                'error' => $this->safeErrorMessage($e, 'Unable to save schedule. Nothing was changed.'),
            ], 422);
        }

        return response()->json([
            'message' => $projects->count() === 1
                ? $projects->first()->name.' scheduled.'
                : $projects->count().' projects scheduled.',
        ]);
    }

    /**
     * Every range a project holds, for the before/after halves of a
     * reschedule log entry.
     *
     * @param  Collection<int, Schedule>  $schedules
     */
    /**
     * A project's ranges as individual labels, for diffing.
     *
     * describeSchedules() joins the same labels into one string for prose.
     * This keeps them apart, because "what changed" is a question about the
     * set rather than about the sentence.
     *
     * @param  Collection<int, Schedule>  $schedules
     * @return array<int, string>
     */
    private function scheduleLabels(Collection $schedules): array
    {
        return $schedules
            ->sortBy('start_datetime')
            ->map(fn (Schedule $schedule): string => $schedule->describe())
            ->values()
            ->all();
    }

    /**
     * What actually changed between two sets of ranges, as a short phrase.
     *
     * The log used to print both sets in full - "changed the date ranges from
     * A; B; C; D to A; B; C; E" - which put the whole schedule on the page
     * twice and left the reader to find the one range that moved. A project
     * holding four ranges produced a line nobody could read, for a change that
     * touched one of them.
     *
     * The common case is one range out and one in, which is a range being
     * edited rather than replaced, and it is worth saying that way: "changed
     * Aug 1 - Aug 5 to Aug 1 - Aug 8". Anything else is listed as what was
     * added and what was dropped. Ranges that did not move are never
     * mentioned.
     *
     * Returns an empty string when the two sets match, so a caller can leave
     * the clause out rather than print "changed nothing".
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    private function describeScheduleChange(array $before, array $after): string
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return '';
        }

        if (count($added) === 1 && count($removed) === 1) {
            return sprintf('changed %s to %s', $removed[0], $added[0]);
        }

        $parts = [];

        if ($added !== []) {
            $parts[] = 'added '.implode(', ', $added);
        }

        if ($removed !== []) {
            $parts[] = 'removed '.implode(', ', $removed);
        }

        return implode('; ', $parts);
    }

    private function describeSchedules(Collection $schedules): string
    {
        if ($schedules->isEmpty()) {
            return 'no dates';
        }

        return $schedules
            ->sortBy('start_datetime')
            // describe() is what puts the hours in the log for a partial day,
            // so "changed from X to Y" stays true for both kinds of schedule.
            ->map(fn (Schedule $schedule): string => $schedule->describe())
            ->implode('; ');
    }

    /**
     * Shape a project for the assignable-projects response.
     */
    private function projectPayload(Project $project, ?string $reason = null): array
    {
        return [
            'project_id' => $project->project_id,
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'client' => $project->clients->first()?->fullname
                ?? $project->clients->first()?->company_name,
            'status' => $project->status,
            'status_label' => $this->statusLabel($project),
            'url' => route('super-admin.projects.show', $project->project_id),
            'technician_ids' => $project->projectTechnicians
                ->pluck('technician_id')
                ->filter()
                ->map(fn ($technicianId): int => (int) $technicianId)
                ->values()
                ->all(),
            'technicians' => $project->projectTechnicians
                ->map(fn ($projectTechnician) => $projectTechnician->technician?->name)
                ->filter()
                ->values()
                ->all(),
            'reason' => $reason,
        ];
    }

    /**
     * Delegates to the model so every screen agrees, including on Overdue.
     */
    private function statusLabel(Project $project): string
    {
        return $project->statusLabel();
    }

    private function assertProjectSchedulable(Project $project): void
    {
        if ($project->isReadOnly()) {
            throw new RuntimeException(sprintf(
                '%s is %s and can no longer be scheduled.',
                $project->name,
                $project->status
            ));
        }

        if ($project->on_hold) {
            throw new RuntimeException($project->name.' is on hold and cannot be scheduled.');
        }

        if (! in_array($project->status, self::SCHEDULABLE_STATUSES, true)) {
            throw new RuntimeException(sprintf(
                '%s cannot be scheduled from the calendar while it is %s.',
                $project->name,
                $this->statusLabel($project)
            ));
        }
    }

    /**
     * Partial days are a Residential offering, and the calendar books one
     * shared window across every project picked - so a Commercial project in
     * that selection is refused by name rather than downgraded to a whole day.
     */
    private function assertPartialDayAllowed(Project $project, string $mode): void
    {
        if ($mode !== Schedule::MODE_PARTIAL_DAY || $project->isResidential()) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s is a Commercial project. Partial Day scheduling is for Residential projects only.',
            $project->name
        ));
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, mode: string}  $entry
     */
    private function assertNoSelfOverlap(Project $project, array $entry, ScheduleModeRules $scheduleRules): void
    {
        $overlaps = $project->schedules->contains(
            fn (Schedule $schedule): bool => $scheduleRules->overlaps($entry, $schedule->occupiedInterval())
        );

        if ($overlaps) {
            throw new RuntimeException($project->name.' already has a schedule covering this time.');
        }
    }

    public function update(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians', 'clients'])->findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.schedules.index')
                ->with('error', 'This project is '.$project->status.' and its schedule can no longer be changed.');
        }

        $scheduleRules = app(ScheduleModeRules::class);

        // A project is allowed to hold no dates at all. Submitting an empty
        // form gives every one of them up, which leaves the project
        // Unscheduled and takes it off the calendar and the table until it is
        // booked again - the same end state as removing its last date.
        $validated = $request->validate([
            'ranges' => ['nullable', 'array'],
            'ranges.*.schedule_id' => ['nullable', 'integer', 'exists:tbl_schedule,schedule_id'],
            'override_past_lock' => ['nullable', 'boolean'],
            ...$scheduleRules->rules('ranges.*.'),
        ], $scheduleRules->messages('ranges.*.'));

        // A Super Admin correcting a booking that has ended, having confirmed
        // they mean to. Read from the account rather than from the form alone:
        // the flag says the confirmation was given, the role says it counts.
        $mayOverrideLock = (bool) ($validated['override_past_lock'] ?? false)
            && (bool) $request->user()?->isSuperAdmin();

        // Read before anything moves, so the log can say what changed.
        $rangesBefore = $this->describeSchedules($project->schedules);
        $labelsBefore = $this->scheduleLabels($project->schedules);
        $overrode = false;

        try {
            DB::transaction(function () use ($validated, $project, $scheduleRules, $mayOverrideLock, &$overrode): void {
                $ranges = $this->resolveSubmittedRanges(
                    $project,
                    $validated['ranges'] ?? [],
                    $scheduleRules,
                    $mayOverrideLock
                );

                $this->assertNoOverlapWithinSubmission($ranges, $scheduleRules);
                $this->assertRangesAvailable($project, $ranges);

                $keepScheduleIds = $ranges->pluck('schedule_id')->filter()->values();

                // A booking that has ended is the record of work that
                // happened, and it survives whatever the form sends. Omitting
                // one is how the editor submits a row it drew read-only, so
                // treating an omission as a deletion would quietly destroy
                // history every time somebody edited a future range.
                $project->schedules()
                    ->whereNotIn('schedule_id', $keepScheduleIds->all())
                    ->get()
                    ->each(function (Schedule $schedule) use ($mayOverrideLock, &$overrode): void {
                        if ($schedule->isLocked() && ! $mayOverrideLock) {
                            return;
                        }

                        if ($schedule->isLocked()) {
                            $overrode = true;
                        }

                        $schedule->delete();
                    });

                $ranges->each(function (array $range) use ($project, &$overrode): void {
                    if ($range['schedule_id']) {
                        if ($range['used_override']) {
                            $overrode = true;
                        }

                        Schedule::query()
                            ->where('project_id', $project->project_id)
                            ->where('schedule_id', $range['schedule_id'])
                            ->update([
                                'start_datetime' => $range['start'],
                                'end_datetime' => $range['end'],
                                'scheduling_mode' => $range['mode'],
                            ]);

                        return;
                    }

                    $schedule = Schedule::create([
                        'project_id' => $project->project_id,
                        'start_datetime' => $range['start'],
                        'end_datetime' => $range['end'],
                        'scheduling_mode' => $range['mode'],
                        'status' => 'scheduled',
                        'remarks' => 'Added from schedules page',
                    ]);

                    $this->projectTeam->linkScheduleToTeam($schedule, $project);
                });

                // Ranges that run into each other are one booking, so they are
                // merged before anything downstream reads them - including the
                // task sync just below, which must measure tasks against the
                // shape that was actually stored.
                app(ScheduleConsolidation::class)->consolidate($project);

                $this->syncTaskDatesWithSchedule($project);
                // Status follows the dates in every direction: given up
                // entirely, moved forward, or moved into the past.
                $this->syncStatusWithSchedule($project);
            });

            $project->unsetRelation('schedules');
            $stored = $project->schedules()->orderBy('start_datetime')->get();
            $rangesAfter = $this->describeSchedules($stored);
            $change = $this->describeScheduleChange($labelsBefore, $this->scheduleLabels($stored));

            $this->activityLogger->record(
                ActivityLog::PROJECT_RESCHEDULED,
                null,
                sprintf(
                    // Named as an override when one was used, so the trail
                    // separates a routine reschedule from a correction to work
                    // already on the record. The actor is recorded by the
                    // logger itself.
                    $overrode
                        ? "Overrode the past-schedule lock on '%s': %s."
                        : "On '%s': %s.",
                    $project->reference_no ?? $project->name,
                    $change !== '' ? $change : 'saved the schedule with no change to its dates'
                ),
                $project
            );

            // Only worth telling anybody about when the dates actually moved.
            if ($rangesAfter !== $rangesBefore) {
                $this->notifications->projectScheduleChanged($project, $rangesAfter);
            }

            return redirect()
                ->route('super-admin.schedules.index')
                ->with('success', $project->schedules()->exists()
                    ? 'Schedule updated successfully.'
                    : sprintf(
                        '%s is now Unscheduled.',
                        $project->name
                    ));
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.schedules.index')
                ->with('error', $this->safeErrorMessage($e, 'Unable to save schedule. Nothing was changed.'));
        }
    }

    /**
     * Take one calendar date off one of a project's schedules.
     *
     * Reached from the panel that opens when a calendar date is clicked, and
     * always for the date that was clicked - there is nothing to choose here,
     * which is the point of it.
     *
     * The date is removed from the one booking named in the URL rather than
     * from everything the project holds that day: a Residential project may
     * have a morning and an afternoon booked separately on one date, and only
     * the person clicking knows which of them is being given up.
     */
    public function removeDate(Request $request, Schedule $schedule, string $date)
    {
        try {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } catch (Throwable $e) {
            return response()->json(['error' => 'Invalid date.'], 422);
        }

        // A Super Admin correcting a day already worked, having confirmed it.
        $mayOverrideLock = $request->boolean('override_past_lock')
            && (bool) $request->user()?->isSuperAdmin();

        $project = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->find($schedule->project_id);

        if (! $project) {
            return response()->json(['error' => 'That schedule no longer exists.'], 422);
        }

        $removal = app(ScheduleDateRemoval::class);
        $clearedTasks = collect();

        // Read before anything moves, so the log can say what changed.
        $labelsBefore = $this->scheduleLabels($project->schedules);
        $label = $day->format(BusinessTime::DATE);

        try {
            DB::transaction(function () use ($project, $schedule, $day, $removal, $mayOverrideLock, &$clearedTasks): void {
                $this->assertDateRemovable($project);
                $this->assertDayRemovable($day, $mayOverrideLock);

                $removal->remove($schedule, $day);

                $project->unsetRelation('schedules');

                // A task can only sit inside a date the project still holds,
                // which is the same rule a reschedule applies - so it is the
                // same code that applies it.
                $clearedTasks = $this->syncTaskDatesWithSchedule($project);

                $this->syncStatusWithSchedule($project);
            });
        } catch (Throwable $e) {
            return response()->json([
                'error' => $this->safeErrorMessage($e, 'Unable to save schedule. Nothing was changed.'),
            ], 422);
        }

        $project->unsetRelation('schedules');
        $stored = $project->schedules()->orderBy('start_datetime')->get();
        $rangesAfter = $this->describeSchedules($stored);
        $change = $this->describeScheduleChange($labelsBefore, $this->scheduleLabels($stored));

        $this->activityLogger->record(
            ActivityLog::PROJECT_RESCHEDULED,
            null,
            sprintf(
                // Named as an override when the day had already passed, so the
                // trail separates giving up a booked day from correcting the
                // record of one that was worked.
                //
                // The day removed is named first because that is the action.
                // What follows is what the action did to the ranges, and only
                // the ranges it touched are named - the log used to reprint
                // the project's whole schedule twice over to say that one of
                // them got a day shorter.
                $day->lt(Schedule::businessToday())
                    ? "Overrode the past-schedule lock on '%2\$s' and removed %1\$s from it%3\$s."
                    : "Removed %1\$s from the schedule on '%2\$s'%3\$s.",
                $label,
                $project->reference_no ?? $project->name,
                $change !== '' ? ' - '.$change : ''
            ),
            $project
        );

        $this->notifications->projectScheduleChanged($project, $rangesAfter);
        $this->notifications->taskDatesCleared($project, $clearedTasks);

        return response()->json([
            'message' => sprintf('%s was removed from %s.', $label, $project->name),
            'schedule_label' => $rangesAfter,
            'cleared_tasks' => $clearedTasks->count(),
        ]);
    }

    /**
     * Whose dates may be taken away.
     *
     * The same rule the rest of the page runs on: a completed, cancelled or
     * archived project is a record of what happened and is not edited, and an
     * on-hold project has no dates to take - putting one on hold releases them
     * all.
     */
    private function assertDateRemovable(Project $project): void
    {
        if ($project->isReadOnly()) {
            throw new RuntimeException(sprintf(
                'This project is %s and its schedule can no longer be changed.',
                $project->status
            ));
        }

        if ($project->is_archived) {
            throw new RuntimeException('This project is archived and its schedule can no longer be changed.');
        }

        if ($project->on_hold) {
            throw new RuntimeException('This project is on hold and its schedule can no longer be changed.');
        }
    }

    /**
     * Whether a particular day may be taken off a schedule.
     *
     * Tomorrow onwards, and nothing else. A day already worked is part of the
     * project's record - the same reason ScheduleHoldCutoff keeps the days a
     * hold was placed after - and today is being worked right now, so taking
     * it away would discard a day the crew is on site for.
     *
     * A Super Admin may still remove a day that has passed, as a correction to
     * a record that was wrong. Today is refused whoever is asking: it is not a
     * mistake in the record yet, it is work in progress.
     */
    private function assertDayRemovable(CarbonImmutable $day, bool $mayOverrideLock): void
    {
        if (Schedule::dateIsRemovable($day)) {
            return;
        }

        if ($day->equalTo(Schedule::businessToday())) {
            throw new RuntimeException(
                'Today is already under way, so it cannot be removed from the schedule.'
            );
        }

        if (! $mayOverrideLock) {
            throw new RuntimeException(sprintf(
                '%s has already passed and cannot be removed. Super Admin access is required to change it.',
                $day->format(BusinessTime::DATE)
            ));
        }
    }

    /**
     * Bring this project's task dates into line with the schedule it now
     * holds, and hand back whichever tasks lost theirs.
     *
     * The rule itself lives on TaskScheduleRules, which is also what the task
     * forms validate against and what a hold applies - so a date cleared here
     * is exactly a date the form would refuse, and no two callers can drift
     * into two answers.
     *
     * @return Collection<int, Task> the tasks whose dates were cleared, so a
     *                               caller can say so rather than leave the
     *                               work to be noticed missing
     */
    private function syncTaskDatesWithSchedule(Project $project): Collection
    {
        return $this->taskScheduleRules->unassignStrandedDates((int) $project->project_id);
    }

    /**
     * Bring the project's status into line with the dates it now holds.
     *
     * Replaces the pair of half-rules this page used to carry - one that
     * promoted out of Unscheduled and one that dropped back into it - with the
     * single answer ProjectStatusRules gives, so the schedules page, the
     * projects listing and Resume cannot disagree about what a set of dates
     * means. It handles both directions and the middle: dates given up
     * entirely leave the project Unscheduled, dates still to come leave it
     * Pending, dates that have arrived leave it Ongoing, and dates that have
     * all passed leave it Ongoing with nothing left to reach - which is what
     * Overdue is derived from.
     */
    private function syncStatusWithSchedule(Project $project): void
    {
        $project->unsetRelation('schedules');

        app(ProjectStatusRules::class)->apply($project);
    }

    /**
     * Ensure every technician assigned to this project is free on EVERY day
     * inside every submitted range, not just at the range endpoints.
     *
     * Delegates to the shared availability service so the schedules page, the
     * project wizard and the assigned-team editor all enforce identical rules.
     *
     * Every row is re-checked on every save, so a partial day widened back
     * into a whole day is tested against what that now demands rather than
     * against the hours it used to occupy.
     *
     * @param  Collection<int, array{schedule_id: ?int, mode: string, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function assertRangesAvailable(Project $project, Collection $ranges): void
    {
        $technicianIds = $project->projectTechnicians->pluck('technician_id')->unique()->values();

        if ($technicianIds->isEmpty()) {
            return;
        }

        // The project's own schedules are being replaced by this submission,
        // so they must not count against it. Overlaps between the submitted
        // ranges themselves are caught by assertNoOverlapWithinSubmission().
        app(TechnicianAvailabilityService::class)->assertContinuouslyAvailable(
            $technicianIds,
            $ranges->map(fn (array $range): array => [
                'start' => $range['start'],
                'end' => $range['end'],
                'mode' => $range['mode'],
            ])->all(),
            $project->project_id
        );
    }

    /**
     * Work out what each submitted row means, and whether it is allowed to
     * mean it.
     *
     * Scheduling mode belongs to the individual row, so a project may hold any
     * mix of whole-day and partial-day schedules and each is judged on its
     * own. A row already saved keeps whatever dates it holds, so editing one
     * schedule is never blocked by another having slipped into the past.
     *
     * Nothing here modifies, merges or removes a row other than the one being
     * submitted: a conversion that cannot be made without guessing is refused
     * outright and the person is asked to resolve it.
     *
     * @param  array<int, array<string, mixed>>  $submitted
     * @return Collection<int, array{schedule_id: ?int, mode: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function resolveSubmittedRanges(
        Project $project,
        array $submitted,
        ScheduleModeRules $scheduleRules,
        bool $mayOverrideLock = false
    ): Collection {
        $existing = $project->schedules->keyBy('schedule_id');
        $partialDayAllowed = $project->isResidential();

        // A throwaway validator, used purely as somewhere for the shared rules
        // to report to. Its messages are surfaced as the page's error toast,
        // which is how every other failure on this screen already arrives.
        $validator = Validator::make([], []);

        $resolved = collect();
        $conversions = [];

        foreach ($submitted as $index => $entry) {
            $scheduleId = isset($entry['schedule_id']) ? (int) $entry['schedule_id'] : null;
            $schedule = $scheduleId ? $existing->get($scheduleId) : null;

            if ($scheduleId && ! $schedule) {
                throw new RuntimeException('One of the schedules being edited does not belong to this project.');
            }

            // Noted before the row is touched: once it has been rewritten its
            // own dates no longer say what state it was in.
            $wasLocked = $schedule?->isLocked() ?? false;
            $wasActive = $schedule?->isActive() ?? false;
            $storedStart = $schedule?->startsOn();

            $range = $scheduleRules->validateEntry(
                $validator,
                $entry,
                sprintf('ranges.%d.', $index),
                $partialDayAllowed,
                $schedule !== null,
                // The stored row, so the rules can tell a booking being left
                // alone from one being moved, and how much of it has already
                // been worked.
                $schedule,
                $mayOverrideLock
            );

            if (! $range) {
                continue;
            }

            if ($schedule) {
                $conversions[] = [$schedule, $range['mode']];
            }

            $resolved->push([
                'schedule_id' => $scheduleId,
                'mode' => $range['mode'],
                'start' => $range['start'],
                'end' => $range['end'],
                // Whether this change could only be made because the lock was
                // overridden - which is what the audit line reports.
                //
                // Two shapes of it, not one. A booking that had ENDED and has
                // been changed at all is the obvious case. The other is a
                // booking already UNDER WAY whose start has been moved: that
                // start is a day the crew worked, it is frozen for everybody
                // else, and moving it rewrites the record just as surely.
                'used_override' => ($wasLocked && ! $this->matchesStored($schedule, $range))
                    || ($wasActive && $storedStart && ! $storedStart->equalTo($range['start']->startOfDay())),
            ]);
        }

        if ($validator->errors()->isNotEmpty()) {
            throw new RuntimeException($validator->errors()->first());
        }

        // Checked only once every row is known to be well formed, so a person
        // is not told about a conversion while a plain typo is still unfixed.
        foreach ($conversions as [$schedule, $mode]) {
            $scheduleRules->assertConvertible($schedule, $mode);
        }

        return $resolved;
    }

    /**
     * Whether a resolved range says exactly what the stored row already says.
     *
     * A row the editor drew read-only is still submitted by some callers, and
     * resubmitting a booking unchanged is not a change - so it must not be
     * reported as an override.
     *
     * @param  array{mode: string, start: CarbonImmutable, end: CarbonImmutable}  $range
     */
    private function matchesStored(?Schedule $schedule, array $range): bool
    {
        if (! $schedule) {
            return false;
        }

        $stored = $schedule->occupiedInterval();

        return $schedule->scheduling_mode === $range['mode']
            && $stored['start']->equalTo($range['start'])
            && $stored['end']->equalTo($range['end']);
    }

    /**
     * Schedules submitted together for the same project must not claim the
     * same time as each other, whether they're pre-existing rows being edited
     * or brand new ones being added alongside them. This is what stops a range
     * added on a previous edit from remaining pickable when adding another.
     *
     * Two partial days on one date are fine as long as their hours don't meet,
     * so the comparison is the same half-open one the availability service
     * uses rather than a whole-day overlap.
     *
     * @param  Collection<int, array{schedule_id: ?int, mode: string, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function assertNoOverlapWithinSubmission(Collection $ranges, ScheduleModeRules $scheduleRules): void
    {
        $rangesList = $ranges->values();

        for ($i = 0; $i < $rangesList->count(); $i++) {
            for ($j = $i + 1; $j < $rangesList->count(); $j++) {
                $a = $rangesList[$i];
                $b = $rangesList[$j];

                if ($scheduleRules->overlaps($a, $b)) {
                    throw new RuntimeException(sprintf(
                        'The schedule for %s overlaps with %s in the same submission.',
                        $this->describeRangeEntry($a),
                        $this->describeRangeEntry($b)
                    ));
                }
            }
        }
    }

    /**
     * A submitted row described the way a saved one would be, so the wording
     * of an error matches the wording everywhere else.
     *
     * @param  array{mode: string, start: CarbonImmutable, end: CarbonImmutable}  $range
     */
    private function describeRangeEntry(array $range): string
    {
        return (new Schedule([
            'start_datetime' => $range['start'],
            'end_datetime' => $range['end'],
            'scheduling_mode' => $range['mode'],
        ]))->describe();
    }

    /**
     * Build FullCalendar-ready events, one per schedule date range.
     * Title is kept to the reference number only to avoid cluttered bars;
     * the project name is passed separately for the hover tooltip.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function buildCalendarEvents(Collection $projects): array
    {
        $events = [];

        foreach ($projects as $project) {
            // Cancelled work is kept off every calendar. Held work is drawn:
            // the only dates it still holds are days that were actually
            // worked, and hiding them made the weeks before a pause read as
            // empty. Nothing here recreates a date the hold released.
            if (! $project->showsOnCalendar()) {
                continue;
            }

            // Null for everything except a cancelled project, whose bars stop
            // on the day it was called off - see Project::calendarCutoff().
            $cutoff = $project->calendarCutoff();

            foreach ($project->schedules as $schedule) {
                // A range that begins after the cancellation is days the job
                // never reached. There is no shortened version of it to draw.
                if (! $schedule->startsOnOrBefore($cutoff)) {
                    continue;
                }

                $events[] = [
                    'id' => $schedule->schedule_id,
                    'title' => $project->reference_no,
                    // A partial day comes back as a timed event, so the bar
                    // carries its hours instead of reading as a whole day.
                    ...$schedule->toCalendarTimesThrough($cutoff),
                    // Filled for a whole-day booking, outlined for a partial
                    // one - see Project::calendarEventColors().
                    ...$project->calendarEventColors($schedule->isDateBased()),
                    'extendedProps' => [
                        'projectId' => $project->project_id,
                        'scheduleId' => $schedule->schedule_id,
                        'referenceNo' => $project->reference_no,
                        'projectName' => $project->name,
                        // Carries the hours for a partial day, so the tooltip
                        // says more than the bar can show.
                        'scheduleLabel' => $schedule->describe(),
                        'status' => $project->status,
                        'statusLabel' => $this->statusLabel($project),
                        'onHold' => $project->on_hold,
                        // A completed project is a historical record, and a
                        // held one is paused: clicking either opens the
                        // view-only panel rather than the editor.
                        'readOnly' => ! $project->scheduleIsEditable(),
                    ],
                ];
            }
        }

        return $events;
    }

    /**
     * technician_id => display name, so the client-side availability check can
     * name the offending technician in the same wording as the server.
     *
     * @return array<int, string>
     */
    private function buildTechnicianNames(): array
    {
        return Technician::query()
            ->with('account:id,name')
            ->get()
            ->mapWithKeys(fn (Technician $technician): array => [
                (int) $technician->technician_id => $technician->name,
            ])
            ->filter()
            ->all();
    }

    /**
     * Build a map of technician_id => list of busy ranges, tagged with the
     * project each belongs to, for every active project schedule.
     *
     * `start` and `end` stay the dates they always were. A partial day carries
     * its hours alongside them, which is what lets the browser leave the rest
     * of that day pickable.
     *
     * @return array<int, array<int, array{start: string, end: string, project_id: int, mode: string, start_time: ?string, end_time: ?string}>>
     */
    private function buildTechnicianSchedules(): array
    {
        // Mirrors TechnicianAvailabilityService::busySchedulesQuery(), archived
        // work included: archiving keeps the schedule now, so a project
        // archived while it read Pending or Ongoing has to be named out rather
        // than left to its status alone.
        $schedules = Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
                    ->where('is_archived', false);
            })
            ->with([
                'scheduleTechnicians:schedule_technician_id,schedule_id,project_technician_id',
                'scheduleTechnicians.projectTechnician:project_technician_id,technician_id',
            ])
            ->get(['schedule_id', 'project_id', 'start_datetime', 'end_datetime', 'scheduling_mode']);

        $scheduleMap = [];

        foreach ($schedules as $schedule) {
            $start = CarbonImmutable::parse($schedule->start_datetime);
            $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);
            $isPartialDay = $schedule->isPartialDay();

            $busyRange = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'project_id' => $schedule->project_id,
                'mode' => $schedule->scheduling_mode,
                'start_time' => $isPartialDay ? $start->format('H:i') : null,
                'end_time' => $isPartialDay ? $end->format('H:i') : null,
            ];

            foreach ($schedule->scheduleTechnicians as $scheduleTechnician) {
                $technicianId = $scheduleTechnician->projectTechnician?->technician_id;

                if (! $technicianId) {
                    continue;
                }

                $scheduleMap[$technicianId][] = $busyRange;
            }
        }

        return $scheduleMap;
    }
}
