<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
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
use App\Services\TaskScheduleRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
            'overdueCount' => $projects->filter->isOverdue()->count(),
            // Closing a project is a lead's call; a technician only reads.
            'canCloseProjects' => $request->user()->isLeadTechnician(),
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
            ->with(['images', 'technician.account'])
            ->where('project_id', $project->project_id)
            ->when(
                $request->filled('report_type'),
                fn ($query) => $query->where('report_type', $request->string('report_type'))
            )
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        $technicians = $project->projectTechnicians->pluck('technician')->filter()->values();

        $technicianActiveTaskCounts = Task::query()
            ->whereIn('technician_id', $technicians->pluck('technician_id'))
            ->whereIn('status', ['pending', 'ongoing'])
            ->selectRaw('technician_id, count(*) as active_count')
            ->groupBy('technician_id')
            ->pluck('active_count', 'technician_id');

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
            'canCloseProjects' => $user->isLeadTechnician(),
            'completionBlockers' => $this->projectPolicy->blockersFor($project),
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

        $technicianActiveTaskCounts = Task::query()
            ->whereIn('technician_id', $techniciansByProject->flatten()->pluck('technician_id')->unique())
            ->whereIn('status', ['pending', 'ongoing'])
            ->selectRaw('technician_id, count(*) as active_count')
            ->groupBy('technician_id')
            ->pluck('active_count', 'technician_id');

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
     * Reports: everything this lead has filed, plus the form to file more.
     */
    public function reports(Request $request)
    {
        $technician = $this->technician($request);

        $reports = TechnicianReport::query()
            ->with(['project', 'images'])
            ->where('technician_id', $technician->technician_id)
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        return view('technician.reports', [
            'reports' => $reports,
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
            ->with(['images', 'technician.account'])
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
            'completion_blockers' => $this->projectPolicy->blockersFor($project),
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
            return $this->failed($request, 'This project has no schedule yet, so a task has no dates to sit in.');
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
                        'image_path' => $image->store('task-completions', 'public'),
                    ]);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($request, $e->getMessage());
        }

        $this->activityLogger->record(
            ActivityLog::TASK_COMPLETED,
            null,
            sprintf("Marked task '%s' as completed.", $task->task_title),
            $task
        );

        $this->notifications->taskCompleted($task, count($request->file('images') ?? []) > 0);

        return $this->succeeded($request, 'Task marked as completed.', fn (): array => [
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
                    Storage::disk('public')->delete($image->image_path);
                    $image->delete();
                }

                $task->delete();
            });
        } catch (Throwable $e) {
            return $this->failed($request, $e->getMessage());
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
                'error' => 'This project has no schedule yet. A task has no dates to sit in.',
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
                    'report_type' => $validated['report_type'],
                    'report_title' => $validated['report_title'],
                    'report_description' => $validated['report_description'],
                    'report_date' => now()->toDateString(),
                ]);

                foreach ($request->file('images') ?? [] as $image) {
                    TechnicianReportImage::create([
                        'technician_report_id' => $report->id,
                        'image_path' => $image->store('technician-reports', 'public'),
                    ]);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($request, $e->getMessage());
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
     * Mark a project complete once nothing is left open on it.
     *
     * The policy decides whether it can; blockersFor() says why not, and the
     * confirmation dialog shows that list rather than a bare refusal.
     */
    public function completeProject(Request $request, Project $project)
    {
        $this->authorize('viewAssigned', $project);

        $blockers = $this->projectPolicy->blockersFor($project);

        if ($blockers !== [] || ! $this->projectPolicy->complete($request->user(), $project)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'This project cannot be completed yet.',
                    'blockers' => $blockers,
                ], 422);
            }

            return back()->with(
                'error',
                'This project cannot be completed yet. '.implode(' ', $blockers)
            );
        }

        // The same completion report the Super Admin portal collects, with one
        // difference: a lead is on site, so the photographs are the evidence
        // and are required rather than optional.
        $validator = Validator::make($request->all(), [
            'completion_date' => ['required', 'date'],
            'completion_summary' => ['required', 'string'],
            'completion_remarks' => ['nullable', 'string'],
            'completion_photos' => ['required', 'array', 'min:1'],
            'completion_photos.*' => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'completion_photos.required' => 'At least one completion photo is required.',
            'completion_photos.min' => 'At least one completion photo is required.',
        ]);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();

        try {
            DB::transaction(function () use ($request, $project, $validated): void {
                $project->update([
                    'status' => 'completed',
                    'on_hold' => false,
                    'completed_at' => CarbonImmutable::parse($validated['completion_date']),
                    'completion_summary' => $validated['completion_summary'],
                    'completion_remarks' => $validated['completion_remarks'] ?? null,
                ]);

                $this->storeCompletionPhotos($request, $project);

                // Dates booked past the completion date are released: the work
                // is done, so the project must stop reading as booked and its
                // technicians must stop reading as busy.
                app(ProjectCompletion::class)->releaseFutureSchedules(
                    $project,
                    CarbonImmutable::parse($validated['completion_date'])
                );
            });
        } catch (Throwable $e) {
            return $this->failed($request, $e->getMessage());
        }

        $this->notifications->projectCompleted($project);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Project marked as completed.']);
        }

        // The project is view only now, so there is nothing left to stay on.
        return redirect()
            ->route('technician.projects')
            ->with('success', 'Project marked as completed.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function technician(Request $request): Technician
    {
        return $request->user()->technicianRecord();
    }

    /**
     * Completion photographs, stored where ProjectController puts its own so
     * both portals' reports read from one place.
     */
    private function storeCompletionPhotos(Request $request, Project $project): void
    {
        $directory = public_path('uploads/completion');

        File::ensureDirectoryExists($directory);

        foreach ($request->file('completion_photos') ?? [] as $photo) {
            $fileName = Str::uuid()->toString().'.'.$photo->getClientOriginalExtension();
            $photo->move($directory, $fileName);

            ProjectCompletionPhoto::create([
                'project_id' => $project->project_id,
                'photo_path' => 'uploads/completion/'.$fileName,
                'uploaded_at' => now(),
            ]);
        }
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
        $start = CarbonImmutable::parse($schedule->start_datetime);
        $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

        return [
            'id' => $schedule->schedule_id,
            'title' => $project->reference_no,
            'start' => $start->toDateString(),
            // FullCalendar treats all-day end dates as exclusive.
            'end' => $end->addDay()->toDateString(),
            'color' => $project->calendarColor(),
            'extendedProps' => [
                'projectId' => $project->project_id,
                'referenceNo' => $project->reference_no,
                'projectName' => $project->name,
                'client' => $this->clientName($project),
                'statusLabel' => $project->statusLabel(),
                'rangeLabel' => $start->format('M j, Y').' - '.$end->format('M j, Y'),
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
        $technicianIds = $project->projectTechnicians->pluck('technician_id')->filter()->values();

        $activeTaskCounts = Task::query()
            ->whereIn('technician_id', $technicianIds)
            ->whereIn('status', ['pending', 'ongoing'])
            ->selectRaw('technician_id, count(*) as active_count')
            ->groupBy('technician_id')
            ->pluck('active_count', 'technician_id');

        $ranges = $this->scheduleRules->ranges($project->project_id);

        return [
            'ranges' => $ranges,
            'ranges_label' => $this->scheduleRules->describe($ranges),
            'technicians' => $project->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?array => $assignment->technician ? [
                    'technician_id' => $assignment->technician->technician_id,
                    'name' => $assignment->technician->name,
                    'is_lead' => optional($assignment->technician->account)->role === 'lead_technician',
                    'active_task_count' => (int) ($activeTaskCounts[$assignment->technician->technician_id] ?? 0),
                ] : null)
                ->filter()
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
                'label' => CarbonImmutable::parse($schedule->start_datetime)->format('M j, Y')
                    .' - '
                    .CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('M j, Y'),
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
                    'url' => asset('storage/'.$image->image_path),
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
            'project_id' => $report->project_id,
            'project_name' => $report->project?->name ?? 'Project removed',
            'reference_no' => $report->project?->reference_no ?? '—',
            'title' => $report->report_title,
            'description' => $report->report_description,
            'type' => $report->report_type,
            'type_label' => $report->typeLabel(),
            'type_badge_class' => $report->typeBadgeClass(),
            'submitted_by' => $report->technician?->name,
            'date' => $report->report_date?->toDateString(),
            'date_label' => $report->report_date?->format('M j, Y') ?? '—',
            // The sort key the table's Date column reads, matching the
            // data-order the Blade rows carry.
            'date_order' => $report->report_date?->timestamp ?? 0,
            'images' => $report->images->map(fn (TechnicianReportImage $image): array => [
                'url' => asset('storage/'.$image->image_path),
            ])->all(),
        ];
    }

    private function clientName(Project $project): ?string
    {
        $client = $project->clients->first();

        return $client?->company_name ?: $client?->fullname;
    }
}
