<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\SpecialtyRequest;
use App\Models\Task;
use App\Models\Technician;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ProfileService;
use App\Services\ProjectTeam;
use App\Services\ProjectTeamChange;
use App\Services\ProjectTeamChangePlan;
use App\Services\ProjectTeamRules;
use App\Services\TechnicianAvailabilityService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * Super-admin technician management: specialties on the Details tab, and
 * per-technician scheduling (view, remove from a project, assign to more
 * projects) on the Schedules tab.
 *
 * All availability decisions go through TechnicianAvailabilityService so this
 * page enforces exactly the same continuous-availability rule as the project
 * wizard and the schedules calendar.
 */
class TechnicianController extends Controller
{
    /**
     * A project's lead is the assigned technician whose account role is
     * lead_technician. There is no per-project lead column, so this mirrors
     * the derivation already used on the project details page.
     */
    private const LEAD_ROLE = 'lead_technician';

    /**
     * Statuses that can still take technicians.
     *
     * Wider than Project::ACTIVE_PROJECT_STATUSES on purpose: a project can be
     * staffed before it is scheduled. Restoring an archived project leaves it
     * unscheduled with no team, so it has to be reachable here - and
     * scheduling it later links every assigned technician to the new range.
     *
     * @var array<int, string>
     */
    private const STAFFABLE_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly ProjectTeam $projectTeam,
        private readonly ProjectTeamRules $teamRules
    ) {}

    public function index()
    {
        $technicians = Technician::query()
            ->with(['account', 'skills'])
            ->whereHas('account', function ($query): void {
                $query->whereIn('role', ['technician', self::LEAD_ROLE]);
            })
            ->orderBy('technician_id')
            ->get();

        $skills = Skill::query()->orderBy('skill_name')->get();

        // Which technicians are waiting on a decision. The table highlights
        // their row; the decision itself is taken in their details dialog,
        // where the reviewer can see what they already hold.
        $pendingTechnicianIds = SpecialtyRequest::query()
            ->pending()
            ->pluck('technician_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return view('super-admin.technicians', compact('technicians', 'skills', 'pendingTechnicianIds'));
    }

    // ------------------------------------------------------------------
    // Specialty requests
    // ------------------------------------------------------------------

    /**
     * Apply a technician's pending specialty request.
     *
     * Only an administrator reaches this - the whole Super Admin group is
     * behind `role:super_admin,admin` - and the change is the only thing that
     * moves a technician's specialties short of editing them here directly.
     */
    public function approveSpecialtyRequest(Request $request, SpecialtyRequest $specialtyRequest)
    {
        return $this->decide(
            $specialtyRequest,
            fn (): SpecialtyRequest => app(ProfileService::class)
                ->approveSpecialtyRequest($specialtyRequest, $request->user()),
            'Specialty request approved.',
            'Unable to approve request.'
        );
    }

    /**
     * Turn a request down. The technician's approved specialties are left
     * exactly as they are.
     */
    public function rejectSpecialtyRequest(Request $request, SpecialtyRequest $specialtyRequest)
    {
        return $this->decide(
            $specialtyRequest,
            fn (): SpecialtyRequest => app(ProfileService::class)
                ->rejectSpecialtyRequest($specialtyRequest, $request->user()),
            'Specialty request rejected.',
            'Unable to reject request.'
        );
    }

    /**
     * Run one approve/reject and answer with the technician as they now are.
     *
     * JSON rather than a redirect: the decision is taken inside the details
     * dialog, which redraws itself from this response instead of reloading the
     * page underneath it.
     *
     * @param  callable(): SpecialtyRequest  $action
     */
    private function decide(SpecialtyRequest $specialtyRequest, callable $action, string $success, string $fallback)
    {
        try {
            $action();
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => $fallback], 500);
        }

        $technician = $specialtyRequest->technician?->fresh(['account', 'skills']);

        return response()->json([
            'message' => $success,
            'technician' => $technician ? $this->technicianPayload($technician) : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Details tab - specialties
    // ------------------------------------------------------------------

    public function show(Technician $technician)
    {
        return response()->json($this->technicianPayload($technician->load(['account', 'skills'])));
    }

    /**
     * Replace a technician's specialties with the submitted set.
     *
     * The modal stages additions and removals locally and only calls this on
     * save, so one sync applies both at once. sync() is also what makes
     * duplicates impossible - the pivot ends up with exactly these ids.
     *
     * An empty list is allowed: it means "no specialties assigned".
     */
    public function syncSpecialties(Request $request, Technician $technician)
    {
        $validator = Validator::make($request->all(), [
            'skill_ids' => ['present', 'array'],
            'skill_ids.*' => ['required', 'integer', 'exists:tbl_skills,skill_id'],
        ], [
            'skill_ids.present' => 'Select at least one specialty.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $skillIds = collect($validator->validated()['skill_ids'])
            ->map(fn ($skillId): int => (int) $skillId)
            ->unique()
            ->values()
            ->all();

        $technician->skills()->sync($skillIds);

        return response()->json([
            'technician' => $this->technicianPayload($technician->fresh(['account', 'skills'])),
        ]);
    }

    // ------------------------------------------------------------------
    // Schedules tab - calendar
    // ------------------------------------------------------------------

    /**
     * Calendar events for one technician: every schedule range of every
     * non-archived project they are assigned to.
     */
    public function calendar(Technician $technician)
    {
        // Archived work is kept off every calendar. Cancelled work is drawn
        // for the days it worked before it stopped and no further - the same
        // rule the schedules calendar applies, from the same two methods, so
        // one technician's calendar and the whole company's cannot disagree
        // about when a called-off job ended.
        $schedules = $this->technicianSchedules($technician)
            ->filter(fn (Schedule $schedule): bool => $schedule->project?->showsOnCalendar() === true)
            ->filter(fn (Schedule $schedule): bool => $schedule->startsOnOrBefore(
                $schedule->project->calendarCutoff()
            ));

        // One bar per span of theirs on each range, drawn over the days that
        // span holds - see Schedule::toCalendarTimesForSpan().
        $events = $schedules->flatMap(fn (Schedule $schedule): Collection => $this->membershipsFor($schedule, $technician)
            ->map(function (ProjectTechnician $membership) use ($schedule): ?array {
                $project = $schedule->project;
                $times = $schedule->toCalendarTimesForSpan($project->calendarCutoff(), $membership);

                if ($times === null) {
                    return null;
                }

                // Days this technician worked on a project they have since
                // been taken off. The booking survives the removal - it is the
                // record of where they were - so it stays on the calendar and
                // is drawn black instead, the same treatment their own portal
                // gives it.
                $isFormer = $membership->hasEnded();

                return [
                    'id' => $schedule->schedule_id,
                    'title' => $project->reference_no,
                    // A partial day comes back as a timed event, so the bar
                    // carries its hours instead of reading as a whole day.
                    ...$times,
                    ...$project->calendarEventColors($schedule->isDateBased()),
                    ...($isFormer ? ['classNames' => ['fc-event-former']] : []),
                    'extendedProps' => [
                        'projectId' => $project->project_id,
                        'referenceNo' => $project->reference_no,
                        'projectName' => $project->name,
                        'client' => $this->clientName($project),
                        'status' => $project->status,
                        'statusLabel' => $this->statusLabel($project),
                        'rangeLabel' => $schedule->describe(),
                        'isFormer' => $isFormer,
                        'removedOn' => $isFormer
                            ? CarbonImmutable::parse($membership->endDate())->format(BusinessTime::DATE)
                            : null,
                    ],
                ];
            })
            ->filter())
            ->values();

        $assignedProjects = $this->assignedProjects($technician);

        return response()->json([
            'technician' => $this->technicianPayload($technician->load(['account', 'skills'])),
            'events' => $events,
            // What they are actually carrying: pending, ongoing and overdue
            // work. A completed project is history, not a workload, so the
            // figure beside the calendar leaves it out - the table below still
            // lists it.
            'activeCount' => $assignedProjects
                ->filter(fn (array $project): bool => in_array(
                    $project['status'] ?? null,
                    Project::ACTIVE_PROJECT_STATUSES,
                    true
                ))
                ->count(),
            'projects' => $assignedProjects->all(),
        ]);
    }

    /**
     * Every project this technician is assigned to, including ones with no
     * schedule yet, for the assignments table and its count.
     *
     * Archived and cancelled work is left out: it is not an assignment the
     * technician still owes anything on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function assignedProjects(Technician $technician): Collection
    {
        $projects = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account', 'phases'])
            ->where('is_archived', false)
            ->where('status', '!=', 'cancelled')
            // Including a project they are scheduled to join: it is work they
            // are assigned, whether or not its first day has come.
            ->whereHas('rosterTechnicians', function ($query) use ($technician): void {
                $query->where('technician_id', $technician->technician_id);
            })
            ->orderByDesc('project_id')
            ->get();

        // One grouped query rather than a count per project.
        $taskCounts = Task::query()
            ->whereIn('project_id', $projects->pluck('project_id'))
            ->where('technician_id', $technician->technician_id)
            ->selectRaw('project_id, count(*) as task_count')
            ->groupBy('project_id')
            ->pluck('task_count', 'project_id');

        return $projects->map(function (Project $project) use ($technician, $taskCounts): array {
            $lead = $this->leadAssignment($project);

            return $this->projectPayload($project) + [
                'is_lead_technician' => $lead
                    && (int) $lead->technician_id === (int) $technician->technician_id,
                'technician_task_count' => (int) ($taskCounts[$project->project_id] ?? 0),
            ];
        })->values();
    }

    /**
     * Everything the project details panel needs, including whether this
     * technician leads the project on the days being asked about and who could
     * lead in their place.
     *
     * The days are the removal the panel is building: `mode` is `from` (off
     * from `from` onward, the default) or `days` (off from `from` to `until`),
     * with today as the default day.
     */
    public function assignment(Request $request, Technician $technician, Project $project)
    {
        // Everything the details panel shows, eager loaded in one go. Tasks
        // are scoped to the technician being viewed - the panel lists their
        // work on this project, not the whole project's task board.
        $project->load([
            'clients',
            'schedules',
            'projectTechnicians.technician.account',
            // The closed memberships too, so a booking this technician no
            // longer holds can still be explained rather than refused.
            'teamHistory.technician.account',
            'tasks' => fn ($query) => $query
                ->where('technician_id', $technician->technician_id)
                ->orderByRaw('start_date is null')
                ->orderBy('start_date')
                ->orderBy('task_id'),
            'tasks.technician.account',
            // Each task's phase, and the project's whole set for the position
            // printed above them - one query each rather than one per row.
            'tasks.phase',
            'phases',
        ]);

        $spans = $project->teamHistory
            ->filter(fn (ProjectTechnician $span): bool => (int) $span->technician_id === (int) $technician->technician_id
                && ! $span->isEmptySpan());

        [$mode, $from, $until] = $this->removalDays(
            $request,
            $project,
            $spans->reject(fn (ProjectTechnician $span): bool => $span->hasEnded())
        );

        // On the team now, or due back on it - the current span first.
        $assignment = $spans
            ->reject(fn (ProjectTechnician $span): bool => $span->hasEnded())
            ->sortBy(fn (ProjectTechnician $span): int => $span->isCurrent() ? 0 : 1)
            ->first();

        // Nobody on the team now - but their calendar may still carry days
        // they worked before they came off it, and clicking one of those has
        // to answer with something. So a closed membership gets the same
        // panel, filled in, with a note saying when this technician left, and
        // no removal controls: there is nothing left to remove.
        $former = $assignment === null
            ? $spans->sortByDesc(fn (ProjectTechnician $span): string => (string) $span->endDate())->first()
            : null;

        if ($assignment === null && $former === null) {
            return response()->json(['error' => 'This technician is not assigned to that project.'], 422);
        }

        $isLead = $assignment !== null && $technician->isLead();

        $payload = [
            'project' => $this->projectPayload($project),
            'is_lead' => $isLead,
            // A former assignment is a record, so the panel reads it the same
            // way it reads a completed project: nothing to change.
            'read_only' => $project->isReadOnly() || $former !== null,
            'on_hold' => (bool) $project->on_hold,
            'is_former' => $former !== null,
            'removed_on' => $former?->endDate()
                ? CarbonImmutable::parse($former->endDate())->format(BusinessTime::DATE)
                : null,
            // Whatever is already scheduled for them here, in the words their
            // schedule dialog uses - "Days off Sep 22 - Sep 23", "Leaving
            // Sep 30" - so the panel can say so. Days off on no working day are
            // left out there, and so here.
            'scheduled' => collect($project->scheduledChangesFor((int) $technician->technician_id))
                ->map(fn (array $change): string => $change['title'].' '.$change['when'])
                ->values()
                ->all(),
            'mode' => $mode,
            'from' => $from->toDateString(),
            'until' => $until?->toDateString(),
            'min_date' => Schedule::businessToday()->toDateString(),
            // The days this technician holds on the project - now or still to
            // come - inclusive at both ends, null for open. Only a day the
            // project is scheduled on AND one of these covers can be taken
            // away; the dialog's pickers offer nothing else.
            'technician_spans' => $spans
                ->reject(fn (ProjectTechnician $span): bool => $span->hasEnded())
                ->map(fn (ProjectTechnician $span): array => [
                    'start' => $span->startDate(),
                    'end' => $span->lastDay()?->toDateString(),
                ])
                ->values()
                ->all(),
            // Their open tasks that run into the days being taken away. The
            // change still saves - the tasks stay theirs, flagged on the task
            // board - but the panel says which ones before anybody presses it.
            'affected_tasks' => $this->tasksCrossingRemoval($project->tasks, $mode, $from, $until)
                ->map(fn (Task $task): string => $this->describeAffectedTask($task))
                ->values()
                ->all(),
            'remaining_after_removal' => $project->teamHistory
                ->filter(fn (ProjectTechnician $span): bool => $span->isCurrent($from->toDateString()))
                ->count() - ($assignment?->isCurrent($from->toDateString()) ? 1 : 0),
            'replacement_leads' => [],
        ];

        // Who could lead in their place: lead-role technicians not on this
        // project for those days and free for the project's dates in them.
        if ($isLead && ! $project->isReadOnly()) {
            $payload['replacement_leads'] = $this->availableReplacementLeads(
                $project,
                $from,
                $mode === 'days' ? $until?->addDay() : null
            )
                ->map(fn (Technician $candidate): array => [
                    'technician_id' => $candidate->technician_id,
                    'name' => $candidate->name,
                    'skills' => $candidate->skill_names,
                ])
                ->values()
                ->all();
        }

        return response()->json($payload);
    }

    /**
     * The open tasks in a list that run into the days a removal takes away:
     * from `from` onward, or `from` to `until` for days off.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    private function tasksCrossingRemoval(
        Collection $tasks,
        string $mode,
        CarbonImmutable $from,
        ?CarbonImmutable $until
    ): Collection {
        $first = $from->toDateString();
        $last = $mode === 'days' ? $until?->toDateString() : null;

        return $tasks
            ->filter(fn (Task $task): bool => in_array($task->status, Task::OPEN_STATUSES, true)
                && $task->start_date !== null
                && $task->due_date !== null
                && CarbonImmutable::parse($task->due_date)->toDateString() >= $first
                && ($last === null || CarbonImmutable::parse($task->start_date)->toDateString() <= $last))
            ->values();
    }

    /**
     * `"Leak test" (Oct 31, 2026 - Nov 2, 2026)`.
     */
    private function describeAffectedTask(Task $task): string
    {
        $start = CarbonImmutable::parse($task->start_date)->format(BusinessTime::DATE);
        $due = CarbonImmutable::parse($task->due_date)->format(BusinessTime::DATE);

        return sprintf('"%s" (%s)', $task->task_title, $start === $due ? $start : $start.' - '.$due);
    }

    /**
     * Take a technician off a project - for some days, or from a day onward.
     *
     *   mode=days   off from `from` to `until`, back on the next scheduled day
     *               after - or not back at all when the project has none. A
     *               lead needs a stand-in, who leads for those days.
     *   mode=from   off from `from` onward, entirely. A lead needs a
     *               replacement, who takes the lead over for good.
     *
     * Both go through ProjectTeamChange, the same as the Edit Assigned Team
     * dialog, so every rule is the same: a lead on every day, and nobody given
     * work they cannot receive. Open tasks the change strands stay with the
     * technician, flagged on the task board.
     */
    public function removeFromProject(Request $request, Technician $technician, Project $project)
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['nullable', 'in:from,days'],
            'replacement_lead_id' => ['nullable', 'integer', 'exists:tbl_technicians,technician_id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        [$mode, $from, $until] = $this->removalDays($request, $project);
        $replacementLeadId = $validator->validated()['replacement_lead_id'] ?? null;
        $change = app(ProjectTeamChange::class);
        $actorId = $request->user()?->id;

        $project->load(['schedules', 'teamHistory.technician.account']);

        try {
            if ($project->isReadOnly()) {
                throw new RuntimeException(sprintf(
                    'This project is %s and its team can no longer be changed.',
                    strtolower($project->statusLabel())
                ));
            }

            if ($project->on_hold) {
                throw new RuntimeException('This project is on hold. Resume it before changing its assigned technicians.');
            }

            if ($from->lt(Schedule::businessToday())) {
                throw new RuntimeException('A team change cannot take effect on a day that has already passed.');
            }

            if ($mode === 'days' && ($until === null || $until->lt($from))) {
                throw new RuntimeException('Choose the last day they are off, on or after the first.');
            }

            // Only the project's own scheduled days can be taken away - a day
            // nobody is booked on site has nothing to remove. A project with no
            // schedule yet has no days to hold anybody to.
            if ($project->schedules->isNotEmpty()) {
                foreach (array_filter([$from, $until]) as $chosen) {
                    if (! $project->isScheduledOn($chosen->toDateString())) {
                        throw new RuntimeException(sprintf(
                            '%s is not a scheduled day on %s. Choose one of its scheduled days.',
                            $chosen->format(BusinessTime::DATE),
                            $project->name
                        ));
                    }
                }
            }

            $theirs = $project->teamHistory
                ->filter(fn (ProjectTechnician $span): bool => (int) $span->technician_id === (int) $technician->technician_id
                    && ! $span->isEmptySpan()
                    && ! $span->hasEnded());

            if ($theirs->isEmpty()) {
                throw new RuntimeException('This technician is not assigned to that project.');
            }

            if ($technician->isLead() && ! $replacementLeadId) {
                throw new RuntimeException(sprintf(
                    $mode === 'days'
                        ? '%s leads this project. Choose a lead technician to stand in for those days.'
                        : '%s leads this project. Choose a replacement first.',
                    $technician->name
                ));
            }

            if (! $technician->isLead()
                && $project->teamHistory->filter(fn (ProjectTechnician $span): bool => $span->isCurrent($from->toDateString()))->count() <= 1
                && $theirs->contains(fn (ProjectTechnician $span): bool => $span->isCurrent($from->toDateString()))) {
                throw new RuntimeException($this->sentence(
                    'A project must keep at least one technician. Assign someone else first.'
                ));
            }

            // Asked before availability, so a plain technician is told why
            // rather than being called busy.
            $replacement = $replacementLeadId ? Technician::query()->with('account')->find($replacementLeadId) : null;

            if ($replacement && ! $replacement->isLead()) {
                throw new RuntimeException(sprintf('%s is not a Lead Technician. Choose a lead technician.', $replacement->name));
            }

            if ($replacementLeadId
                && ! $this->availableReplacementLeads($project, $from, $mode === 'days' ? $until->addDay() : null)
                    ->contains('technician_id', $replacementLeadId)) {
                throw new RuntimeException(
                    'That lead technician is no longer free for those days. Choose another.'
                );
            }

            $plan = $mode === 'days'
                ? $change->planDaysOff($project, (int) $technician->technician_id, $from, $until, $replacementLeadId)
                : $change->planRemoval($project, (int) $technician->technician_id, $from, $replacementLeadId);

            if ($problem = collect($change->problems($plan))->first()) {
                throw new RuntimeException($problem);
            }

            if ($conflict = $change->availabilityConflict($plan)) {
                throw new RuntimeException($conflict);
            }

            DB::transaction(fn () => $change->apply($plan, $actorId));
        } catch (Throwable $e) {
            return response()->json([
                'error' => $this->safeErrorMessage($e, 'Unable to save that change. Nothing was changed.'),
            ], 422);
        }

        $affected = $this->tasksCrossingRemoval(
            $project->tasks()->where('technician_id', $technician->technician_id)->get(),
            $mode,
            $from,
            $until
        );

        $cover = $replacementLeadId ? Technician::query()->with('account')->find($replacementLeadId)?->name : null;
        $days = $mode === 'days'
            ? ($from->isSameDay($until)
                ? 'on '.$from->format(BusinessTime::DATE)
                : 'from '.$from->format(BusinessTime::DATE).' to '.$until->format(BusinessTime::DATE))
            : null;

        $this->activityLogger->record(
            ActivityLog::TECHNICIAN_REMOVED,
            $technician->account,
            $mode === 'days'
                ? sprintf(
                    "Took %s off '%s' %s%s.",
                    $technician->name,
                    $project->reference_no ?? $project->name,
                    $days,
                    $cover ? '; '.$cover.' leads in their place' : ''
                )
                : sprintf(
                    "Removed %s from '%s'%s%s.",
                    $technician->name,
                    $project->reference_no ?? $project->name,
                    $plan->isImmediate() ? '' : ', effective '.$from->format(BusinessTime::DATE),
                    $cover ? '; '.$cover.' takes over as lead' : ''
                ),
            $project
        );

        $change->notify($plan);

        $message = match (true) {
            $mode === 'days' => $this->sentence(sprintf('%s is off %s %s', $technician->name, $project->name, $days)),
            $plan->isImmediate() => $this->sentence($technician->name.' was removed from '.$project->name),
            default => $this->sentence(sprintf(
                '%s will be removed from %s on %s',
                $technician->name,
                $project->name,
                $from->format(BusinessTime::DATE)
            )),
        };

        if ($affected->isNotEmpty()) {
            $message .= sprintf(
                ' %s: %s. %s flagged "Technician Not Assigned for Dates" on the task board.',
                $affected->count() === 1 ? 'This task still belongs to them and runs into those days' : 'These tasks still belong to them and run into those days',
                $affected->map(fn (Task $task): string => $this->describeAffectedTask($task))->join(', ', ' and '),
                $affected->count() === 1 ? 'It is' : 'They are'
            );
        }

        return response()->json([
            'message' => $message,
            'affected_tasks' => $affected->map(fn (Task $task): string => $this->describeAffectedTask($task))->values()->all(),
        ]);
    }

    /**
     * The removal a request describes: its mode, first day, and last day (for
     * days off). Today when no day is given; `effective_date` is still read as
     * the first day, for a caller that sends the older field.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: ?CarbonImmutable}
     */
    private function removalDays(Request $request, Project $project, ?Collection $spans = null): array
    {
        $day = function (mixed $value): ?CarbonImmutable {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                ? CarbonImmutable::parse($value)->startOfDay()
                : null;
        };

        $mode = $request->input('mode') === 'days' ? 'days' : 'from';
        // Asked of nothing, the first day that can be taken away: the next
        // day from today the project is scheduled on and the technician is on
        // it, or today when there is none.
        $from = $day($request->input('from'))
            ?? $day($request->input('effective_date'))
            ?? $this->firstDayOnFrom($project, $spans, Schedule::businessToday())
            ?? $project->firstScheduledDayFrom(Schedule::businessToday())
            ?? Schedule::businessToday();
        $until = $mode === 'days' ? ($day($request->input('until')) ?? $from) : null;

        return [$mode, $from, $until];
    }

    /**
     * The first day from $from that the project is scheduled on and one of
     * these spans covers, or null when there is none - or no spans to ask.
     *
     * @param  Collection<int, ProjectTechnician>|null  $spans
     */
    private function firstDayOnFrom(Project $project, ?Collection $spans, CarbonImmutable $from): ?CarbonImmutable
    {
        if ($spans === null || $spans->isEmpty()) {
            return null;
        }

        $project->loadMissing('schedules');

        $last = $project->schedules->map(fn (Schedule $schedule): CarbonImmutable => $schedule->endsOn())->max();

        for ($day = $from->startOfDay(); $last !== null && $day->lte($last); $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($project->isScheduledOn($date)
                && $spans->contains(fn (ProjectTechnician $span): bool => $span->coveredOn($date))) {
                return $day;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Schedules tab - assigning to more projects
    // ------------------------------------------------------------------

    /**
     * Projects this technician could join: pending/ongoing, not on hold, not
     * already assigned, scheduled, and free for every day of their schedule.
     *
     * Each project carries its date ranges so the browser can grey out
     * projects that overlap one the user has already ticked.
     */
    public function assignableProjects(Technician $technician)
    {
        // Asked here as well as on the way in, so a switched-off account is
        // told why up front instead of being walked through a list of projects
        // and refused at the last step. The button that opens this is already
        // hidden for them; this is what answers a stale page.
        if (! $technician->isAssignable()) {
            return response()->json([
                'error' => $this->teamRules->unavailableMessage($technician),
            ], 422);
        }

        $candidates = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account', 'phases'])
            ->whereIn('status', self::STAFFABLE_STATUSES)
            ->where('is_archived', false)
            ->where(function ($query): void {
                $query->where('on_hold', false)->orWhereNull('on_hold');
            })
            ->whereDoesntHave('rosterTechnicians', function ($query) use ($technician): void {
                $query->where('technician_id', $technician->technician_id);
            })
            ->orderBy('name')
            ->get();

        $eligible = [];
        $blocked = [];

        // A project has exactly one lead, derived from the account role, so a
        // lead technician can only join projects that don't have one yet.
        $isLeadTechnician = optional($technician->account)->role === self::LEAD_ROLE;

        // Every candidate range in one window, so availability costs a fixed
        // handful of queries no matter how many projects are in play. The
        // technician is not on any candidate (see whereDoesntHave above), so
        // there is nothing of theirs to exclude per project.
        $allRanges = $candidates
            ->flatMap(fn (Project $project): array => $this->projectRanges($project))
            ->values()
            ->all();

        $busyDays = $allRanges === []
            ? []
            : (app(TechnicianAvailabilityService::class)->unavailableDatesByTechnician(
                [$technician->technician_id],
                [[
                    'start' => collect($allRanges)->min(fn (array $range) => $range['start']),
                    'end' => collect($allRanges)->max(fn (array $range) => $range['end']),
                ]]
            )[(int) $technician->technician_id] ?? []);

        foreach ($candidates as $project) {
            // Availability is decided before anything is said about the lead,
            // and that order is the rule rather than a detail. A lead who is
            // not free for the dates is no candidate to replace anybody, so
            // asking about the lead first would offer a replacement the save
            // then refuses - and would replace the honest calendar reason with
            // a lead one.
            $ranges = $this->projectRanges($project);

            // No ranges means nothing to clash with: either the project is not
            // scheduled yet, or everything it held has already been worked.
            // The technician is simply put on the team; scheduling the project
            // later links them to whatever range is created.
            $clashes = false;

            foreach ($ranges as $range) {
                foreach ($this->eachDate($range['start'], $range['end']) as $day) {
                    if (isset($busyDays[$day])) {
                        $clashes = true;

                        break 2;
                    }
                }
            }

            if ($clashes) {
                $blocked[] = $this->projectPayload(
                    $project,
                    'Technician is unavailable during this project\'s schedule.'
                );

                continue;
            }

            // A project carries exactly one lead. A lead technician who IS
            // free for its remaining dates is therefore offered the project
            // with the sitting lead named on it: the save may take them, but
            // only as a replacement, and only once somebody has said so.
            // assignToProjects() checks every part of that again.
            $existingLead = $isLeadTechnician ? $this->leadAssignment($project) : null;

            // Replacing today is refused where the team editor would refuse it
            // - a lead handover already booked for a later day would leave two
            // leads from that day on - so it is not offered here either.
            $replacementProblem = $existingLead
                ? $this->leadReplacementProblem($project, $existingLead, $technician)
                : null;

            if ($replacementProblem !== null) {
                $blocked[] = $this->projectPayload($project, $replacementProblem);

                continue;
            }

            $eligible[] = $this->projectPayload($project, null, $existingLead);
        }

        return response()->json([
            'technician' => $this->technicianPayload($technician->load(['account', 'skills'])),
            'projects' => $eligible,
            'blocked' => $blocked,
        ]);
    }

    /**
     * Assign the technician to one or more projects.
     *
     * Re-runs every check the browser already made, so a stale page or a
     * simultaneous edit elsewhere cannot create an overlapping assignment.
     *
     * `lead_replacements` is how a lead technician is allowed onto a project
     * that already has one. It carries, per project, the id of the lead the
     * person was looking at when they confirmed - so a project whose lead
     * changed in the meantime is refused rather than quietly having the wrong
     * person taken off it. Nothing is replaced without an entry here: the
     * absence of one is the old refusal, unchanged.
     */
    public function assignToProjects(Request $request, Technician $technician)
    {
        $validator = Validator::make($request->all(), [
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['required', 'integer', 'exists:tbl_projects,project_id'],
            'lead_replacements' => ['nullable', 'array'],
            'lead_replacements.*.project_id' => ['required', 'integer', 'exists:tbl_projects,project_id'],
            'lead_replacements.*.replacing_technician_id' => ['required', 'integer', 'exists:tbl_technicians,technician_id'],
        ], [
            'project_ids.required' => 'Select at least one project.',
            'project_ids.min' => 'Select at least one project.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        // The other end of the same rule the team editor applies: an account
        // that cannot sign in cannot be given work, and this page hands out
        // work from the technician's side rather than the project's. The
        // sentence is ProjectTeamRules' so the two screens refuse the same
        // thing in the same words, and name the same reason for it.
        if (! $technician->isAssignable()) {
            return response()->json([
                'error' => $this->teamRules->unavailableMessage($technician),
            ], 422);
        }

        $validated = $validator->validated();

        $projects = Project::query()
            // technician.account is needed to spot an existing lead.
            ->with(['schedules', 'projectTechnicians.technician.account'])
            ->whereIn('project_id', $validated['project_ids'])
            ->get();

        // project_id => the lead id the person confirmed against. Read once,
        // here, so the transaction below only ever asks a plain question of it.
        $confirmedLeadReplacements = collect($validated['lead_replacements'] ?? [])
            ->mapWithKeys(fn (array $replacement): array => [
                (int) $replacement['project_id'] => (int) $replacement['replacing_technician_id'],
            ])
            ->all();

        $isLeadTechnician = optional($technician->account)->role === self::LEAD_ROLE;
        $availability = app(TechnicianAvailabilityService::class);

        // Who actually lost the lead role, gathered inside the transaction and
        // reported after it commits - so the message describes what happened
        // rather than what was asked for.
        $replacedLeadNames = [];

        $removedBy = $request->user()?->id;

        try {
            DB::transaction(function () use (
                $technician,
                $projects,
                $availability,
                $isLeadTechnician,
                $confirmedLeadReplacements,
                $removedBy,
                &$replacedLeadNames
            ): void {
                $claimedRanges = [];

                foreach ($projects as $project) {
                    $this->assertProjectAcceptsTechnicians($project);

                    if ($project->projectTechnicians->contains('technician_id', $technician->technician_id)) {
                        throw new RuntimeException($this->sentence(
                            $technician->name.' is already assigned to '.$project->name
                        ));
                    }

                    // Whether this project's sitting lead is about to be
                    // replaced. Worked out before anything is written, and
                    // acted on only after the availability pass below, so a
                    // request that turns out to be impossible has taken
                    // nobody off anything.
                    $outgoingLead = null;
                    $confirmedLeadId = $confirmedLeadReplacements[(int) $project->project_id] ?? null;

                    // Re-checked here so a stale page can't create a second
                    // lead on a project that already has one - and so a
                    // confirmation that has been overtaken by somebody else's
                    // edit is refused rather than applied to the wrong person.
                    if ($isLeadTechnician) {
                        $existingLead = $this->leadAssignment($project);

                        if ($existingLead && $confirmedLeadId === null) {
                            throw new RuntimeException(sprintf(
                                '%s is already led by %s.',
                                $project->name,
                                $existingLead->technician?->name ?? 'another lead technician'
                            ));
                        }

                        if ($existingLead && $confirmedLeadId !== (int) $existingLead->technician_id) {
                            throw new RuntimeException(sprintf(
                                '%s is now led by %s rather than the lead you confirmed. Reopen the list and try again.',
                                $project->name,
                                $existingLead->technician?->name ?? 'another lead technician'
                            ));
                        }

                        $outgoingLead = $existingLead;
                    }

                    // A confirmation with nothing left to replace - the lead
                    // came off the project, or this technician is no longer a
                    // lead - is stale rather than harmless: the person agreed
                    // to something that is no longer what would happen.
                    if ($confirmedLeadId !== null && ! $outgoingLead) {
                        throw new RuntimeException(sprintf(
                            '%s no longer has a lead technician to replace. Reopen the list and try again.',
                            $project->name
                        ));
                    }

                    $ranges = $this->projectRanges($project);

                    // No ranges left means nothing to check availability
                    // against and nothing to overlap: the project is either
                    // not scheduled yet, or everything it held has already
                    // been worked. attachTechnician() below simply adds the
                    // team row; the schedule rows follow either way -
                    // ProjectTeam::attach() links whatever the project holds.
                    if ($ranges !== []) {
                        // Against everything already stored.
                        $availability->assertContinuouslyAvailable(
                            [$technician->technician_id],
                            $ranges,
                            $project->project_id
                        );

                        // Against the other projects being saved in this
                        // request, which share no rows yet and so can't be
                        // caught above.
                        foreach ($ranges as $range) {
                            foreach ($claimedRanges as $claimed) {
                                $overlaps = $range['start']->lte($claimed['end'])
                                    && $range['end']->gte($claimed['start']);

                                if ($overlaps) {
                                    throw new RuntimeException(sprintf(
                                        '%s overlaps %s, so %s cannot take both.',
                                        $project->name,
                                        $claimed['project'],
                                        $technician->name
                                    ));
                                }
                            }

                            $claimedRanges[] = [
                                'start' => $range['start'],
                                'end' => $range['end'],
                                'project' => $project->name,
                            ];
                        }
                    }

                    // The replacement happens today, through the same change
                    // the team editor makes, so the outgoing lead's span closes
                    // and the incoming lead's opens on the same day and the
                    // project is never holding two leads, or none.
                    //
                    // The outgoing lead's open work stays theirs, flagged on
                    // the task board where they are no longer assigned for its
                    // dates - see ProjectTeamChange::apply().
                    $outgoingLeadName = $outgoingLead?->technician?->name ?? 'the previous lead technician';

                    if ($outgoingLead) {
                        $outgoingAccount = $outgoingLead->technician?->account;
                        $change = app(ProjectTeamChange::class);
                        $plan = $this->leadReplacementPlan($project, $outgoingLead, $technician);

                        if ($problem = collect($change->problems($plan))->first()) {
                            throw new RuntimeException($problem);
                        }

                        $change->apply($plan, $removedBy);

                        $this->activityLogger->record(
                            ActivityLog::TECHNICIAN_REMOVED,
                            $outgoingAccount,
                            sprintf(
                                "Replaced %s as lead technician on '%s' with %s.",
                                $outgoingLeadName,
                                $project->reference_no ?? $project->name,
                                $technician->name
                            ),
                            $project
                        );

                        if ($outgoingAccount) {
                            $this->notifications->leadRemovedFromProject($project, $outgoingAccount);
                        }

                        $replacedLeadNames[] = $outgoingLeadName;

                        // The relation was loaded before the change, so the
                        // departing lead is still in it. Anything asked of it
                        // after this point - the duplicate-assignment guard on
                        // the next project, say - has to see what the project
                        // actually holds.
                        $project->load('projectTechnicians.technician.account');
                    } else {
                        $this->attachTechnician($project, $technician, $removedBy);
                    }

                    // A lead joining a project is a different event from a
                    // technician joining it, and the audit trail says which.
                    $this->activityLogger->record(
                        $isLeadTechnician
                            ? ActivityLog::LEAD_TECHNICIAN_ASSIGNED
                            : ActivityLog::TECHNICIAN_ASSIGNED,
                        $technician->account,
                        sprintf(
                            $outgoingLead
                                ? "Assigned %s as lead technician on '%s' from the technician's schedule, replacing %s."
                                : "Assigned %s to '%s' from the technician's schedule.",
                            $technician->name,
                            $project->reference_no ?? $project->name,
                            $outgoingLeadName
                        ),
                        $project
                    );

                    if ($technician->account) {
                        $isLeadTechnician
                            ? $this->notifications->leadAssignedToProject($project, $technician->account)
                            : $this->notifications->techniciansAssignedToProject($project, [$technician->account]);
                    }
                }
            });
        } catch (Throwable $e) {
            return response()->json([
                'error' => $this->safeErrorMessage($e, 'Unable to save that change. Nothing was changed.'),
            ], 422);
        }

        $message = $projects->count() === 1
            ? $this->sentence($technician->name.' was assigned to '.$projects->first()->name)
            : $technician->name.' was assigned to '.$projects->count().' projects.';

        // Somebody losing a project is the consequential half of this, so it
        // is said rather than left to be noticed on the next screen.
        if ($replacedLeadNames !== []) {
            $message .= ' '.$this->sentence(sprintf(
                '%s %s no longer the lead technician',
                implode(' and ', array_unique($replacedLeadNames)),
                count(array_unique($replacedLeadNames)) === 1 ? 'is' : 'are'
            ));
        }

        return response()->json(['message' => $message]);
    }

    // ------------------------------------------------------------------
    // Schedules tab - a single day on the calendar
    // ------------------------------------------------------------------

    /**
     * Every day from `start` to `end` (exclusive, as FullCalendar asks) that
     * a live project is scheduled on - the small dot the calendar draws, so
     * an empty day that has something to offer can be told from one that
     * has nothing.
     */
    public function bookedDays(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after:start'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $first = CarbonImmutable::parse($validator->validated()['start'])->startOfDay();
        // A month view asks for six weeks; anything much longer is not a view.
        $last = CarbonImmutable::parse($validator->validated()['end'])->startOfDay()->subDay();
        $last = $last->gt($first->addDays(62)) ? $first->addDays(62) : $last;

        // date => [colour => true], each colour the project's status ink -
        // the same one its bar and the legend are drawn in.
        $days = [];

        Schedule::query()
            ->with('project')
            ->whereHas('project', fn ($query) => $query
                ->whereIn('status', self::STAFFABLE_STATUSES)
                ->where('is_archived', false))
            ->where('start_datetime', '<', $last->addDay())
            ->where('end_datetime', '>=', $first)
            ->orderBy('start_datetime')
            ->get()
            ->each(function (Schedule $schedule) use ($first, $last, &$days): void {
                $from = $schedule->startsOn()->lt($first) ? $first : $schedule->startsOn()->startOfDay();
                $to = $schedule->endsOn()->gt($last) ? $last : $schedule->endsOn()->startOfDay();
                $colour = $schedule->project->calendarInkColor();

                for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                    $days[$day->toDateString()][$colour] = true;
                }
            });

        ksort($days);

        return response()->json([
            'days' => array_map(fn (array $colours): array => array_keys($colours), $days),
        ]);
    }

    /**
     * Projects this technician could be put on for one day only: the ones
     * scheduled on that day which they are not already on for it.
     *
     * Each is screened with the same plan the save runs - the lead rule and
     * availability for that day alone - so a project the save would refuse is
     * listed as unavailable with the save's own reason.
     *
     * A lead technician is offered a project that already has a lead that
     * day, with the sitting lead named on it (`lead_replacement`): saving it
     * makes them the lead for that day, and the sitting lead has that one day
     * off - see dayPlan().
     */
    public function dayProjects(Request $request, Technician $technician)
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $day = CarbonImmutable::parse($validator->validated()['date'])->startOfDay();
        $date = $day->toDateString();

        $payload = [
            'date' => $date,
            'date_label' => $day->format(BusinessTime::DATE),
            'is_past' => $day->lt(Schedule::businessToday()),
            'notice' => null,
            'projects' => [],
            'blocked' => [],
        ];

        if ($payload['is_past']) {
            $payload['notice'] = 'This day has already passed. A technician can only be added to a day still to come.';

            return response()->json($payload);
        }

        if (! $technician->isAssignable()) {
            $payload['notice'] = $this->teamRules->unavailableMessage($technician);

            return response()->json($payload);
        }

        $change = app(ProjectTeamChange::class);

        $candidates = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account', 'teamHistory.technician.account', 'phases'])
            ->whereIn('status', self::STAFFABLE_STATUSES)
            ->where('is_archived', false)
            ->whereHas('schedules')
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project): bool => $project->isScheduledOn($date))
            // Already on it that day: the day is theirs on the calendar, and
            // is taken away from there rather than added again here.
            ->reject(fn (Project $project): bool => $this->holdsDay($project, $technician, $day));

        foreach ($candidates as $project) {
            if ($project->on_hold) {
                $payload['blocked'][] = $this->projectPayload($project, 'This project is on hold.');

                continue;
            }

            try {
                [$plan, $sittingLead] = $this->dayPlan($change, $project, $technician, $day);
                $problem = collect($change->problems($plan))->first() ?? $change->availabilityConflict($plan);
            } catch (RuntimeException $e) {
                [$sittingLead, $problem] = [null, $e->getMessage()];
            }

            if ($problem !== null) {
                $payload['blocked'][] = $this->projectPayload($project, $problem);

                continue;
            }

            $payload['projects'][] = $this->projectPayload($project, null, $sittingLead);
        }

        return response()->json($payload);
    }

    /**
     * Put the technician on a project for one day only - on that day, off
     * again the day after. Every check dayProjects() made is made again, so a
     * stale dialog cannot book somebody the list would no longer offer.
     *
     * `replacing_technician_id` is the lead the person was warned about and
     * agreed to replace for that day. A lead technician is never put in
     * another lead's place without it, and never in place of a different lead
     * than the one they were shown.
     */
    public function assignForDay(Request $request, Technician $technician, Project $project)
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'replacing_technician_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $day = CarbonImmutable::parse($validator->validated()['date'])->startOfDay();
        $confirmedLeadId = $validator->validated()['replacing_technician_id'] ?? null;
        $change = app(ProjectTeamChange::class);
        $actorId = $request->user()?->id;
        $sittingLead = null;

        $project->load(['schedules', 'teamHistory.technician.account']);

        try {
            if (! $technician->isAssignable()) {
                throw new RuntimeException($this->teamRules->unavailableMessage($technician));
            }

            if ($day->lt(Schedule::businessToday())) {
                throw new RuntimeException('A technician can only be added to a day still to come.');
            }

            $this->assertProjectAcceptsTechnicians($project);

            if (! $project->isScheduledOn($day->toDateString())) {
                throw new RuntimeException(sprintf(
                    '%s is not a scheduled day on %s.',
                    $day->format(BusinessTime::DATE),
                    $project->name
                ));
            }

            if ($this->holdsDay($project, $technician, $day)) {
                throw new RuntimeException($this->sentence(sprintf(
                    '%s is already on %s on %s',
                    $technician->name,
                    $project->name,
                    $day->format(BusinessTime::DATE)
                )));
            }

            [$plan, $sittingLead] = $this->dayPlan($change, $project, $technician, $day);
            $sittingLeadName = $sittingLead?->technician?->name ?? 'another lead technician';

            if ($sittingLead && $confirmedLeadId === null) {
                throw new RuntimeException(sprintf(
                    '%s leads %s on %s. Confirm replacing them for that day.',
                    $sittingLeadName,
                    $project->name,
                    $day->format(BusinessTime::DATE)
                ));
            }

            if ($sittingLead && (int) $confirmedLeadId !== (int) $sittingLead->technician_id) {
                throw new RuntimeException(sprintf(
                    '%s is now led by %s on %s rather than the lead you confirmed. Reopen the day and try again.',
                    $project->name,
                    $sittingLeadName,
                    $day->format(BusinessTime::DATE)
                ));
            }

            if (! $sittingLead && $confirmedLeadId !== null) {
                throw new RuntimeException(sprintf(
                    '%s no longer has a lead technician on %s to replace. Reopen the day and try again.',
                    $project->name,
                    $day->format(BusinessTime::DATE)
                ));
            }

            if ($problem = collect($change->problems($plan))->first()) {
                throw new RuntimeException($problem);
            }

            if ($conflict = $change->availabilityConflict($plan)) {
                throw new RuntimeException($conflict);
            }

            DB::transaction(fn () => $change->apply($plan, $actorId));
        } catch (Throwable $e) {
            return response()->json([
                'error' => $this->safeErrorMessage($e, 'Unable to save that change. Nothing was changed.'),
            ], 422);
        }

        $dayLabel = $day->format(BusinessTime::DATE);

        if ($sittingLead) {
            $this->activityLogger->record(
                ActivityLog::TECHNICIAN_REMOVED,
                $sittingLead->technician?->account,
                sprintf(
                    "Took %s off '%s' on %s; %s leads in their place.",
                    $sittingLead->technician?->name,
                    $project->reference_no ?? $project->name,
                    $dayLabel,
                    $technician->name
                ),
                $project
            );
        }

        $this->activityLogger->record(
            $technician->isLead() ? ActivityLog::LEAD_TECHNICIAN_ASSIGNED : ActivityLog::TECHNICIAN_ASSIGNED,
            $technician->account,
            sprintf(
                $sittingLead
                    ? "Assigned %s as lead technician on '%s' for %s only, from the technician's schedule, in place of %s."
                    : "Assigned %s to '%s' for %s only, from the technician's schedule.",
                $technician->name,
                $project->reference_no ?? $project->name,
                $dayLabel,
                $sittingLead?->technician?->name
            ),
            $project
        );

        $change->notify($plan);

        if (! $sittingLead) {
            return response()->json([
                'message' => $this->sentence(sprintf(
                    '%s was assigned to %s for %s only',
                    $technician->name,
                    $project->name,
                    $dayLabel
                )),
            ]);
        }

        // The sitting lead's own work that day stays theirs, flagged on the
        // task board - said here, as Remove on This Day says it.
        $affected = $this->tasksCrossingRemoval(
            $project->tasks()->where('technician_id', $sittingLead->technician_id)->get(),
            'days',
            $day,
            $day
        );

        $message = $this->sentence(sprintf(
            '%s will lead %s on %s in place of %s, who is off it that day',
            $technician->name,
            $project->name,
            $dayLabel,
            $sittingLead->technician?->name
        ));

        if ($affected->isNotEmpty()) {
            $message .= sprintf(
                ' %s: %s. %s flagged "Technician Not Assigned for Dates" on the task board.',
                $affected->count() === 1
                    ? 'This task of '.$sittingLead->technician?->name.' runs into that day'
                    : 'These tasks of '.$sittingLead->technician?->name.' run into that day',
                $affected->map(fn (Task $task): string => $this->describeAffectedTask($task))->join(', ', ' and '),
                $affected->count() === 1 ? 'It is' : 'They are'
            );
        }

        return response()->json(['message' => $message]);
    }

    /**
     * The change that puts a technician on a project for one day, and the lead
     * it puts them in place of - null when there is none.
     *
     * A lead technician joining a day that already has a lead takes the lead
     * for that day: the sitting lead has the day off, with them standing in -
     * the same change as Remove on This Day for a lead - and is back the day
     * after. Anybody else simply joins for the day.
     *
     * @return array{0: ProjectTeamChangePlan, 1: ?ProjectTechnician}
     *
     * @throws RuntimeException
     */
    private function dayPlan(ProjectTeamChange $change, Project $project, Technician $technician, CarbonImmutable $day): array
    {
        $sittingLead = $technician->isLead()
            ? $change->leadOnDay(
                $project->teamHistory->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan()),
                $day->toDateString()
            )
            : null;

        if ($sittingLead === null) {
            return [$change->planDayOn($project, (int) $technician->technician_id, $day), null];
        }

        return [
            $change->planDaysOff($project, (int) $sittingLead->technician_id, $day, $day, (int) $technician->technician_id),
            $sittingLead,
        ];
    }

    /**
     * Whether any span of this technician's on the project covers the day.
     */
    private function holdsDay(Project $project, Technician $technician, CarbonImmutable $day): bool
    {
        return $project->teamHistory->contains(
            fn (ProjectTechnician $span): bool => (int) $span->technician_id === (int) $technician->technician_id
                && $span->overlaps($day->toDateString(), $day->addDay()->toDateString())
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Add the technician to a project and to every one of its schedules.
     */
    private function attachTechnician(
        Project $project,
        Technician $technician,
        ?int $addedBy = null
    ): void {
        $this->projectTeam->attach($project, (int) $technician->technician_id, $addedBy);
    }

    /**
     * Why replacing the sitting lead today would break the team rules - the
     * team editor's own sentence - or null when it would not.
     */
    private function leadReplacementProblem(Project $project, ProjectTechnician $outgoing, Technician $incoming): ?string
    {
        return collect(app(ProjectTeamChange::class)->problems(
            $this->leadReplacementPlan($project, $outgoing, $incoming)
        ))->first();
    }

    private function leadReplacementPlan(Project $project, ProjectTechnician $outgoing, Technician $incoming): ProjectTeamChangePlan
    {
        $team = $project->projectTechnicians
            ->reject(fn (ProjectTechnician $span): bool => $span->is($outgoing))
            ->pluck('technician_id')
            ->map(fn ($id): int => (int) $id)
            ->push((int) $incoming->technician_id);

        return app(ProjectTeamChange::class)->plan(
            $project,
            Schedule::businessToday(),
            (int) $incoming->technician_id,
            $team
        );
    }

    /**
     * Lead-role technicians who are not on this project and who are free for
     * every day of its schedule.
     *
     * Two things this deliberately does NOT do, both of which it used to.
     *
     * It no longer gives up on a project with no dates. An unscheduled project
     * has nothing to check availability against, which is a reason to skip the
     * availability pass and not a reason to offer nobody - offering nobody
     * made the outgoing lead impossible to remove, because removeFromProject()
     * requires a replacement drawn from this very list. The lead was stuck
     * until somebody scheduled the project.
     *
     * And it no longer offers accounts that have been switched off. Screening
     * on the lead role alone let a deactivated or archived technician be
     * installed as the new lead of a live project - an account that cannot
     * sign in, so cannot open the project, close a task or read the
     * notification saying it now leads one. ProjectTeamRules refuses exactly
     * that on the team editor, and the two screens have to refuse the same
     * thing.
     *
     * Screened from the day they would take over. Somebody busy elsewhere
     * until a handover is still free to take it over, so the days before it
     * are not asked about - and anybody already on this project's team, now or
     * from a later day, is not offered: they have a place on it already.
     *
     * @return Collection<int, Technician>
     */
    private function availableReplacementLeads(Project $project, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): Collection
    {
        // [$from, $until): the days they would lead. Null $until runs on.
        $from = ($from ?? Schedule::businessToday())->startOfDay();

        $ranges = collect($this->projectRanges($project))
            ->filter(fn (array $range): bool => $range['end']->gte($from)
                && ($until === null || $range['start']->lt($until)))
            ->map(fn (array $range): array => [
                'start' => $range['start']->lt($from) ? $from : $range['start'],
                'end' => $until !== null && $range['end']->gte($until) ? $until->subDay() : $range['end'],
            ])
            ->values()
            ->all();

        // Anybody on this project for any of those days already has a place
        // on it, and a lead-role technician cannot hold two.
        $assignedIds = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->notEnded()
            ->get()
            ->filter(fn (ProjectTechnician $span): bool => $span->overlaps($from->toDateString(), $until?->toDateString()))
            ->pluck('technician_id')
            ->all();

        $candidates = Technician::query()
            ->with(['account', 'skills'])
            // assignable() is the shared answer to "may this person be given
            // work?" - the lead role AND an account that can still sign in.
            ->assignable()
            ->whereHas('account', function ($query): void {
                $query->where('role', self::LEAD_ROLE);
            })
            ->whereNotIn('technician_id', $assignedIds)
            ->orderBy('technician_id')
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        // No dates means no clash to find. Everybody eligible is free, because
        // there is nothing yet to be free of.
        if ($ranges === []) {
            return $candidates->values();
        }

        // One bulk availability pass for every candidate rather than a query
        // per technician.
        $unavailable = app(TechnicianAvailabilityService::class)->unavailableDatesByTechnician(
            $candidates->pluck('technician_id'),
            $ranges,
            $project->project_id
        );

        return $candidates
            ->filter(fn (Technician $candidate): bool => ($unavailable[(int) $candidate->technician_id] ?? []) === [])
            ->values();
    }

    /**
     * The schedule ranges of a project that still have days to come, as
     * availability-service ranges.
     *
     * This page hands out FUTURE work - a technician being put on a project,
     * or a lead being installed on one - so a range the project has already
     * finished has nothing to say about it. Screening against one refused
     * technicians over a week nobody can staff differently now: a project
     * running Aug 10-12 and again Sep 5-8 would report somebody who was busy
     * in August as unavailable for September.
     *
     * Every remaining range is still returned, separately. A technician has to
     * be free for all of them, and merging them into one span would invent a
     * booking across the gap between them.
     *
     * A range that began before today keeps the days it has left, clamped to
     * today: those are still a real claim on somebody's diary, and the days
     * already worked are not.
     *
     * Whole-day shaped on purpose. This page compares ranges by date - see
     * assignableProjects() and the overlap pass in assignToProjects() - so the
     * hours of a partial day are not read here, exactly as they were not
     * before. What changed is which ranges are in the list, and nothing else.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function projectRanges(Project $project): array
    {
        $today = Schedule::businessToday();

        return $project->schedules
            // isLocked() is the line the schedule editor, the calendar and the
            // validator already draw: a range whose last day has gone by is
            // history rather than a promise.
            ->reject(fn (Schedule $schedule): bool => $schedule->isLocked())
            ->map(function (Schedule $schedule) use ($today): array {
                $start = $schedule->startsOn();

                return [
                    'start' => $start->lt($today) ? $today : $start,
                    'end' => $schedule->endsOn(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Inclusive list of 'Y-m-d' strings between two dates.
     *
     * @return array<int, string>
     */
    private function eachDate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();
        $dates = [];

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    private function assertProjectAcceptsTechnicians(Project $project): void
    {
        if ($project->isReadOnly()) {
            throw new RuntimeException(sprintf(
                '%s is %s and can no longer take technicians.',
                $project->name,
                strtolower($project->statusLabel())
            ));
        }

        if ($project->on_hold) {
            throw new RuntimeException($this->sentence($project->name.' is on hold'));
        }

        if (! in_array($project->status, self::STAFFABLE_STATUSES, true)) {
            throw new RuntimeException(sprintf(
                '%s cannot take technicians while it is %s.',
                $project->name,
                $this->statusLabel($project)
            ));
        }
    }

    /**
     * Schedules of every non-archived project this technician is on.
     *
     * @return Collection<int, Schedule>
     */
    /**
     * This technician's membership of the project a schedule belongs to,
     * taken from the booking already loaded against it.
     *
     * Read off the link rather than queried again: the schedule is only in
     * hand because one of their memberships is booked on it.
     *
     * A range can carry two of them - somebody taken off part-way through it
     * and put back on before it ended keeps the old span's link and gains a new
     * one - and each is its own stretch of the range.
     *
     * @return Collection<int, ProjectTechnician>
     */
    private function membershipsFor(Schedule $schedule, Technician $technician): Collection
    {
        return $schedule->scheduleTechnicians
            ->map(fn (ScheduleTechnician $link): ?ProjectTechnician => $link->projectTechnician)
            ->filter(fn (?ProjectTechnician $assignment): bool => $assignment !== null
                && (int) $assignment->technician_id === (int) $technician->technician_id)
            ->unique(fn (ProjectTechnician $assignment): int => (int) $assignment->project_technician_id)
            ->values();
    }

    private function technicianSchedules(Technician $technician): Collection
    {
        return Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->where('is_archived', false);
            })
            ->whereHas('scheduleTechnicians.projectTechnician', function ($query) use ($technician): void {
                $query->where('technician_id', $technician->technician_id);
            })
            // project.schedules is needed because isOverdue() inspects every
            // range; without it each event would fire its own query. The
            // booking links come too, so calendar() can tell whether the
            // membership behind each one is still open without going back to
            // the database per range.
            ->with([
                'project.clients',
                'project.schedules',
                'scheduleTechnicians.projectTechnician',
            ])
            ->orderBy('start_datetime')
            ->get();
    }

    /**
     * The project's own answer - see Project::leadAssignment() - so a finished
     * project's panel names the lead who ran it, not whoever holds the role now.
     */
    private function leadAssignment(Project $project): ?ProjectTechnician
    {
        return $project->leadAssignment();
    }

    /**
     * @param  ProjectTechnician|null  $replaceableLead  the lead this project
     *                                                   already has, when the
     *                                                   technician being placed
     *                                                   could take the role off
     *                                                   them. Null for every
     *                                                   other case, which is
     *                                                   every case where no
     *                                                   replacement is on offer.
     * @return array<string, mixed>
     */
    private function projectPayload(
        Project $project,
        ?string $reason = null,
        ?ProjectTechnician $replaceableLead = null
    ): array {
        $schedules = $project->schedules ?? collect();
        $start = $schedules->min('start_datetime');
        $end = $schedules->max('end_datetime');
        $lead = $this->leadAssignment($project);

        return [
            'project_id' => $project->project_id,
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'client' => $this->clientName($project),
            'address' => $project->address,
            'status' => $project->status,
            'status_label' => $this->statusLabel($project),
            'url' => route('super-admin.projects.show', $project->project_id),
            // Which stage the project is on, in the same words the client's
            // phase line and the technician's own schedule panel use - see
            // Project::phasePosition(). Null until the structure is
            // finalized, and the panel says so rather than showing a
            // position nobody has agreed.
            'phase_position' => $project->phasePosition(),
            'start_date' => $start ? CarbonImmutable::parse($start)->toDateString() : null,
            'end_date' => $end ? CarbonImmutable::parse($end)->toDateString() : null,
            // The project's overall span. With a single schedule that IS the
            // schedule, so it is described rather than printed as a span of
            // one date to itself.
            'range_label' => match (true) {
                $schedules->count() === 1 => $schedules->first()->describe(),
                (bool) ($start && $end) => CarbonImmutable::parse($start)->format(BusinessTime::DATE)
                    .' - '.CarbonImmutable::parse($end)->format(BusinessTime::DATE),
                default => 'No schedule set',
            },
            'has_schedule' => $schedules->isNotEmpty(),
            'ranges' => $schedules->map(fn (Schedule $schedule): array => [
                'start' => $schedule->startsOn()->toDateString(),
                'end' => $schedule->endsOn()->toDateString(),
                // `label` reads long for the details panel, `short_label`
                // fits a table cell where every schedule is listed. Both come
                // from the shared formatter, so a Partial Day carries its
                // hours into either.
                'label' => $schedule->describe(),
                'short_label' => $schedule->describe(),
                'is_partial_day' => $schedule->isPartialDay(),
                // Whether the booking has already been worked, so the panel
                // greys it and keeps the colour for what is still to come.
                // Schedule::lockState() draws that line everywhere else.
                'is_past' => $schedule->isLocked(),
            ])->values()->all(),
            'tasks' => $project->relationLoaded('tasks')
                ? $project->tasks->map(fn (Task $task): array => [
                    'task_id' => $task->task_id,
                    'title' => $task->task_title,
                    'description' => $task->task_description,
                    // The derived state, not ucfirst() of the stored column -
                    // which reported an overdue task as "Pending" and a task
                    // closed a fortnight late as "Completed". See TaskStatus.
                    ...$task->statusPayload(),
                    'technician' => $task->technician?->name,
                    // The stage of the project this task belongs to, the
                    // same pair x-task-phase-cell prints on the boards.
                    'phase' => $task->phase ? [
                        'sequence' => $task->phase->sequence,
                        'title' => $task->phase->title,
                        'label' => $task->phase->label(),
                    ] : null,
                    'range_label' => $task->start_date && $task->due_date
                        ? CarbonImmutable::parse($task->start_date)->format(BusinessTime::DATE)
                            .' - '
                            .CarbonImmutable::parse($task->due_date)->format(BusinessTime::DATE)
                        : 'No dates set',
                ])->values()->all()
                : [],
            'lead_technician' => $lead?->technician?->name,
            'technicians' => $project->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?array => $assignment->technician ? [
                    'technician_id' => $assignment->technician->technician_id,
                    'name' => $assignment->technician->name,
                    'is_lead' => $project->isLeadMember($assignment),
                ] : null)
                ->filter()
                ->values()
                ->all(),
            'reason' => $reason,
            // Present only when this project already has a lead AND the
            // technician being placed is one, and is free for what the project
            // has left. The browser reads it to ask before replacing anybody;
            // assignToProjects() reads the id back to check the same lead is
            // still sitting there when the answer arrives.
            'lead_replacement' => $replaceableLead ? [
                'technician_id' => (int) $replaceableLead->technician_id,
                'name' => $replaceableLead->technician?->name ?? 'another lead technician',
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianPayload(Technician $technician): array
    {
        return [
            'technician_id' => $technician->technician_id,
            'display_code' => $technician->displayCode(),
            'name' => $technician->name,
            'position' => $this->positionLabel($technician),
            'email' => $technician->account?->email,
            'avatar_url' => $technician->account?->avatarUrl(),
            // The account's own state, in the same words and colours the
            // profile page prints it in - so "Deactivated" means one thing
            // across the application.
            'status_label' => $technician->account?->statusLabel() ?? 'No account',
            'status_badge_class' => $technician->account?->statusBadgeClass() ?? 'bg-dark',
            'can_receive_work' => $technician->isAssignable(),
            'specialties' => $technician->skills
                ->map(fn (Skill $skill): array => [
                    'skill_id' => $skill->skill_id,
                    'skill_name' => $skill->skill_name,
                ])
                ->sortBy('skill_name')
                ->values()
                ->all(),
            'pending_request' => $this->pendingRequestPayload($technician),
        ];
    }

    /**
     * The technician's outstanding specialty request, if they have one.
     *
     * Reviewed in their details dialog rather than in a queue of its own: the
     * decision is easier to make beside the specialties they already hold.
     *
     * @return array<string, mixed>|null
     */
    private function pendingRequestPayload(Technician $technician): ?array
    {
        $request = SpecialtyRequest::query()
            ->pending()
            ->where('technician_id', $technician->technician_id)
            ->latest('specialty_request_id')
            ->first();

        if (! $request) {
            return null;
        }

        return [
            'id' => $request->specialty_request_id,
            'submitted_at' => BusinessTime::at($request->created_at)?->format(BusinessTime::DATE_TIME),
            'additions' => $request->additions()->all(),
            'removals' => $request->removals()->all(),
            'resulting' => $request->requestedSkills()->pluck('skill_name')->all(),
            'approve_url' => route('super-admin.technicians.specialty-requests.approve', $request),
            'reject_url' => route('super-admin.technicians.specialty-requests.reject', $request),
        ];
    }

    /**
     * Finish a sentence without doubling punctuation - project names often
     * already end in "." (e.g. "Anesi Inc.").
     */
    private function sentence(string $text): string
    {
        return preg_match('/[.!?]$/', $text) === 1 ? $text : $text.'.';
    }

    private function positionLabel(Technician $technician): string
    {
        $role = optional($technician->account)->role ?? $technician->role;

        return $role === self::LEAD_ROLE ? 'Lead Technician' : 'Technician';
    }

    private function clientName(Project $project): ?string
    {
        $client = $project->clients->first();

        return $client?->fullname ?: $client?->company_name;
    }

    /**
     * Delegates to the model so every screen agrees, including on Overdue.
     */
    private function statusLabel(Project $project): string
    {
        return $project->statusLabel();
    }
}
