{{-- The dialogs behind the rows on one page of the Tasks tab on Project
     Details - view and edit, complete, delete - rendered by
     ProjectController::tasksTable() beside the rows. Shared components with
     the Tasks page and the technician portal. --}}
@foreach ($tasks as $task)
    {{-- Shared with the Tasks page and the technician portal. --}}
    <x-task-details-modal :task="$task" :technicians="$taskTechnicians" :periods="$technicianPeriods"
        :active-task-counts="$technicianActiveTaskCounts" :schedule-ranges="$scheduleRanges"
        :phases="$selectablePhases"
        :update-action="$canTakeWork ? route('super-admin.tasks.update', $task->task_id) : null" />

    @if ($canTakeWork)
        @if ($task->status != 'completed' && $task->status != 'unassigned')
            <x-task-complete-modal :task="$task"
                :action="route('super-admin.tasks.complete', $task->task_id)" />
        @endif

        <x-task-delete-modal :task="$task"
            :action="route('super-admin.tasks.destroy', $task->task_id)" />
    @endif
@endforeach
