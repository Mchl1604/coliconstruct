<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
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
            // Cancelled work is off this page entirely - calendar, table and
            // the panel a clicked date opens. The schedules themselves are
            // untouched and still read on the project's own page.
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

        // The other half: work waiting for its first dates, which is the only
        // way it reaches the calendar. On-hold projects are left out - a hold
        // releases the dates and the team, and the project has to be resumed
        // before it can be booked, which is the project page's to do.
        $unscheduledProjects = $projects
            ->filter(fn (Project $project): bool => $project->schedules->isEmpty()
                && ! $project->on_hold
                && ! $project->isReadOnly())
            ->sortBy('name')
            ->values();

        $calendarEvents = $this->buildCalendarEvents($scheduledProjects);
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
        // hours exist without the list being written out twice.
        $workingHours = Schedule::workingHourOptions();

        return view('super-admin.schedule', compact(
            'projects',
            'scheduledProjects',
            'unscheduledProjects',
            'calendarEvents',
            'technicianSchedules',
            'technicianNames',
            'projectLabels',
            'workingHours'
        ));
    }

    /**
     * Projects whose schedule covers the clicked calendar date.
     */
    public function dateDetails(string $date)
    {
        try {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } catch (Throwable $e) {
            return response()->json(['error' => 'Invalid date.'], 422);
        }

        $dayString = $day->toDateString();

        $projects = Project::query()
            ->with([
                'clients',
                'schedules',
                'projectTechnicians.technician.account',
            ])
            ->where('is_archived', false)
            // Kept off this panel for the same reason it is kept off the
            // calendar and the table: cancelled work has left this page.
            ->where('status', '!=', 'cancelled')
            ->whereHas('schedules', function ($query) use ($dayString): void {
                $query->whereDate('start_datetime', '<=', $dayString)
                    ->whereDate('end_datetime', '>=', $dayString);
            })
            ->orderBy('project_id', 'desc')
            ->get();

        return response()->json([
            'date' => $dayString,
            'label' => $day->format('F j, Y'),
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
                    // Completed work is a historical record and paused work is
                    // waiting to be resumed: both are listed here, and neither
                    // one's dates can be changed - exactly as on the calendar
                    // and in the table. Asked of the model so this panel and
                    // the endpoint behind Remove This Date agree.
                    'read_only' => ! $project->scheduleIsEditable(),
                    'url' => route('super-admin.projects.show', $project->project_id),
                    'technicians' => $project->projectTechnicians
                        ->map(fn ($projectTechnician) => $projectTechnician->technician?->name)
                        ->filter()
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
                    'Partial Day scheduling is available on Residential projects only.'
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
                                '%s is assigned to both %s and %s, so they cannot share this schedule.',
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
                'error' => $this->safeErrorMessage($e, 'That schedule could not be saved. Nothing was changed.'),
            ], 422);
        }

        return response()->json([
            'message' => $projects->count() === 1
                ? $projects->first()->name.' has been scheduled.'
                : $projects->count().' projects have been scheduled.',
        ]);
    }

    /**
     * Every range a project holds, for the before/after halves of a
     * reschedule log entry.
     *
     * @param  Collection<int, Schedule>  $schedules
     */
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
            '%s is a Commercial project, and Partial Day scheduling is available on Residential projects only.',
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
            ...$scheduleRules->rules('ranges.*.'),
        ], $scheduleRules->messages('ranges.*.'));

        // Read before anything moves, so the log can say what it changed from.
        $rangesBefore = $this->describeSchedules($project->schedules);

        try {
            DB::transaction(function () use ($validated, $project, $scheduleRules): void {
                $ranges = $this->resolveSubmittedRanges($project, $validated['ranges'] ?? [], $scheduleRules);

                $this->assertNoOverlapWithinSubmission($ranges, $scheduleRules);
                $this->assertRangesAvailable($project, $ranges);

                $keepScheduleIds = $ranges->pluck('schedule_id')->filter()->values();

                $project->schedules()
                    ->whereNotIn('schedule_id', $keepScheduleIds->all())
                    ->get()
                    ->each(fn (Schedule $schedule) => $schedule->delete());

                $ranges->each(function (array $range) use ($project): void {
                    if ($range['schedule_id']) {
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
            $rangesAfter = $this->describeSchedules($project->schedules()->orderBy('start_datetime')->get());

            $this->activityLogger->record(
                ActivityLog::PROJECT_RESCHEDULED,
                null,
                sprintf(
                    "Changed the date ranges on '%s' from %s to %s.",
                    $project->reference_no ?? $project->name,
                    $rangesBefore,
                    $rangesAfter
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
                        '%s now holds no dates and is Unscheduled, so it has been taken off the calendar and the table.',
                        $project->name
                    ));
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.schedules.index')
                ->with('error', $this->safeErrorMessage($e, 'The schedule could not be saved. Nothing was changed.'));
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
    public function removeDate(Schedule $schedule, string $date)
    {
        try {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } catch (Throwable $e) {
            return response()->json(['error' => 'Invalid date.'], 422);
        }

        $project = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->find($schedule->project_id);

        if (! $project) {
            return response()->json(['error' => 'That schedule no longer exists.'], 422);
        }

        $removal = app(ScheduleDateRemoval::class);
        $clearedTasks = collect();

        // Read before anything moves, so the log can say what it changed from.
        $rangesBefore = $this->describeSchedules($project->schedules);
        $label = $day->format('F j, Y');

        try {
            DB::transaction(function () use ($project, $schedule, $day, $removal, &$clearedTasks): void {
                $this->assertDateRemovable($project);

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
                'error' => $this->safeErrorMessage($e, 'That schedule could not be saved. Nothing was changed.'),
            ], 422);
        }

        $project->unsetRelation('schedules');
        $rangesAfter = $this->describeSchedules($project->schedules()->orderBy('start_datetime')->get());

        $this->activityLogger->record(
            ActivityLog::PROJECT_RESCHEDULED,
            null,
            sprintf(
                "Removed %s from the schedule on '%s'. Its dates changed from %s to %s.",
                $label,
                $project->reference_no ?? $project->name,
                $rangesBefore,
                $rangesAfter
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
        ScheduleModeRules $scheduleRules
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

            $range = $scheduleRules->validateEntry(
                $validator,
                $entry,
                sprintf('ranges.%d.', $index),
                $partialDayAllowed,
                $schedule !== null,
                // The stored row, so the rules can tell a booking being left
                // alone from one being moved. Only the second is refused a
                // date in the past.
                $schedule
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

            foreach ($project->schedules as $schedule) {
                $events[] = [
                    'id' => $schedule->schedule_id,
                    'title' => $project->reference_no,
                    // A partial day comes back as a timed event, so the bar
                    // carries its hours instead of reading as a whole day.
                    ...$schedule->toCalendarTimes(),
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
        $schedules = Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', self::ACTIVE_PROJECT_STATUSES);
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
