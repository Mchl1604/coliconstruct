<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\TaskImage;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use App\Services\TaskAssignmentGaps;
use App\Services\TaskAssignmentRules;
use App\Services\TaskScheduleRules;
use App\Services\TechnicianTaskLoad;
use App\Support\BusinessTime;
use App\Support\UploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Throwable;

/**
 * The technician portal, shared by both technician roles.
 *
 * One set of pages serves them: a calendar beside a detail panel, and
 * DataTables everywhere else, laid out the way the Super Admin portal lays its
 * own out. What differs is reach, not layout.
 *
 *   - A technician reads their assignments and completes their own tasks.
 *     Nothing else on these pages is theirs to touch.
 *   - A lead additionally runs the task board, files reports and closes
 *     projects, and gets a Reports page of their own.
 *
 * That difference is decided by ProjectPolicy / TaskPolicy, which the views
 * consult, rather than by giving the two roles separate screens - so there is
 * one place to read the rules from and one page to keep working.
 *
 * Every query is anchored to the signed-in account's technician record, so a
 * technician can only ever reach their own work.
 */
class TechnicianPortalController extends Controller
{
    /**
     * Statuses that keep a project off the portal entirely: finished and
     * abandoned work is not an assignment anyone still owes anything on.
     *
     * @var array<int, string>
     */
    private const HIDDEN_STATUSES = ['cancelled', 'archived'];

