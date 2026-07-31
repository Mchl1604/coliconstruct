<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TaskController extends Controller
{
    /**
     * Tasks page: shows tasks across all projects with a project filter
     * dropdown, and an Add Task modal that lets you pick the project first.
     */
    public function index(Request $request)
    {
        // Only live work belongs on this page: a task is listed when its
        // project is pending or ongoing. Not-yet-scheduled, on-hold,
        // completed, cancelled and archived projects are all left out.
        $tasks = Task::with([
            'project.projectTechnicians.technician.account',
            'project.schedules',
            'technician',
            'images',
        ])
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', Project::ACTIVE_PROJECT_STATUSES)
                    ->where('is_archived', false);
            })
            ->orderBy('project_id')
            ->orderBy('start_date')
            ->get();

        $projects = Project::query()
            ->orderBy('name')
            ->get(['project_id', 'name', 'reference_no', 'status', 'on_hold', 'is_archived']);

        // Only projects that can actually receive new tasks are selectable in
        // the Add Task modal: completed, cancelled and archived are excluded.
        $schedulableProjects = $projects->filter(function (Project $project) {
            return ! $project->isReadOnly() && ! $project->isArchived();
        })->values();

        // The filter offers every pending/ongoing project - the same set the
        // table can show - whether or not it currently has any tasks. Picking
        // one with no tasks is a valid, informative result.
        $filterProjects = $projects
            ->filter(function (Project $project): bool {
                return in_array($project->status, Project::ACTIVE_PROJECT_STATUSES, true)
                    && ! $project->isArchived();
            })
            ->values();

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
            'filterProjects',
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
                ->with('error', 'This project is ' . $project->status . ' and no longer accepts new tasks.');
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
            'technician_id' => 'required|exists:tbl_technicians,technician_id',
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $this->attachRangeRule($validator, $ranges);

        $validated = $validator->validate();

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

        $ranges = $this->scheduleRanges($task->project_id);

        if ($ranges === []) {
            return back()->with('error', 'This project has no schedule yet.');
        }

        $validator = Validator::make($request->all(), [
            'task_title' => 'required|string|max:255',
            'task_description' => 'required|string',
            'technician_id' => 'required|exists:tbl_technicians,technician_id',
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $this->attachRangeRule($validator, $ranges);

        $validated = $validator->validate();

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
     * Every date range on a project's schedule, as 'Y-m-d' pairs.
     *
     * A project can have several ranges with gaps between them (July 10-15
     * and July 20-25, say). Checking only the earliest start and the latest
     * end would wrongly accept July 16-19, so callers must test the ranges
     * individually.
     *
     * @return array<int, array{start: string, end: string}>
     */
    private function scheduleRanges(int $projectId): array
    {
        return Schedule::query()
            ->where('project_id', $projectId)
            ->orderBy('start_datetime')
            ->get(['start_datetime', 'end_datetime'])
            ->map(fn (Schedule $schedule): array => [
                'start' => Carbon::parse($schedule->start_datetime)->format('Y-m-d'),
                'end' => Carbon::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('Y-m-d'),
            ])
            ->values()
            ->all();
    }

    /**
     * A task must sit entirely inside ONE of the project's date ranges - it
     * cannot straddle the gap between two of them. This mirrors the rule
     * ScheduleController uses when it re-checks tasks after a reschedule.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    private function rangeCovering(array $ranges, ?string $startDate, ?string $dueDate): ?array
    {
        if (! $startDate || ! $dueDate) {
            return null;
        }

        foreach ($ranges as $range) {
            if ($startDate >= $range['start'] && $dueDate <= $range['end']) {
                return $range;
            }
        }

        return null;
    }

    /**
     * Human-readable list of the allowed windows, for validation messages.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    private function describeRanges(array $ranges): string
    {
        return collect($ranges)
            ->map(fn (array $range): string => Carbon::parse($range['start'])->format('M j, Y')
                . ' - '
                . Carbon::parse($range['end'])->format('M j, Y'))
            ->join('; ');
    }

    /**
     * Add the "inside a single schedule range" check to a validator.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    private function attachRangeRule(\Illuminate\Validation\Validator $validator, array $ranges): void
    {
        $validator->after(function ($validator) use ($ranges): void {
            if ($validator->errors()->hasAny(['start_date', 'due_date'])) {
                return;
            }

            $data = $validator->getData();
            $covering = $this->rangeCovering(
                $ranges,
                $data['start_date'] ?? null,
                $data['due_date'] ?? null
            );

            if ($covering) {
                return;
            }

            $message = count($ranges) === 1
                ? 'The task dates must fall inside the project schedule (' . $this->describeRanges($ranges) . ').'
                : 'The task dates must fall inside a single one of the project\'s schedule ranges ('
                    . $this->describeRanges($ranges) . '). A task cannot span the gap between two ranges.';

            $validator->errors()->add('start_date', $message);
            $validator->errors()->add('due_date', $message);
        });
    }
}