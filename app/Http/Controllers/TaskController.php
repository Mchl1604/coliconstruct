<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function store(Request $request, $projectId)
    {
        // Get the project schedule
        $query = Schedule::query();

        $schedule = $query
            ->where('project_id', $projectId)
            ->firstOrFail();

        $projectStart = $schedule->start_datetime->format('Y-m-d');
        $projectEnd = $schedule->end_datetime->format('Y-m-d');

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

        if ($task->status == 'completed') {
            return back();
        }

        $query = Schedule::query();

        $schedule = $query
            ->where('project_id', $task->project_id)
            ->firstOrFail();

        $projectStart = $schedule->start_datetime->format('Y-m-d');
        $projectEnd = $schedule->end_datetime->format('Y-m-d');

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

            $task->update($validated);

            DB::commit();

            return back()->with(
                'success',
                'Task updated successfully.'
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with(
                'error',
                $e->getMessage()
            );
        }
    }
    public function complete($taskId)
    {
        $task = Task::findOrFail($taskId);

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
        DB::beginTransaction();

        try {

            $task = Task::findOrFail($taskId);

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
}