    public function __construct(
        private TaskScheduleRules $scheduleRules,
        private readonly TaskAssignmentRules $assignmentRules,
        private ProjectPolicy $projectPolicy,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    /**
     * My Schedule: a calendar of every date this technician was booked for,
     * with the clicked project's details loaded into the panel beside it.
     *
     * Built from the bookings rather than from the current team, and that
     * distinction is the whole point of this page. My Projects answers "what
     * am I on?" and drops a project the moment somebody takes you off it. This
     * answers "where was I, and where am I going to be?", and the days you
     * worked in July are still days you worked whatever happened in August.
     * Reading both from the same source is what made them unable to disagree.
     *
     * A booking that outlives the membership is drawn as a record - see
     * calendarEvent() - and its panel says so rather than opening the project.
     */
    public function schedule(Request $request)
    {
        $technician = $this->technician($request);
        $projects = $this->assignedProjects($technician);

        $events = $this->bookedSchedules($technician)
            ->map(fn (Schedule $schedule): array => $this->calendarEvent(
                $schedule->project,
                $schedule,
                $this->membershipFor($schedule, $technician)
            ))
            ->values();

        $today = CarbonImmutable::today();

        return view('technician.schedule', [
            'events' => $events,
            'activeCount' => $this->countProjects(
                $projects,
                fn (CarbonImmutable $start, CarbonImmutable $end): bool => $start->lte($today) && $end->gte($today)
            ),
            'upcomingCount' => $this->countProjects(
                $projects,
                fn (CarbonImmutable $start, CarbonImmutable $end): bool => $start->gt($today)
            ),
        ]);
    }

    /**
     * My Projects: every assignment as a DataTable, each row opening the
     * read-only project view.
     */
    public function projects(Request $request)
    {
        // Cancelled work is included here and nowhere else in the portal: the
        // status tabs offer it, and a lead should be able to look back at a
        // job that was called off.
        $projects = $this->assignedProjects($this->technician($request), ['archived'])
            ->each(fn (Project $project) => $project->loadMissing('projectTypes'));

        // Completion blockers are deliberately not rendered here: the view
        // modal can complete a task, which changes them, so the confirmation
        // dialog fetches them when it opens instead.
        return view('technician.projects', [
            'projects' => $projects,
            // The same tabs and the same counts the administrative projects
            // table draws, from the same method - so "3 Ongoing" here means
            // exactly what it means there.
            'statusTabs' => Project::statusTabs($projects),
            // Closing a project is a lead's call; a technician only reads.
            'canCloseProjects' => $request->user()->isLeadTechnician(),
            // A deactivated account keeps whatever it was booked on - see
            // Project::inactiveCrew() - so the crew it leaves short has to be
            // told rather than left to find out on the day. The administrative
            // projects table already flags this; the lead running the job is
            // the other person who needs to know, and the flag is theirs
            // alone: a technician cannot act on somebody else's account and
            // has no tasks to move.
            'flagsInactiveCrew' => $request->user()->isLeadTechnician(),
        ]);
    }

    /**
     * One project, in full.
     *
     * The Super Admin project details page as a lead sees it: the same
     * information in the same order, with every control that edits the
     * project record itself left out. What remains is the task board and the
     * report log, which are the lead's to run.
     */
    public function showProject(Request $request, Project $project)
    {
        $this->authorize('viewAssigned', $project);

        $project->load([
            'clients',
            'documents',
            'schedules',
            'projectTypes',
            'completionPhotos',
            'projectTechnicians.technician.account',
            // The Assigned Team panel lists each technician's approved
            // specialties beside their name.
            'projectTechnicians.technician.skills',
        ]);

        // The lead first, matching how the Super Admin page orders the team.
        $project->setRelation(
            'projectTechnicians',
            $project->projectTechnicians
                ->sortByDesc(fn (ProjectTechnician $assignment): bool => optional($assignment->technician?->account)->role === 'lead_technician')
                ->values()
        );

        // Narrowed to what the reader is allowed to see: a lead runs the whole
        // board, a technician reads their own work and nothing else. Done in
        // SQL rather than in the view so a colleague's task never reaches the
        // page to be hidden - see Task::scopeVisibleTo.
        $tasks = Task::query()
            ->visibleTo($request->user())
            ->with(['technician.account', 'images', 'completedBy'])
            ->where('project_id', $project->project_id)
            ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 when status = 'unassigned' then 2 else 3 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get();

        // Archived reports are off this list, exactly as they are off the
        // administrator's copy of the page: archiving one from either portal
        // takes it out of both.
        $reports = TechnicianReport::query()
            ->active()
            ->with(['images', 'technician.account', 'submitter', 'project.clients'])
            ->where('project_id', $project->project_id)
            ->when(
                $request->filled('report_type'),
                fn ($query) => $query->where('report_type', $request->string('report_type'))
            )
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        // The lead leads the Assign To picker, matching the administrator's
        // copy of this page and the Create Task dialog.
        $technicians = $project->projectTechnicians
            ->pluck('technician')
            ->filter()
            ->sortBy(fn ($technician): string => sprintf(
                '%d %s',
                optional($technician->account)->role === 'lead_technician' ? 0 : 1,
                mb_strtolower((string) $technician->name)
            ))
            ->values();

        // This project's load, not the whole system's - see TechnicianTaskLoad.
        $technicianActiveTaskCounts = app(TechnicianTaskLoad::class)
            ->forProject($project->project_id);

        $user = $request->user();

        return view('technician.projectDetails', [
            'project' => $project,
            'tasks' => $tasks,
            'reports' => $reports,
            'technicians' => $technicians,
            'technicianActiveTaskCounts' => $technicianActiveTaskCounts,
            'technicianId' => $this->technician($request)->technician_id,
            'scheduleRanges' => collect($this->scheduleRules->ranges($project->project_id)),
            'canManageTasks' => $this->projectPolicy->manageTasks($user, $project),
            'canSubmitReport' => $this->projectPolicy->submitReport($user, $project),
            // Whether this project is even in a state to be closed out. A
            // Pending, Unscheduled or paused project is not, so the button is
            // not drawn at all rather than opening a dialog that can only
            // refuse - see ProjectPolicy::offersCompletion().
            'canCloseProject' => $this->projectPolicy->offersCompletion($user, $project),
            // Same flag My Projects carries, for the same reason: the lead is
            // told their crew is short, and here they can see which of them it
            // is and move the work off them.
            'flagsInactiveCrew' => $user->isLeadTechnician(),
            // The overdue banner is a lead's notice, not the crew's. It asks
            // the reader to close the project off or to go and have the
            // schedule extended, and a plain technician can do neither - so to
            // them it is a warning about somebody else's decision, on a page
            // whose only remaining purpose is the work they still owe. The
            // status badge still reads Overdue for everyone; what is withheld
            // is the instruction, not the fact.
            'showsOverdueNotice' => ! $user->isTechnician(),
            'completionBlockers' => $this->projectPolicy->blockerDetailsFor($project),
            'reportTypes' => TechnicianReport::TYPES,
        ]);
    }

    /**
     * Tasks: the whole board for every assigned project, grouped by project
     * rather than flattened into one undifferentiated list.
     */
    public function tasks(Request $request)
    {
        $technician = $this->technician($request);

        // A finished project has no board left to run, so it drops off this
        // page entirely - unlike My Projects, which keeps it for the record.
        $projects = $this->assignedProjects($technician, ['cancelled', 'archived', 'completed']);

        $tasks = Task::query()
            ->visibleTo($request->user())
            ->with(['technician.account', 'images', 'completedBy'])
            ->whereIn('project_id', $projects->pluck('project_id'))
            ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 when status = 'unassigned' then 2 else 3 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get()
            ->groupBy('project_id');

        // The per-task dialogs are the Super Admin ones, and they need each
        // project's own team and date ranges to render.
        $techniciansByProject = $projects->mapWithKeys(fn (Project $project): array => [
            $project->project_id => $project->projectTechnicians->pluck('technician')->filter()->values(),
        ]);

        $rangesByProject = $projects->mapWithKeys(fn (Project $project): array => [
            $project->project_id => collect($this->scheduleRules->ranges($project->project_id)),
        ]);

        // Keyed by project too: this page shows several boards at once.
        $technicianActiveTaskCounts = app(TechnicianTaskLoad::class)
            ->forProjects($projects->pluck('project_id'));

        $user = $request->user();

        $manageable = $projects->mapWithKeys(fn (Project $project): array => [
            $project->project_id => $this->projectPolicy->manageTasks($user, $project),
        ]);

        // Work on this board that cannot proceed: nobody holds it, it has no
        // dates, or neither.
        //
        // A lead has no dashboard of their own and is not getting one for
        // this, so the alert lives at the top of the page they already run the
        // board from. The scope handed over is deliberately theirs and not the
        // whole board - visibleTo() plus the projects they are actually on -
        // so a lead is never told about a stuck task on somebody else's job.
        // What counts as stuck is not theirs to decide: that is
        // Task::scopeNeedsAssignment(), the same rule the Super Admin
        // dashboard counts by.
        //
        // Offered to leads only. A plain technician's board is their own work,
        // an unheld task is never on it, and they may not assign anybody - so
        // for them this would be an alert about nothing they can reach.
        $attentionSummary = ['total' => 0, 'counts' => [], 'lines' => []];
        $attentionReadOnly = null;

        if ($user->isLeadTechnician()) {
            $mine = fn (): Builder => Task::query()
                ->visibleTo($user)
                ->whereIn('project_id', $projects->pluck('project_id'));

            $attentionSummary = app(TaskAssignmentGaps::class)->summarise($mine());

            // Which projects the stuck tasks are actually on, so the note
            // below is about this backlog rather than about the page.
            $affected = $mine()->needsAssignment()->distinct()->pluck('project_id');

            // A lead only runs the board on a live, scheduled project of their
            // own (see ProjectPolicy::manageTasks). Where they cannot, the row
            // opens read-only - the task dialog is handed no technician list
            // and no form action - so the alert says who to ask rather than
            // implying a control that is not there.
            $canResolveAny = $affected->contains(
                fn ($projectId): bool => (bool) ($manageable[$projectId] ?? false)
            );

            if ($attentionSummary['total'] > 0 && ! $canResolveAny) {
                $attentionReadOnly = 'These tasks are on projects you cannot edit, so an administrator will need to fill in what is missing.';
            }
        }

        return view('technician.tasks', [
            'projects' => $projects,
            'tasksByProject' => $tasks,
            'attentionSummary' => $attentionSummary,
            'attentionReadOnly' => $attentionReadOnly,
            'techniciansByProject' => $techniciansByProject,
            'rangesByProject' => $rangesByProject,
            'technicianActiveTaskCounts' => $technicianActiveTaskCounts,
            'technicianId' => $technician->technician_id,
            'manageable' => $manageable,
            // Only projects that can actually take a new task are offered in
            // the Add Task dialog.
            'creatableProjects' => $projects->filter(
                fn (Project $project): bool => $manageable[$project->project_id]
            )->values(),
        ]);
    }

    /**
     * Reports: everything this lead has filed themselves, plus the form to
     * file more.
     *
     * "Filed themselves" is the account that pressed Submit, not the
     * technician the report is filed against. The two are usually the same
     * person, but not always: an administrator filing a report from the
     * Reports page credits it to the project's lead when the form does not say
     * otherwise (see TechnicianReportController::defaultTechnicianId), and a
     * report somebody else wrote has no business in this lead's own list.
     *
     * Reports written before submitted_by existed carry no account at all, so
     * they fall back to the technician the report is about - which for those
     * rows is who filed them.
     *
     * This narrowing is deliberately confined to this page. Opening a project
     * still shows every report on it whoever filed it: the question there is
     * "what has happened on this job", not "what have I written".
     */
    public function reports(Request $request)
    {
        $technician = $this->technician($request);
        $accountId = $request->user()->id;

        $reports = TechnicianReport::query()
            ->active()
            ->with([
                'project.clients',
                'images',
                'technician.account',
                'submitter',
            ])
            ->where(fn ($mine) => $this->scopeToOwnReports($mine, $accountId, $technician->technician_id))
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        return view('technician.reports', [
            'reports' => $reports,
            // The Project list is built from the rows actually on the page, so
            // it can never offer a filter that matches nothing.
            'filterProjects' => $reports
                ->map(fn (TechnicianReport $report): ?Project => $report->project)
                ->filter()
                ->unique('project_id')
                ->sortBy('reference_no')
                ->values(),
            // The viewer reads from this rather than re-fetching a report it
            // has already been handed.
            'reportPayloads' => $reports
                ->mapWithKeys(fn (TechnicianReport $report): array => [
                    $report->id => $this->reportPayload($report, $request->user()),
                ]),
            'reportableProjects' => $this->assignedProjects($technician)
                ->filter(fn (Project $project): bool => $this->projectPolicy->submitReport($request->user(), $project))
                ->values(),
            'reportTypes' => TechnicianReport::TYPES,
        ]);
    }

    /**
     * The archive behind the Reports page: the reports this lead filed away
     * themselves.
     *
     * Narrowed exactly as the active log is, and for the same reason - a lead
     * reads what they wrote, not what the office archived on some other
     * project. Restoring is offered per row and re-checked by
     * TechnicianReportPolicy on the way in, so the list and the endpoint agree
     * about what is theirs.
     *
     * Nothing here is a stub: an archived report keeps its pictures, its
     * project and the day it was filed, and the viewer shows all of it.
     */
    public function archivedReports(Request $request)
    {
        $technician = $this->technician($request);
        $accountId = $request->user()->id;

        $reports = TechnicianReport::query()
            ->archived()
            ->with([
                'project.clients',
                'images',
                'technician.account',
                'submitter',
                'archiver',
            ])
            ->where(fn ($mine) => $this->scopeToOwnReports($mine, $accountId, $technician->technician_id))
            // Rows archived before the timestamp existed sort last rather than
            // first, which is what a null would otherwise do on some engines.
            ->orderByRaw('archived_at is null')
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->get();

        $user = $request->user();

        return view('technician.archivedReports', [
            'reports' => $reports,
            'reportPayloads' => $reports->mapWithKeys(fn (TechnicianReport $report): array => [
                $report->id => $this->reportPayload($report, $user),
            ]),
        ]);
    }

    /**
     * "Reports this account filed", as a query condition.
     *
     * The account that pressed Submit, or - for reports written before
     * submitted_by existed, which carry no account at all - the technician the
     * report was filed under, who for those rows is who wrote it. Shared by the
     * active log and its archive so the two can never disagree about whose
     * report it is.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|Builder<TechnicianReport>  $query
     */
    private function scopeToOwnReports($query, int $accountId, int $technicianId): void
    {
        $query->where('submitted_by', $accountId)
            ->orWhere(fn ($legacy) => $legacy
                ->whereNull('submitted_by')
                ->where('technician_id', $technicianId));
    }

    // ------------------------------------------------------------------
    // JSON: reading a project
    // ------------------------------------------------------------------

    /**
     * Everything the schedule panel and the View Project modal render.
     *
     * One payload serves both so the two screens can never disagree about a
     * project. `mine_only` trims the task list to this lead's own work, which
     * is what the schedule panel shows.
     */
    public function projectDetails(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewAssigned', $project);

        $technician = $this->technician($request);
        $mineOnly = $request->boolean('mine_only');

        $project->load([
            'clients',
            'schedules',
            'projectTechnicians.technician.account',
            'tasks' => fn ($query) => $query
                ->visibleTo($request->user())
                ->when($mineOnly, fn ($q) => $q->where('technician_id', $technician->technician_id))
                ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 when status = 'unassigned' then 2 else 3 end")
                ->orderByRaw('due_date is null')
                ->orderBy('due_date'),
            'tasks.technician.account',
            'tasks.images',
            'tasks.completedBy',
        ]);

        $reports = TechnicianReport::query()
            ->active()
            ->with(['images', 'technician.account', 'submitter', 'project.clients'])
            ->where('project_id', $project->project_id)
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        $user = $request->user();

        return response()->json([
            'project' => $this->projectPayload($project),
            'tasks' => $project->tasks->map(fn (Task $task): array => $this->taskPayload($task, $technician))->all(),
            'reports' => $reports->map(fn (TechnicianReport $report): array => $this->reportPayload($report, $user))->all(),
            'permissions' => [
                'manage_tasks' => $this->projectPolicy->manageTasks($user, $project),
                'submit_report' => $this->projectPolicy->submitReport($user, $project),
                'complete_project' => $this->projectPolicy->complete($user, $project),
            ],
            // With the link to whatever fixes each one: the completion dialog
            // on My Projects renders these, and a refusal a lead cannot act on
            // is a dead end.
            'completion_blockers' => $this->projectPolicy->blockerDetailsFor($project),
            'task_form' => $this->taskFormData_($project),
        ]);
    }

    // ------------------------------------------------------------------
    // JSON: writing
    // ------------------------------------------------------------------

    /**
     * Create a task for somebody already on the project.
     *
     * Same shape as the Super Admin form, same validation: a technician who
     * is not on the project is rejected, and the dates have to sit inside one
     * of the project's schedule ranges.
     */
    public function storeTask(Request $request, Project $project)
    {
        $this->authorize('manageTasks', $project);

        $ranges = $this->scheduleRules->ranges($project->project_id);

        if ($ranges === []) {
            return $this->failed($request, 'This project has no schedule yet, so tasks cannot be dated.');
        }

        $validator = Validator::make($request->all(), [
            'task_title' => ['required', 'string', 'max:255'],
            'task_description' => ['required', 'string'],
            'technician_id' => ['required', 'integer', $this->assignedTechnicianRule($project)],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'technician_id.*' => 'Pick a technician who is assigned to this project.',
        ]);

        $this->scheduleRules->attach($validator, $ranges);
        // New work, so there is no current owner to make an exception for.
        $this->assignmentRules->attach($validator);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();

        // The Super Admin board treats an identical open task as a duplicate,
        // and so does this one - the same job twice is a scheduling mistake.
        $duplicate = Task::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $validated['technician_id'])
            ->whereIn('status', Task::OPEN_STATUSES)
            ->whereRaw('lower(task_title) = ?', [mb_strtolower($validated['task_title'])])
            ->exists();

        if ($duplicate) {
            return $this->failed(
                $request,
                'That technician already has an open task with this title on this project.'
            );
        }

        $task = Task::create($validated + [
            'project_id' => $project->project_id,
            'status' => 'pending',
        ]);

        $this->activityLogger->record(
            ActivityLog::TASK_CREATED,
            null,
            sprintf("Created task '%s' on %s.", $task->task_title, $project->reference_no),
            $task
        );

        $this->notifications->taskAssigned($task);

        return $this->succeeded($request, 'Task created.', fn (): array => [
            'task' => $this->taskPayload(
                $task->load(['technician.account', 'images', 'completedBy']),
                $this->technician($request)
            ),
        ], 201);
    }

