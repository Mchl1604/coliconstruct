<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskImage;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\TaskScheduleRules;
use App\Services\TechnicianTaskLoad;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class TaskController extends Controller
{
    public function __construct(
        private TaskScheduleRules $scheduleRules,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Tasks page: one card per project, each holding that project's board,
     * with a filter above them and a single Add Task dialog that picks the
     * project first. Same layout the technician portal uses.
     */
    public function index(Request $request)
    {
        // Only live work belongs on this page: a project is listed when it is
        // pending or ongoing. Not-yet-scheduled, on-hold, completed, cancelled
        // and archived projects are all left out.
        $projects = Project::query()
            ->with(['schedules', 'projectTechnicians.technician.account'])
            ->whereIn('status', Project::ACTIVE_PROJECT_STATUSES)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        $tasksByProject = Task::query()
            ->with(['technician', 'images', 'completedBy'])
            ->whereIn('project_id', $projects->pluck('project_id'))
            ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 when status = 'unassigned' then 2 else 3 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get()
            ->groupBy('project_id');

        $techniciansByProject = $projects->mapWithKeys(fn (Project $project): array => [
            $project->project_id => $project->projectTechnicians->pluck('technician')->filter()->values(),
        ]);

        $rangesByProject = $projects->mapWithKeys(fn (Project $project): array => [
            $project->project_id => collect($this->scheduleRules->ranges($project->project_id)),
        ]);

        // An administrator runs every board; only a locked project is off
        // limits, and none of those are listed here anyway.
        $manageable = $projects->mapWithKeys(fn (Project $project): array => [
            $project->project_id => ! $project->isReadOnly() && ! $project->isArchived(),
        ]);

        // Keyed by project as well as by technician: this page shows several
        // boards at once, and the same technician on two projects has a
        // different amount of each of them on their plate. One query covers
        // every board on the page.
        $technicianActiveTaskCounts = app(TechnicianTaskLoad::class)
            ->forProjects($projects->pluck('project_id'));

        // Only projects that can actually receive new tasks are selectable in
        // the Add Task modal: completed, cancelled and archived are excluded.
        // An unscheduled project stays on the list and the form-data
        // endpoint explains why it cannot take a task, which reads better than
        // the project simply not being there.
        $schedulableProjects = Project::query()
            ->orderBy('name')
            ->get(['project_id', 'name', 'reference_no', 'status', 'on_hold', 'is_archived'])
            ->filter(fn (Project $project): bool => ! $project->isReadOnly() && ! $project->isArchived())
            ->values();

        return view('super-admin.tasks', compact(
            'projects',
            'tasksByProject',
            'techniciansByProject',
            'rangesByProject',
            'manageable',
            'schedulableProjects',
            'technicianActiveTaskCounts'
        ));
    }

    /**
     * JSON data used by the Add Task modal once a project is chosen:
     * that project's assigned team (so the technician list changes per
     * project) and its current schedule span (for the date min/max).
     */
    public function projectFormData(int $projectId)
    {
        $project = Project::with(['projectTechnicians.technician.account', 'schedules'])->findOrFail($projectId);

        if ($project->isReadOnly()) {
            return response()->json([
                'error' => 'This project is '.$project->status.' and no longer accepts new tasks.',
            ], 422);
        }

        $ranges = $this->scheduleRanges($projectId);

        if ($ranges === []) {
            return response()->json([
                'error' => 'This project has no schedule yet. Set a schedule before adding tasks.',
            ], 422);
        }

        $scheduleStart = $project->schedules->min('start_datetime');
        $scheduleEnd = $project->schedules->max('end_datetime');

        $technicians = $project->projectTechnicians
            ->filter(fn ($projectTechnician) => $projectTechnician->technician)
            ->map(fn ($projectTechnician) => $projectTechnician->technician);

        $activeTaskCounts = app(TechnicianTaskLoad::class)->forProject($project->project_id);

        $technicians = $technicians
            ->map(function ($technician) use ($activeTaskCounts) {
                $isLead = optional($technician->account)->role === 'lead_technician';

                return [
                    'technician_id' => $technician->technician_id,
                    'name' => $technician->name,
                    'role' => optional($technician->account)->role,
                    'is_lead' => $isLead,
                    // Their own picture, or the default avatar - the same
                    // source the Blade-rendered assign cards draw from, so the
                    // two never show the same person differently.
                    'avatar_url' => $technician->account?->avatarUrl() ?? asset('img/default-avatar.svg'),
                    'active_task_count' => (int) ($activeTaskCounts[$technician->technician_id] ?? 0),
                ];
            })
            // The lead comes first, as they already do in the Assigned Team
            // panel, then everyone else alphabetically.
            ->sortBy(fn (array $technician): string => sprintf(
                '%d %s',
                $technician['is_lead'] ? 0 : 1,
                mb_strtolower((string) $technician['name'])
            ))
            ->values();

        return response()->json([
            'technicians' => $technicians,
            // Kept as a coarse outer bound; `ranges` is what actually decides
            // which days are selectable, gaps included.
            'schedule_start' => Carbon::parse($scheduleStart)->format('Y-m-d'),
            'schedule_end' => Carbon::parse($scheduleEnd)->format('Y-m-d'),
            'ranges' => $ranges,
        ]);
    }

    public function store(Request $request, $projectId)
    {
        $project = Project::findOrFail($projectId);

        if ($project->isReadOnly()) {
            return redirect()
                ->back()
                ->with('error', 'This project is '.$project->status.' and no longer accepts new tasks.');
        }

        $ranges = $this->scheduleRanges($projectId);

        if ($ranges === []) {
            return redirect()
                ->back()
                ->with('error', 'This project has no schedule yet. Set a schedule before adding tasks.');
        }

        $validator = Validator::make($request->all(), [
            'task_title' => 'required|string|max:255',
            'task_description' => 'required|string',
            'technician_id' => ['required', 'integer', $this->assignedTechnicianRule($project)],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'technician_id.exists' => 'Pick a technician who is assigned to this project.',
        ]);

        $this->attachRangeRule($validator, $ranges);

        $validated = $validator->validate();

        // The same job twice is a scheduling mistake, and it is also what a
        // double-clicked Save produces. The technician portal has always
        // refused it; this board did not, so an impatient click made two
        // identical tasks.
        $duplicate = Task::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $validated['technician_id'])
            ->whereIn('status', Task::OPEN_STATUSES)
            ->whereRaw('lower(task_title) = ?', [mb_strtolower($validated['task_title'])])
            ->exists();

        if ($duplicate) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'That technician already has an open task with this title on this project.');
        }

        DB::beginTransaction();

        try {

            $task = Task::create([
                'project_id' => $projectId,
                'technician_id' => $validated['technician_id'],
                'task_title' => $validated['task_title'],
                'task_description' => $validated['task_description'],
                'start_date' => $validated['start_date'],
                'due_date' => $validated['due_date'],
                'status' => 'pending',
            ]);

            DB::commit();

            $this->activityLogger->record(
                ActivityLog::TASK_CREATED,
                null,
                sprintf("Created task '%s' on %s.", $task->task_title, $project->reference_no),
                $task
            );

            $this->notifications->taskAssigned($task);

            return redirect()
                ->back()
                ->with('success', 'Task created successfully.');
        } catch (\Exception $e) {

            DB::rollBack();

            return redirect()
                ->back()
                ->with('error', $this->safeErrorMessage($e, 'The task could not be saved. Nothing was changed.'));
        }
    }

    public function update(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);

        $project = Project::findOrFail($task->project_id);

        if ($project->isReadOnly()) {
            return back()->with('error', 'This project is '.$project->status.' and its tasks can no longer be edited.');
        }

        if ($task->status == 'completed') {
            return back();
        }

        $ranges = $this->scheduleRanges($task->project_id);

        if ($ranges === []) {
            return back()->with('error', 'This project has no schedule yet.');
        }

        $validator = Validator::make($request->all(), [
            'task_title' => 'required|string|max:255',
            'task_description' => 'required|string',
            'technician_id' => ['required', 'integer', $this->assignedTechnicianRule($project)],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'technician_id.exists' => 'Pick a technician who is assigned to this project.',
        ]);

        $this->attachRangeRule($validator, $ranges);

        $validated = $validator->validate();

        if ($task->status === 'unassigned') {
            $validated['status'] = 'pending';
        }

        // Read before the write, so the log can tell an edit from a handover.
        $before = $task->technician_id;
        $previousOwner = $this->notifications->taskOwner($task);

        DB::beginTransaction();

        try {
            $task->update($validated);

            // Moving a task to a different technician is a different event from
            // editing its wording or its dates, so it is recorded as one.
            $reassigned = (int) $before !== (int) $task->technician_id;

            DB::commit();

            $this->activityLogger->record(
                $reassigned ? ActivityLog::TASK_REASSIGNED : ActivityLog::TASK_UPDATED,
                null,
                sprintf(
                    $reassigned
                        ? "Reassigned task '%s' to %s."
                        : "Updated task '%s'. Assigned to %s.",
                    $task->task_title,
                    $task->fresh('technician')->technician?->name ?? 'nobody'
                ),
                $task
            );

            if ($reassigned) {
                $this->notifications->taskReassigned($task->fresh('technician.account'), $previousOwner);
            }

            return back()->with('success', 'Task updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', $this->safeErrorMessage($e, 'The task could not be saved. Nothing was changed.'));
        }
    }

    /**
     * Close a task off, with an account of what was done where there is one.
     *
     * An administrator closing somebody else's task did not do the work, so
     * the notes and photos are optional for them; the completion panel then
     * says who closed it rather than showing an empty record. Closing your own
     * task still asks for the account, exactly as the technician portal does.
     */
    public function complete(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        $project = Project::findOrFail($task->project_id);

        if ($project->isReadOnly()) {
            return back()->with('error', 'This project is '.$project->status.' and its tasks can no longer be edited.');
        }

        if ($task->status === 'completed') {
            return back()->with(
                'info',
                'Task is already completed.'
            );
        }

        $mustDescribe = $task->isAssignedTo($request->user());

        $validated = $request->validate([
            'completion_notes' => [$mustDescribe ? 'required' : 'nullable', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'completion_notes.required' => 'Describe what was done before completing the task.',
        ]);

        DB::beginTransaction();

        try {
            $task->update([
                'status' => 'completed',
                'completion_notes' => $validated['completion_notes'] ?? null,
                'completed_at' => now(),
                'completed_by' => $request->user()?->id,
            ]);

            foreach ($request->file('images') ?? [] as $image) {
                TaskImage::create([
                    'task_id' => $task->task_id,
                    'image_path' => $image->store('task-completions', 'public'),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', $this->safeErrorMessage($e, 'The task could not be saved. Nothing was changed.'));
        }

        $this->activityLogger->record(
            ActivityLog::TASK_COMPLETED,
            null,
            sprintf("Marked task '%s' as completed.", $task->task_title),
            $task
        );

        $photos = count($request->file('images') ?? []);

        $this->notifications->taskCompleted($task, $photos > 0);

        if ($photos > 0) {
            $this->activityLogger->record(
                ActivityLog::TASK_IMAGE_UPLOADED,
                null,
                sprintf('Uploaded %d completion photo(s) for %s.', $photos, $task->task_title),
                $task
            );
        }

        return back()->with(
            'success',
            'Task marked as completed.'
        );
    }

    public function destroy($taskId)
    {
        $task = Task::findOrFail($taskId);
        $project = Project::findOrFail($task->project_id);

        if ($project->isReadOnly()) {
            return back()->with('error', 'This project is '.$project->status.' and its tasks can no longer be edited.');
        }

        DB::beginTransaction();

        try {
            // The completion photos go with the task rather than being left
            // orphaned on disk.
            foreach ($task->images as $image) {
                Storage::disk('public')->delete($image->image_path);
                $image->delete();
            }

            $task->delete();

            DB::commit();

            $this->notifications->taskCancelled($task);

            return back()->with(
                'success',
                'Task deleted successfully.'
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with(
                'error',
                $this->safeErrorMessage($e, 'The task could not be deleted. Nothing was changed.')
            );
        }
    }

    /**
     * A task belongs to somebody on the project.
     *
     * The same rule the technician portal has always applied, stated the same
     * way. Without it this board could hand work to a technician who is not on
     * the team - and ProjectPolicy::viewAssigned() will then refuse them the
     * project, so they are notified about a job they cannot open.
     */
    private function assignedTechnicianRule(Project $project): Exists
    {
        return Rule::exists('tbl_project_technicians', 'technician_id')
            ->where('project_id', $project->project_id);
    }

    /**
     * Every date range on a project's schedule, as 'Y-m-d' pairs.
     *
     * @return array<int, array{start: string, end: string}>
     */
    private function scheduleRanges(int $projectId): array
    {
        return $this->scheduleRules->ranges($projectId);
    }

    /**
     * Add the "inside a single schedule range" check to a validator.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    private function attachRangeRule(\Illuminate\Validation\Validator $validator, array $ranges): void
    {
        $this->scheduleRules->attach($validator, $ranges);
    }
}
