<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    /**
     * Tasks page: shows tasks across all projects with a project filter
     * dropdown, and an Add Task modal that lets you pick the project first.
     */
    public function index(Request $request)
    {
        $tasks = Task::with([
            'project.projectTechnicians.technician.account',
            'project.schedules',
            'technician',
            'images',
        ])
            ->orderBy('project_id')
            ->orderBy('start_date')
            ->get();

        $projects = Project::query()
            ->orderBy('name')
            ->get(['project_id', 'name', 'reference_no', 'status']);

        // Only projects that can actually receive new tasks (editable and
        // already have a schedule) are selectable in the Add Task modal.
        $schedulableProjects = $projects->filter(function (Project $project) {
            return ! $project->isReadOnly();
        })->values();

        // Active task counts for every technician that shows up on any
        // project involved, used by the Assign To cards in both the Add
        // Task and per-task Edit Task modals.
        $allTechnicianIds = $tasks
            ->pluck('project.projectTechnicians')
            ->filter()
            ->flatten()
            ->pluck('technician_id')
            ->filter()
            ->unique()
            ->values();

        $technicianActiveTaskCounts = Task::query()
            ->whereIn('technician_id', $allTechnicianIds)
            ->whereIn('status', ['pending', 'ongoing'])
            ->selectRaw('technician_id, count(*) as active_count')
            ->groupBy('technician_id')
            ->pluck('active_count', 'technician_id');

        return view('super-admin.tasks', compact(
            'tasks',
            'projects',
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
                'error' => 'This project is ' . $project->status . ' and no longer accepts new tasks.',
            ], 422);
        }

        $scheduleStart = $project->schedules->min('start_datetime');
        $scheduleEnd = $project->schedules->max('end_datetime');

        if (! $scheduleStart || ! $scheduleEnd) {
            return response()->json([
                'error' => 'This project has no schedule yet. Set a schedule before adding tasks.',
            ], 422);
        }

        $technicians = $project->projectTechnicians
            ->filter(fn ($projectTechnician) => $projectTechnician->technician)
            ->map(fn ($projectTechnician) => $projectTechnician->technician);

        $technicianIds = $technicians->pluck('technician_id')->filter()->values();

        $activeTaskCounts = Task::query()
            ->whereIn('technician_id', $technicianIds)
            ->whereIn('status', ['pending', 'ongoing'])
            ->selectRaw('technician_id, count(*) as active_count')
            ->groupBy('technician_id')
            ->pluck('active_count', 'technician_id');

        $technicians = $technicians
            ->map(function ($technician) use ($activeTaskCounts) {
                return [
                    'technician_id' => $technician->technician_id,
                    'name' => $technician->name,
                    'role' => optional($technician->account)->role,
                    'active_task_count' => (int) ($activeTaskCounts[$technician->technician_id] ?? 0),
                ];
            })
            ->values();

        return response()->json([
            'technicians' => $technicians,
            'schedule_start' => \Carbon\Carbon::parse($scheduleStart)->format('Y-m-d'),
            'schedule_end' => \Carbon\Carbon::parse($scheduleEnd)->format('Y-m-d'),
        ]);
    }

    public function store(Request $request, $projectId)
    {
        $project = Project::findOrFail($projectId);

        if ($project->isReadOnly()) {
            return redirect()
                ->back()
                ->with('error', 'This project is ' . $project->status . ' and no longer accepts new tasks.');
        }

        [$projectStart, $projectEnd] = $this->scheduleSpan($projectId);

        if (! $projectStart || ! $projectEnd) {
            return redirect()
                ->back()
                ->with('error', 'This project has no schedule yet. Set a schedule before adding tasks.');
        }

        $validated = $request->validate([
            'task_title' => 'required|string|max:255',
            'task_description' => 'required|string',
            'technician_id' => 'required|exists:tbl_technicians,technician_id',
            'start_date' => [
                'required',
                'date',
                'after_or_equal:' . $projectStart,
                'before_or_equal:' . $projectEnd,
            ],
            'due_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                'before_or_equal:' . $projectEnd,
            ],
        ]);

        DB::beginTransaction();

        try {

            Task::create([
                'project_id' => $projectId,
                'technician_id' => $validated['technician_id'],
                'task_title' => $validated['task_title'],
                'task_description' => $validated['task_description'],
                'start_date' => $validated['start_date'],
                'due_date' => $validated['due_date'],
                'status' => 'pending',
            ]);

            DB::commit();

            return redirect()
                ->back()
                ->with('success', 'Task created successfully.');
        } catch (\Exception $e) {

            DB::rollBack();

            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }
    }

    public function update(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);

        $project = Project::findOrFail($task->project_id);

        if ($project->isReadOnly()) {
            return back()->with('error', 'This project is ' . $project->status . ' and its tasks can no longer be edited.');
        }

        if ($task->status == 'completed') {
            return back();
        }

        [$projectStart, $projectEnd] = $this->scheduleSpan($task->project_id);

        if (! $projectStart || ! $projectEnd) {
            return back()->with('error', 'This project has no schedule yet.');
        }

        $validated = $request->validate([
            'task_title' => 'required|string|max:255',
            'task_description' => 'required|string',
            'technician_id' => 'required|exists:tbl_technicians,technician_id',
            'start_date' => [
                'required',
                'date',
                'after_or_equal:' . $projectStart,
                'before_or_equal:' . $projectEnd,
            ],
            'due_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                'before_or_equal:' . $projectEnd,
            ],
        ]);

        if ($task->status === 'unassigned') {
            $validated['status'] = 'pending';
        }

        DB::beginTransaction();

        try {
            $task->update($validated);
            DB::commit();
            return back()->with('success', 'Task updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    public function complete($taskId)
    {
        $task = Task::findOrFail($taskId);
        $project = Project::findOrFail($task->project_id);

        if ($project->isReadOnly()) {
            return back()->with('error', 'This project is ' . $project->status . ' and its tasks can no longer be edited.');
        }

        if ($task->status === 'completed') {
            return back()->with(
                'info',
                'Task is already completed.'
            );
        }

        $task->update([
            'status' => 'completed',
        ]);

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
            return back()->with('error', 'This project is ' . $project->status . ' and its tasks can no longer be edited.');
        }

        DB::beginTransaction();

        try {

            $task->delete();

            DB::commit();

            return back()->with(
                'success',
                'Task deleted successfully.'
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with(
                'error',
                $e->getMessage()
            );
        }
    }

    /**
     * A project can have several date ranges. Tasks must be constrained to
     * the overall span of the project's current schedule (earliest start
     * to latest end) so the check always reflects the latest schedule.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function scheduleSpan(int $projectId): array
    {
        $schedules = Schedule::query()
            ->where('project_id', $projectId)
            ->get(['start_datetime', 'end_datetime']);

        if ($schedules->isEmpty()) {
            return [null, null];
        }

        $start = $schedules->min('start_datetime');
        $end = $schedules->max('end_datetime');

        return [
            $start ? $start->format('Y-m-d') : null,
            $end ? $end->format('Y-m-d') : null,
        ];
    }
}