    /**
     * Edit an existing task on a project this lead runs.
     */
    public function updateTask(Request $request, Task $task)
    {
        $this->authorize('update', $task);

        $project = $task->project;
        $ranges = $this->scheduleRules->ranges($project->project_id);

        if ($ranges === []) {
            return $this->failed($request, 'This project has no schedule yet.');
        }

        $validator = Validator::make($request->all(), [
            'task_title' => ['required', 'string', 'max:255'],
            'task_description' => ['required', 'string'],
            'technician_id' => ['required', 'integer', $this->assignedTechnicianRule($project)],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $this->scheduleRules->attach($validator, $ranges);
        // Whoever holds the task may keep it: editing its wording or its dates
        // re-submits the owner, and refusing that would make an inactive
        // technician's work uneditable - including the handover off them.
        $this->assignmentRules->attach($validator, (int) $task->technician_id);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();

        // Matches TaskController: giving an orphaned task an owner puts it
        // back in the queue rather than leaving it unassigned.
        if ($task->status === 'unassigned') {
            $validated['status'] = 'pending';
        }

        $task->update($validated);

        $this->activityLogger->record(
            ActivityLog::TASK_UPDATED,
            null,
            sprintf("Updated task '%s'.", $task->task_title),
            $task
        );

        return $this->succeeded($request, 'Task updated.', fn (): array => [
            'task' => $this->taskPayload(
                $task->fresh(['technician.account', 'images', 'completedBy']),
                $this->technician($request)
            ),
        ]);
    }

    /**
     * Close a task.
     *
     * Your own work is closed with an account of it. A lead closing somebody
     * else's has no first-hand account to give, so the notes and photos are
     * optional there and the completion panel records who closed it instead.
     */
    public function completeTask(Request $request, Task $task)
    {
        $this->authorize('complete', $task);

        $user = $request->user();
        $mustDescribe = app(TaskPolicy::class)->mustDescribeCompletion($user, $task);

        $validator = Validator::make($request->all(), [
            'completion_notes' => [$mustDescribe ? 'required' : 'nullable', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'completion_notes.required' => 'Describe what was done before completing the task.',
        ]);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        try {
            DB::transaction(function () use ($request, $task, $validator, $user): void {
                $task->update([
                    'status' => 'completed',
                    'completion_notes' => $validator->validated()['completion_notes'] ?? null,
                    'completed_at' => now(),
                    'completed_by' => $user->id,
                ]);

                foreach ($request->file('images') ?? [] as $image) {
                    TaskImage::create([
                        'task_id' => $task->task_id,
                        'image_path' => UploadStore::put($image, 'task_images'),
                    ]);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        $this->activityLogger->record(
            ActivityLog::TASK_COMPLETED,
            null,
            sprintf("Marked task '%s' as completed.", $task->task_title),
            $task
        );

        $this->notifications->taskCompleted($task, count($request->file('images') ?? []) > 0);

        return $this->succeeded($request, 'Task completed.', fn (): array => [
            'task' => $this->taskPayload(
                $task->fresh(['technician.account', 'images', 'completedBy']),
                $this->technician($request)
            ),
        ]);
    }

    /**
     * Remove a task from a project this lead runs.
     *
     * A task raised in error is still an error after somebody ticks it off, so
     * completed ones can go too - along with whatever completion photos they
     * carried, which the confirmation dialog says outright.
     */
    public function destroyTask(Request $request, Task $task)
    {
        $this->authorize('delete', $task);

        try {
            DB::transaction(function () use ($task): void {
                foreach ($task->images as $image) {
                    UploadStore::remove($image->image_path);
                    $image->delete();
                }

                $task->delete();
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        $this->activityLogger->record(
            ActivityLog::TASK_ARCHIVED,
            null,
            sprintf("Deleted task '%s'.", $task->task_title),
            $task
        );

        $this->notifications->taskCancelled($task);

        return $this->succeeded($request, 'Task deleted.');
    }

    /**
     * What the Add Task form needs once a project is picked.
     *
     * Deliberately the same shape TaskController@projectFormData answers with,
     * so one script drives the dialog in both portals.
     */
    public function taskFormData(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageTasks', $project);

        $project->load('projectTechnicians.technician.account');

        $formData = $this->taskFormData_($project);

        if ($formData['ranges'] === []) {
            return response()->json([
                'error' => 'This project has no schedule yet, so tasks cannot be dated.',
            ], 422);
        }

        return response()->json($formData);
    }

    /**
     * File a technician report against a project this lead is on.
     *
     * Deliberately not routed through TechnicianReportController: that action
     * trusts whichever technician_id the form sends, which is right for an
     * administrator filing on someone's behalf and wrong here. A lead's
     * report is always filed under the lead.
     */
    public function storeReport(Request $request, Project $project)
    {
        $this->authorize('submitReport', $project);

        $validator = Validator::make($request->all(), [
            'report_type' => ['required', 'in:'.implode(',', array_keys(TechnicianReport::TYPES))],
            'report_title' => ['required', 'string', 'max:255'],
            'report_description' => ['required', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();
        $technician = $this->technician($request);
        $report = null;

        try {
            DB::transaction(function () use ($request, $project, $technician, $validated, &$report): void {
                $report = TechnicianReport::create([
                    'project_id' => $project->project_id,
                    'technician_id' => $technician->technician_id,
                    'submitted_by' => $request->user()->id,
                    'report_type' => $validated['report_type'],
                    'report_title' => $validated['report_title'],
                    'report_description' => $validated['report_description'],
                    'report_date' => now()->toDateString(),
                ]);

                foreach ($request->file('images') ?? [] as $image) {
                    TechnicianReportImage::create([
                        'technician_report_id' => $report->id,
                        'image_path' => UploadStore::put($image, 'report_images'),
                    ]);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        $this->activityLogger->record(
            ActivityLog::REPORT_GENERATED,
            null,
            sprintf('Filed a %s on %s.', strtolower($report->typeLabel()), $project->reference_no),
            $report
        );

        $this->notifications->technicianReportFiled($project, strtolower($report->typeLabel()));

        return $this->succeeded($request, 'Report submitted.', fn (): array => [
            'report' => $this->reportPayload($report->load(['project', 'images']), $request->user()),
        ], 201);
    }

    /**
     * Hand a finished project over to its client for confirmation.
     *
     * The policy decides whether it can; blockersFor() says why not, and the
     * confirmation dialog shows that list rather than a bare refusal.
     *
     * A lead no longer closes a project outright. Pressing this records the
     * completion report, releases the dates booked past the completion date,
     * and asks the client to confirm - after which the project is out of the
     * lead's hands either way.
     */
    public function completeProject(Request $request, Project $project)
    {
        $this->authorize('viewAssigned', $project);

        $blockers = $this->projectPolicy->blockerDetailsFor($project);

        if ($blockers !== [] || ! $this->projectPolicy->complete($request->user(), $project)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'This project cannot be completed yet.',
                    'blockers' => $blockers,
                ], 422);
            }

            // The full-page route back is the project's own page, which draws
            // the same blockers with the same links, so the flash message only
            // has to say what happened.
            return back()->with(
                'error',
                'This project cannot be completed yet. '
                    .implode(' ', array_column($blockers, 'message'))
            );
        }

        // The same completion report the Super Admin portal collects, from the
        // same rule set, with one difference: a lead is on site, so the
        // photographs are the evidence and are required rather than optional.
        $completion = app(ProjectCompletion::class);

        $validator = Validator::make(
            $request->all(),
            $completion->rules(photosRequired: true),
            $completion->messages()
        );

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();

        try {
            DB::transaction(function () use ($request, $project, $validated, $completion): void {
                $completion->requestCompletion(
                    $project,
                    $validated,
                    $request->file('completion_photos'),
                    $request->user()
                );
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        // Recorded from this portal exactly as it is from the other one. The
        // lead closing out their own project is the commonest route into
        // completion, and it used to leave no audit entry at all.
        $this->activityLogger->record(
            ActivityLog::PROJECT_COMPLETION_REQUESTED,
            null,
            sprintf(
                "Marked project '%s' complete as of %s and sent it for client confirmation "
                    .'(Ongoing -> Awaiting Client Confirmation). Its schedule now holds %s.',
                $project->reference_no ?? $project->name,
                $project->completed_at?->format(BusinessTime::DATE) ?? 'today',
                $completion->describeRemainingSchedule($project)
            ),
            $project
        );

        $this->notifications->projectAwaitingClientConfirmation($project);

        // Completed from the portal is still completed, so the client hears
        // about it in exactly the same words as when an administrator does it.
        app(ProjectEmails::class)->projectAwaitingConfirmation($project->refresh());

        $message = sprintf(
            'Completion recorded. Completes automatically in %d days unless the client replies.',
            Project::completionConfirmationDays()
        );

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        // The project is view only now, so there is nothing left to stay on.
        return redirect()
            ->route('technician.projects')
            ->with('success', $message);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function technician(Request $request): Technician
    {
        return $request->user()->technicianRecord();
    }

    /**
     * Answer whichever way the caller asked.
     *
     * The task dialogs are plain Blade forms shared with the Super Admin
     * portal, so they post and expect to land back on the page with a flash
     * message. The schedule panel calls the same endpoints over fetch and
     * expects JSON, because reloading would throw away the project it has
     * open.
     *
     * @param  (\Closure(): array<string, mixed>)|null  $payload
     */
    private function succeeded(Request $request, string $message, ?\Closure $payload = null, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + ($payload ? $payload() : []), $status);
        }

        return back()->with('success', $message);
    }

    private function failed(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $message], 422);
        }

        return back()->with('error', $message);
    }

    /**
     * Every project this technician is assigned to, with the relations the
     * portal reads, loaded once.
     *
     * `$hide` says which statuses to leave out, because the pages disagree:
     * My Projects shows everything bar archived, the task board drops finished
     * work too, and the calendar drops cancelled work.
     *
     * @param  array<int, string>  $hide
     * @return Collection<int, Project>
     */
    private function assignedProjects(Technician $technician, array $hide = self::HIDDEN_STATUSES): Collection
    {
        return Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->where('is_archived', false)
            ->whereNotIn('status', $hide)
            ->whereHas(
                'projectTechnicians',
                fn ($query) => $query->where('technician_id', $technician->technician_id)
            )
            ->orderByDesc('project_id')
            ->get();
    }

    /**
     * "This technician is on that project" as a validation rule, so a posted
     * technician_id can never reach across projects.
     */
    private function assignedTechnicianRule(Project $project): Exists
    {
        return Rule::exists('tbl_project_technicians', 'technician_id')
            ->where('project_id', $project->project_id)
            // Somebody taken off the team keeps their row - it carries the
            // dates they worked - so the membership has to be an open one or
            // a removed technician would still pass as assignable here.
            ->whereNull('removed_at');
    }

    /**
     * @param  \Closure(CarbonImmutable, CarbonImmutable): bool  $matches
     * @param  Collection<int, Project>  $projects
     */
    private function countProjects(Collection $projects, \Closure $matches): int
    {
        return $projects
            ->filter(fn (Project $project): bool => $project->schedules->contains(
                fn (Schedule $schedule): bool => $matches(
                    CarbonImmutable::parse($schedule->start_datetime)->startOfDay(),
                    CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->startOfDay()
                )
            ))
            ->count();
    }

    /**
     * Every schedule this technician holds a booking on.
     *
     * The booking is the record: tbl_schedule_technicians says which ranges a
     * person was actually put on, which is not the same as every range their
     * project happens to hold. Somebody who joined a job in week three was
     * never on weeks one and two - ProjectTeam refuses to write those links
     * for exactly that reason - and somebody taken off in week five keeps the
     * weeks they worked, because removal only releases the dates still ahead.
     *
     * Read through the membership rather than the current team, so a closed
     * membership still carries its dates onto this calendar. That is the one
     * thing this page needs that My Projects must not do.
     *
     * @return Collection<int, Schedule>
     */
    private function bookedSchedules(Technician $technician): Collection
    {
        return Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->where('is_archived', false)
                    ->whereNotIn('status', self::HIDDEN_STATUSES);
            })
            ->whereHas('scheduleTechnicians.projectTechnician', function ($query) use ($technician): void {
                $query->where('technician_id', $technician->technician_id);
            })
            // project.schedules is needed because isOverdue() inspects every
            // range; without it each event would fire its own query.
            ->with([
                'project.clients',
                'project.schedules',
                'scheduleTechnicians.projectTechnician',
            ])
            ->orderBy('start_datetime')
            ->get()
            // HIDDEN_STATUSES above already keeps cancelled work off this
            // calendar - the administrative ones draw it, a technician's own
            // does not - so in practice this only drops archived work today.
            // The cutoff is asked anyway, here and in calendarEvent(), so all
            // three calendars run the same rule and letting cancelled work
            // through would be a one-line change rather than a rewrite.
            ->filter(fn (Schedule $schedule): bool => ($schedule->project?->showsOnCalendar() ?? false)
                && $schedule->startsOnOrBefore($schedule->project->calendarCutoff()))
            ->values();
    }

    /**
     * This technician's membership of the project the schedule belongs to,
     * taken from the booking already loaded against it.
     *
     * Read off the link rather than queried again: the schedule is only here
     * because one of their memberships is booked on it, so the row is in hand.
     */
    private function membershipFor(Schedule $schedule, Technician $technician): ?ProjectTechnician
    {
        return $schedule->scheduleTechnicians
            ->map(fn (ScheduleTechnician $link): ?ProjectTechnician => $link->projectTechnician)
            ->filter(fn (?ProjectTechnician $assignment): bool => $assignment !== null
                && (int) $assignment->technician_id === (int) $technician->technician_id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarEvent(
        Project $project,
        Schedule $schedule,
        ?ProjectTechnician $membership = null
    ): array {
        $isFormer = $membership?->isRemoved() ?? false;

        return [
            'id' => $schedule->schedule_id,
            'title' => $project->reference_no,
            // A partial day comes back as a timed event, so the bar carries
            // its hours instead of reading as a whole day.
            ...$schedule->toCalendarTimesThrough($project->calendarCutoff()),
            ...$project->calendarEventColors($schedule->isDateBased()),
            // Drawn flat and grey, so days that are a record of where you were
            // do not read as work you are still expected at.
            ...($isFormer ? ['classNames' => ['fc-event-former']] : []),
            'extendedProps' => [
                'projectId' => $project->project_id,
                'referenceNo' => $project->reference_no,
                'projectName' => $project->name,
                'client' => $this->clientName($project),
                'statusLabel' => $project->statusLabel(),
                'rangeLabel' => $schedule->describe(),
                // Everything the panel needs to explain a former booking is
                // here, and deliberately nothing more. A technician who has
                // been taken off a project should not get its client contact
                // details, its task board or its current state back by
                // clicking an old date - so the browser renders the note from
                // what it already holds and never asks the server for the
                // project at all. See the schedule page's eventClick.
                'isFormer' => $isFormer,
                'removedOn' => $isFormer
                    ? CarbonImmutable::parse($membership->removed_at)->format(BusinessTime::DATE)
                    : null,
            ],
        ];
    }

    /**
     * What the Add Task form needs once a project is known: who can be
     * assigned, and which days are selectable.
     *
     * @return array<string, mixed>
     */
    private function taskFormData_(Project $project): array
    {
        $activeTaskCounts = app(TechnicianTaskLoad::class)->forProject($project->project_id);

        $ranges = $this->scheduleRules->ranges($project->project_id);

        return [
            'ranges' => $ranges,
            'ranges_label' => $this->scheduleRules->describe($ranges),
            'technicians' => $project->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?array => $assignment->technician ? [
                    'technician_id' => $assignment->technician->technician_id,
                    'name' => $assignment->technician->name,
                    'role' => optional($assignment->technician->account)->role,
                    'is_lead' => optional($assignment->technician->account)->role === 'lead_technician',
                    // Somebody whose account has been switched off stays on the
                    // list, because they are still on the team - but the card is
                    // rendered unselectable. See TaskAssignmentRules, which
                    // refuses the same choice on the way back in.
                    'can_receive_work' => $assignment->technician->isAssignable(),
                    // Their own picture, or the default avatar - the same
                    // source the Blade-rendered assign cards draw from, so the
                    // two never show the same person differently.
                    'avatar_url' => $assignment->technician->account?->avatarUrl()
                        ?? asset('img/default-avatar.svg'),
                    'active_task_count' => (int) ($activeTaskCounts[$assignment->technician->technician_id] ?? 0),
                ] : null)
                ->filter()
                // The lead comes first, then everyone else alphabetically.
                ->sortBy(fn (array $technician): string => sprintf(
                    '%d %s',
                    $technician['is_lead'] ? 0 : 1,
                    mb_strtolower((string) $technician['name'])
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project): array
    {
        $schedules = $project->schedules;
        $start = $schedules->min('start_datetime');
        $end = $schedules->max('end_datetime');
        $client = $project->clients->first();

        return [
            'project_id' => $project->project_id,
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'client' => $this->clientName($project),
            'client_type' => $client?->client_type,
            'client_contact' => $client?->contact_number,
            'client_email' => $client?->email_address,
            'address' => $project->address,
            'description' => $project->description,
            'status' => $project->status,
            'status_label' => $project->statusLabel(),
            'status_badge_class' => $project->statusBadgeClass(),
            'start_date' => $start ? CarbonImmutable::parse($start)->format(BusinessTime::DATE) : null,
            'end_date' => $end ? CarbonImmutable::parse($end)->format(BusinessTime::DATE) : null,
            'ranges' => $schedules->map(fn (Schedule $schedule): array => [
                // The shared formatter, so a technician sees the hours they
                // are expected on site rather than a date twice over.
                'label' => $schedule->describe(),
                'is_partial_day' => $schedule->isPartialDay(),
            ])->values()->all(),
            'technicians' => $project->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?array => $assignment->technician ? [
                    'technician_id' => $assignment->technician->technician_id,
                    'name' => $assignment->technician->name,
                    'is_lead' => optional($assignment->technician->account)->role === 'lead_technician',
                ] : null)
                ->filter()
                ->sortByDesc('is_lead')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(Task $task, Technician $viewer): array
    {
        return [
            'task_id' => $task->task_id,
            'project_id' => $task->project_id,
            'title' => $task->task_title,
            'description' => $task->task_description,
            'technician_id' => $task->technician_id,
            'technician' => $task->technician?->name ?? 'Unassigned',
            // status, status_key, status_label and status_badge_class, all
            // from the one derivation - see TaskStatus.
            ...$task->statusPayload(),
            'start_date' => $task->start_date ? CarbonImmutable::parse($task->start_date)->toDateString() : null,
            'due_date' => $task->due_date ? CarbonImmutable::parse($task->due_date)->toDateString() : null,
            'start_date_label' => $task->start_date ? CarbonImmutable::parse($task->start_date)->format(BusinessTime::DATE) : '—',
            'due_date_label' => $task->due_date ? CarbonImmutable::parse($task->due_date)->format(BusinessTime::DATE) : '—',
            'is_mine' => (int) $task->technician_id === (int) $viewer->technician_id,
            // A lead may close anything on a project they run, so this asks
            // the policy rather than re-deriving the rule.
            'can_complete' => request()->user()?->can('complete', $task) ?? false,
            'completion_notes' => $task->completion_notes,
            // Through BusinessTime, not formatted off the stored instant: the
            // column is UTC, so a task closed at 7 AM in Manila is stored on
            // the previous UTC day and printed as the wrong date. The task
            // modal has always read it this way; this payload had not.
            'completed_at_label' => $task->completed_at
                ? BusinessTime::format($task->completed_at)
                : null,
            'closed_on_behalf' => $task->wasClosedOnBehalf(),
            'completed_by' => $task->completedBy?->fullName(),
            'images' => $task->relationLoaded('images')
                ? $task->images->map(fn (TaskImage $image): array => [
                    'url' => $image->url(),
                ])->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(TechnicianReport $report, ?User $user = null): array
    {
        return [
            'id' => $report->id,
            'display_code' => $report->displayCode(),
            'project_id' => $report->project_id,
            'project_name' => $report->project?->name ?? 'Project removed',
            'reference_no' => $report->project?->reference_no ?? '—',
            'title' => $report->report_title,
            'description' => $report->report_description,
            'client' => $this->reportClientName($report),
            'type' => $report->report_type,
            'type_label' => $report->typeLabel(),
            'type_badge_class' => $report->typeBadgeClass(),
            'type_accent_class' => $report->typeAccentClass(),
            'submitted_by' => $report->submitterName(),
            'submitted_by_avatar' => $report->submitterAvatarUrl(),
            'date' => $report->report_date?->toDateString(),
            'date_label' => $report->report_date?->format(BusinessTime::DATE) ?? '—',
            // The sort key the table's Date column reads, matching the
            // data-order the Blade rows carry.
            'date_order' => $report->report_date?->timestamp ?? 0,
            'images' => $report->images->map(fn (TechnicianReportImage $image): array => [
                'url' => $image->url(),
            ])->all(),
            'is_archived' => $report->isArchived(),
            'archived_at_label' => $report->archived_at?->format(BusinessTime::DATE) ?? '—',
            'archived_by' => $report->archiver?->fullName() ?? '—',
            // Whether this account may act on the report. The endpoint asks the
            // same policy again, so these only decide whether a button is
            // drawn - a lead sees Archive on their own reports and on no
            // others, whatever the page does with the payload.
            'can_archive' => (bool) $user?->can('archive', $report),
            'can_restore' => (bool) $user?->can('restore', $report),
        ];
    }

    private function clientName(Project $project): ?string
    {
        $client = $project->clients->first();

        return $client?->company_name ?: $client?->fullname;
    }

    /**
     * The client a report's project belongs to, for the reports table.
     */
    private function reportClientName(TechnicianReport $report): string
    {
        $project = $report->project;

        if (! $project) {
            return '—';
        }

        $project->loadMissing('clients');

        return $this->clientName($project) ?: '—';
    }
}
