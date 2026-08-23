<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TaskImage;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use App\Services\TaskAssignmentRules;
use App\Services\TaskScheduleRules;
use App\Services\TechnicianTaskLoad;
use App\Support\UploadStore;
use Carbon\CarbonImmutable;
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
     * My Schedule: a calendar of every project this lead is booked on, with
     * the clicked project's details loaded into the panel beside it.
     */
    public function schedule(Request $request)
    {
        $technician = $this->technician($request);
        $projects = $this->assignedProjects($technician);

        $events = $projects
            ->filter(fn (Project $project): bool => $project->showsOnCalendar())
            ->flatMap(fn (Project $project) => $project->schedules->map(
                fn (Schedule $schedule): array => $this->calendarEvent($project, $schedule)
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

        $tasks = Task::query()
            ->with(['technician.account', 'images', 'completedBy'])
            ->where('project_id', $project->project_id)
            ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 when status = 'unassigned' then 2 else 3 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get();

        $reports = TechnicianReport::query()
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

        return view('technician.tasks', [
            'projects' => $projects,
            'tasksByProject' => $tasks,
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
            ->with([
                'project.clients',
                'images',
                'technician.account',
                'submitter',
            ])
            ->where(function ($mine) use ($accountId, $technician): void {
                $mine->where('submitted_by', $accountId)
                    ->orWhere(fn ($legacy) => $legacy
                        ->whereNull('submitted_by')
                        ->where('technician_id', $technician->technician_id));
            })
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
                    $report->id => $this->reportPayload($report),
                ]),
            'reportableProjects' => $this->assignedProjects($technician)
                ->filter(fn (Project $project): bool => $this->projectPolicy->submitReport($request->user(), $project))
                ->values(),
            'reportTypes' => TechnicianReport::TYPES,
        ]);
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
                ->when($mineOnly, fn ($q) => $q->where('technician_id', $technician->technician_id))
                ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 when status = 'unassigned' then 2 else 3 end")
                ->orderByRaw('due_date is null')
                ->orderBy('due_date'),
            'tasks.technician.account',
            'tasks.images',
            'tasks.completedBy',
        ]);

        $reports = TechnicianReport::query()
            ->with(['images', 'technician.account', 'submitter', 'project.clients'])
            ->where('project_id', $project->project_id)
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        $user = $request->user();

        return response()->json([
            'project' => $this->projectPayload($project),
            'tasks' => $project->tasks->map(fn (Task $task): array => $this->taskPayload($task, $technician))->all(),
            'reports' => $reports->map(fn (TechnicianReport $report): array => $this->reportPayload($report))->all(),
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
            'report' => $this->reportPayload($report->load(['project', 'images'])),
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
                $project->completed_at?->format('F j, Y') ?? 'today',
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
            Project::COMPLETION_CONFIRMATION_DAYS
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
            ->where('project_id', $project->project_id);
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
     * @return array<string, mixed>
     */
    private function calendarEvent(Project $project, Schedule $schedule): array
    {
        return [
            'id' => $schedule->schedule_id,
            'title' => $project->reference_no,
            // A partial day comes back as a timed event, so the bar carries
            // its hours instead of reading as a whole day.
            ...$schedule->toCalendarTimes(),
            ...$project->calendarEventColors($schedule->isDateBased()),
            'extendedProps' => [
                'projectId' => $project->project_id,
                'referenceNo' => $project->reference_no,
                'projectName' => $project->name,
                'client' => $this->clientName($project),
                'statusLabel' => $project->statusLabel(),
                'rangeLabel' => $schedule->describe(),
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
            'start_date' => $start ? CarbonImmutable::parse($start)->format('M j, Y') : null,
            'end_date' => $end ? CarbonImmutable::parse($end)->format('M j, Y') : null,
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
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'status_badge_class' => $task->statusBadgeClass(),
            'start_date' => $task->start_date ? CarbonImmutable::parse($task->start_date)->toDateString() : null,
            'due_date' => $task->due_date ? CarbonImmutable::parse($task->due_date)->toDateString() : null,
            'start_date_label' => $task->start_date ? CarbonImmutable::parse($task->start_date)->format('M j, Y') : '—',
            'due_date_label' => $task->due_date ? CarbonImmutable::parse($task->due_date)->format('M j, Y') : '—',
            'is_mine' => (int) $task->technician_id === (int) $viewer->technician_id,
            // A lead may close anything on a project they run, so this asks
            // the policy rather than re-deriving the rule.
            'can_complete' => request()->user()?->can('complete', $task) ?? false,
            'completion_notes' => $task->completion_notes,
            'completed_at_label' => $task->completed_at?->format('M j, Y'),
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
    private function reportPayload(TechnicianReport $report): array
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
            'date_label' => $report->report_date?->format('M j, Y') ?? '—',
            // The sort key the table's Date column reads, matching the
            // data-order the Blade rows carry.
            'date_order' => $report->report_date?->timestamp ?? 0,
            'images' => $report->images->map(fn (TechnicianReportImage $image): array => [
                'url' => $image->url(),
            ])->all(),
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
