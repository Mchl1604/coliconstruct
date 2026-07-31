<?php
namespace App\Http\Controllers;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
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

    public function index()
    {
        $projects = Project::query()
            ->with([
                'clients',
                'schedules',
                'projectTechnicians.technician.account',
            ])
            ->where('is_archived', false)
            ->orderBy('project_id', 'desc')
            ->get();

        $calendarEvents = $this->buildCalendarEvents($projects);
        $technicianSchedules = $this->buildTechnicianSchedules();
        $technicianNames = $this->buildTechnicianNames();

        return view('super-admin.schedule', compact(
            'projects',
            'calendarEvents',
            'technicianSchedules',
            'technicianNames'
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
                    'url' => route('super-admin.projects.show', $project->project_id),
                    'technicians' => $project->projectTechnicians
                        ->map(fn ($projectTechnician) => $projectTechnician->technician?->name)
                        ->filter()
                        ->values()
                        ->all(),
                    'ranges' => $covering->map(fn (Schedule $schedule): array => [
                        'start' => CarbonImmutable::parse($schedule->start_datetime)->toDateString(),
                        'end' => CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->toDateString(),
                        'label' => CarbonImmutable::parse($schedule->start_datetime)->format('M j, Y')
                            . ' - '
                            . CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('M j, Y'),
                    ])->all(),
                ];
            })->values(),
        ]);
    }

    /**
     * Projects that can take on the given date range.
     *
     * A project qualifies when it is schedulable (pending/ongoing, not on
     * hold or archived), none of its own ranges already cover these dates,
     * and every technician on it is continuously free for the whole range.
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
        $validator = Validator::make($request->all(), [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();

        $start = CarbonImmutable::parse($validated['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($validated['end_date'])->startOfDay();
        $ranges = [['start' => $start, 'end' => $end]];

        $candidates = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->where('is_archived', false)
            ->where(function ($query): void {
                $query->where('on_hold', false)->orWhereNull('on_hold');
            })
            ->orderBy('name')
            ->get();

        // One query covers availability for every technician on every
        // candidate project, tagged with the project that booked each day.
        $allTechnicianIds = $candidates
            ->flatMap(fn (Project $project) => $project->projectTechnicians->pluck('technician_id'))
            ->filter()
            ->unique()
            ->values();

        $dayOwners = app(TechnicianAvailabilityService::class)
            ->unavailableDayOwners($allTechnicianIds, $ranges);

        $rangeDays = $this->eachDate($start->toDateString(), $end->toDateString());

        $eligible = [];
        $blocked = [];

        foreach ($candidates as $project) {
            $projectId = (int) $project->project_id;

            // A project can't be booked over dates it already occupies.
            $selfOverlap = $project->schedules->contains(function (Schedule $schedule) use ($start, $end): bool {
                $scheduleStart = CarbonImmutable::parse($schedule->start_datetime)->startOfDay();
                $scheduleEnd = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->startOfDay();

                return $start->lte($scheduleEnd) && $end->gte($scheduleStart);
            });

            if ($selfOverlap) {
                $blocked[] = $this->projectPayload($project, 'Already scheduled during these dates.');

                continue;
            }

            $unavailableNames = [];

            foreach ($project->projectTechnicians as $projectTechnician) {
                $technicianId = (int) $projectTechnician->technician_id;
                $busyDays = $dayOwners[$technicianId] ?? [];

                if ($busyDays === []) {
                    continue;
                }

                foreach ($rangeDays as $day) {
                    $ownersForDay = $busyDays[$day] ?? [];

                    // Days this same project booked don't count against it.
                    unset($ownersForDay[$projectId]);

                    if ($ownersForDay !== []) {
                        $name = $projectTechnician->technician?->name;

                        if ($name) {
                            $unavailableNames[$name] = true;
                        }

                        break;
                    }
                }
            }

            if ($unavailableNames !== []) {
                $blocked[] = $this->projectPayload(
                    $project,
                    implode(', ', array_keys($unavailableNames))
                        . (count($unavailableNames) === 1 ? ' is' : ' are')
                        . ' booked on another project during these dates.'
                );

                continue;
            }

            $eligible[] = $this->projectPayload($project);
        }

        return response()->json([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
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
        $validator = Validator::make($request->all(), [
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['required', 'integer', 'exists:tbl_projects,project_id'],
        ], [
            'project_ids.required' => 'Select at least one project to schedule.',
            'project_ids.min' => 'Select at least one project to schedule.',
            'start_date.after_or_equal' => 'The start date cannot be in the past.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();

        $start = CarbonImmutable::parse($validated['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($validated['end_date'])->endOfDay();
        $ranges = [['start' => $start, 'end' => $start->max($end)->startOfDay()]];

        $projects = Project::query()
            ->with(['schedules', 'projectTechnicians.technician.account'])
            ->whereIn('project_id', $validated['project_ids'])
            ->get();

        $availability = app(TechnicianAvailabilityService::class);

        try {
            DB::transaction(function () use ($projects, $start, $end, $ranges, $availability): void {
                $claimedTechnicians = [];

                foreach ($projects as $project) {
                    $this->assertProjectSchedulable($project);
                    $this->assertNoSelfOverlap($project, $start, $end);

                    $technicianIds = $project->projectTechnicians
                        ->pluck('technician_id')
                        ->filter()
                        ->unique()
                        ->values();

                    // Conflicts against everything already in the database.
                    $availability->assertContinuouslyAvailable(
                        $technicianIds,
                        $ranges,
                        $project->project_id
                    );

                    // Conflicts between the projects being saved right now,
                    // which share no rows yet and so can't be caught above.
                    foreach ($technicianIds as $technicianId) {
                        if (isset($claimedTechnicians[$technicianId])) {
                            throw new RuntimeException(sprintf(
                                '%s is assigned to both %s and %s, so they cannot share these dates.',
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
                        'start_datetime' => $start,
                        'end_datetime' => $end,
                        'status' => 'scheduled',
                        'remarks' => 'Added from the schedules calendar',
                    ]);

                    $project->projectTechnicians->each(function ($projectTechnician) use ($schedule): void {
                        ScheduleTechnician::create([
                            'schedule_id' => $schedule->schedule_id,
                            'project_technician_id' => $projectTechnician->project_technician_id,
                        ]);
                    });

                    $this->promoteStatusAfterScheduling($project);
                }
            });
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $projects->count() === 1
                ? $projects->first()->name . ' has been scheduled.'
                : $projects->count() . ' projects have been scheduled.',
        ]);
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
            throw new RuntimeException($project->name . ' is on hold and cannot be scheduled.');
        }

        if (! in_array($project->status, self::ACTIVE_PROJECT_STATUSES, true)) {
            throw new RuntimeException(sprintf(
                '%s cannot be scheduled from the calendar while it is %s.',
                $project->name,
                $this->statusLabel($project)
            ));
        }
    }

    private function assertNoSelfOverlap(Project $project, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $overlaps = $project->schedules->contains(function (Schedule $schedule) use ($start, $end): bool {
            $scheduleStart = CarbonImmutable::parse($schedule->start_datetime);
            $scheduleEnd = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

            return $start->lte($scheduleEnd) && $end->gte($scheduleStart);
        });

        if ($overlaps) {
            throw new RuntimeException($project->name . ' already has a schedule covering these dates.');
        }
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

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    public function update(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.schedules.index')
                ->with('error', 'This project is ' . $project->status . ' and its schedule can no longer be changed.');
        }

        $validated = $request->validate([
            'ranges' => ['required', 'array', 'min:1'],
            'ranges.*.schedule_id' => ['nullable', 'integer', 'exists:tbl_schedule,schedule_id'],
            'ranges.*.start_date' => ['required', 'date'],
            'ranges.*.end_date' => ['required', 'date', 'after_or_equal:ranges.*.start_date'],
        ], [
            'ranges.required' => 'At least one date range is required.',
            'ranges.min' => 'At least one date range is required.',
        ]);

        try {
            DB::transaction(function () use ($validated, $project): void {
                $ranges = collect($validated['ranges'])->map(function (array $range) {
                    return [
                        'schedule_id' => isset($range['schedule_id']) ? (int) $range['schedule_id'] : null,
                        'start' => CarbonImmutable::parse($range['start_date'])->startOfDay(),
                        'end' => CarbonImmutable::parse($range['end_date'])->endOfDay(),
                    ];
                });

                $this->assertNewRangesNotInPast($ranges);
                $this->assertNoOverlapWithinSubmission($ranges);
                $this->assertRangesAvailable($project, $ranges);

                $keepScheduleIds = $ranges->pluck('schedule_id')->filter()->values();

                $project->schedules()
                    ->whereNotIn('schedule_id', $keepScheduleIds->all())
                    ->get()
                    ->each(fn (Schedule $schedule) => $schedule->delete());

                $projectTechnicianIds = $project->projectTechnicians->pluck('project_technician_id');

                $ranges->each(function (array $range) use ($project, $projectTechnicianIds): void {
                    if ($range['schedule_id']) {
                        Schedule::query()
                            ->where('project_id', $project->project_id)
                            ->where('schedule_id', $range['schedule_id'])
                            ->update([
                                'start_datetime' => $range['start'],
                                'end_datetime' => $range['end'],
                            ]);

                        return;
                    }

                    $schedule = Schedule::create([
                        'project_id' => $project->project_id,
                        'start_datetime' => $range['start'],
                        'end_datetime' => $range['end'],
                        'status' => 'scheduled',
                        'remarks' => 'Added from schedules page',
                    ]);

                    $projectTechnicianIds->each(function (int $projectTechnicianId) use ($schedule): void {
                        ScheduleTechnician::create([
                            'schedule_id' => $schedule->schedule_id,
                            'project_technician_id' => $projectTechnicianId,
                        ]);
                    });
                });

                $this->syncTaskDatesWithSchedule($project, $ranges);
                $this->promoteStatusAfterScheduling($project);
            });

            return redirect()
                ->route('super-admin.schedules.index')
                ->with('success', 'Schedule updated successfully.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.schedules.index')
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Tasks must always reflect the latest schedule. Any task whose dates
     * no longer fall inside at least one of the project's current date
     * ranges gets its dates cleared (shown as "Unassigned" in the UI)
     * instead of silently keeping stale dates.
     *
     * @param  Collection<int, array{schedule_id: ?int, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function syncTaskDatesWithSchedule(Project $project, Collection $ranges): void
    {
        $currentRanges = $ranges->map(fn (array $range) => [
            'start' => $range['start']->toDateString(),
            'end' => $range['end']->toDateString(),
        ]);

        Task::query()
            ->where('project_id', $project->project_id)
            ->whereNotNull('start_date')
            ->whereNotNull('due_date')
            ->get()
            ->each(function (Task $task) use ($currentRanges): void {
                $taskStart = CarbonImmutable::parse($task->start_date)->toDateString();
                $taskEnd = CarbonImmutable::parse($task->due_date)->toDateString();

                $stillCovered = $currentRanges->contains(function (array $range) use ($taskStart, $taskEnd): bool {
                    return $taskStart >= $range['start'] && $taskEnd <= $range['end'];
                });

                if (! $stillCovered) {
                    $task->update([
                        'start_date' => null,
                        'due_date' => null,
                    ]);
                }
            });
    }

    /**
     * Promote a Not Yet Scheduled project once it receives a schedule from
     * the schedules page, mirroring ProjectController's promotion rule.
     */
    private function promoteStatusAfterScheduling(Project $project): void
    {
        if ($project->status !== 'not_yet_scheduled') {
            return;
        }

        $firstSchedule = $project->schedules()->orderBy('start_datetime')->first();

        if (! $firstSchedule) {
            return;
        }

        $status = now()->gte($firstSchedule->start_datetime) ? 'ongoing' : 'pending';

        $project->update(['status' => $status]);
    }

    /**
     * Ensure every technician assigned to this project is free on EVERY day
     * inside every submitted range, not just at the range endpoints.
     *
     * Delegates to the shared availability service so the schedules page, the
     * project wizard and the assigned-team editor all enforce identical rules.
     *
     * @param  Collection<int, array{schedule_id: ?int, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
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
            ])->all(),
            $project->project_id
        );
    }

    /**
     * New date ranges (no existing schedule_id) cannot start before today.
     * Existing ranges are left alone so already-saved past dates don't
     * block an unrelated edit to the same project.
     *
     * @param  Collection<int, array{schedule_id: ?int, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function assertNewRangesNotInPast(Collection $ranges): void
    {
        $today = CarbonImmutable::today();

        foreach ($ranges as $range) {
            if ($range['schedule_id']) {
                continue;
            }

            if ($range['start']->lt($today)) {
                throw new RuntimeException('New date ranges cannot start before today.');
            }
        }
    }

    /**
     * Ranges submitted together for the same project must not overlap each
     * other, whether they're pre-existing ranges being edited or brand new
     * ones being added alongside them. This is what stops a range added on
     * a previous edit from remaining pickable when adding another one.
     *
     * @param  Collection<int, array{schedule_id: ?int, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function assertNoOverlapWithinSubmission(Collection $ranges): void
    {
        $rangesList = $ranges->values();

        for ($i = 0; $i < $rangesList->count(); $i++) {
            for ($j = $i + 1; $j < $rangesList->count(); $j++) {
                $a = $rangesList[$i];
                $b = $rangesList[$j];

                $overlaps = $a['start']->lte($b['end']) && $a['end']->gte($b['start']);

                if ($overlaps) {
                    throw new RuntimeException(sprintf(
                        'Date range %s to %s overlaps with %s to %s in the same submission.',
                        $a['start']->toDateString(),
                        $a['end']->toDateString(),
                        $b['start']->toDateString(),
                        $b['end']->toDateString()
                    ));
                }
            }
        }
    }

    /**
     * Build FullCalendar-ready events, one per schedule date range.
     * Title is kept to the reference number only to avoid cluttered bars;
     * the project name is passed separately for the hover tooltip.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function buildCalendarEvents(Collection $projects): array
    {
        $events = [];

        foreach ($projects as $project) {
            // Cancelled and on-hold work is kept off every calendar.
            if (! $project->showsOnCalendar()) {
                continue;
            }

            $color = $project->calendarColor();

            foreach ($project->schedules as $schedule) {
                $start = CarbonImmutable::parse($schedule->start_datetime);
                $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

                $events[] = [
                    'id' => $schedule->schedule_id,
                    'title' => $project->reference_no,
                    'start' => $start->toDateString(),
                    // FullCalendar's end date for all-day events is exclusive.
                    'end' => $end->addDay()->toDateString(),
                    'color' => $color,
                    'extendedProps' => [
                        'projectId' => $project->project_id,
                        'scheduleId' => $schedule->schedule_id,
                        'referenceNo' => $project->reference_no,
                        'projectName' => $project->name,
                        'status' => $project->status,
                        'statusLabel' => $this->statusLabel($project),
                        'onHold' => $project->on_hold,
                        // Completed / cancelled / archived projects are
                        // historical records: their schedule can't be edited.
                        'readOnly' => $project->isReadOnly(),
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
     * Build a map of technician_id => list of busy date ranges, tagged with
     * the project each range belongs to, for every active project schedule.
     *
     * @return array<int, array<int, array{start: string, end: string, project_id: int}>>
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
            ->get(['schedule_id', 'project_id', 'start_datetime', 'end_datetime']);

        $scheduleMap = [];

        foreach ($schedules as $schedule) {
            $startDate = CarbonImmutable::parse($schedule->start_datetime)->toDateString();
            $endDate = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->toDateString();

            foreach ($schedule->scheduleTechnicians as $scheduleTechnician) {
                $technicianId = $scheduleTechnician->projectTechnician?->technician_id;

                if (! $technicianId) {
                    continue;
                }

                $scheduleMap[$technicianId][] = [
                    'start' => $startDate,
                    'end' => $endDate,
                    'project_id' => $schedule->project_id,
                ];
            }
        }

        return $scheduleMap;
    }
}