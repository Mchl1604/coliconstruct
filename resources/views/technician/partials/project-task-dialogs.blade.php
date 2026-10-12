{{-- The dialogs behind the rows on one page of a project's Tasks tab, rendered
     by TechnicianPortalController::projectTasksTable() beside the rows. --}}
@foreach ($tasks as $task)
    {{-- The same dialogs the Super Admin portal opens. --}}
    <x-task-details-modal :task="$task"
        :technicians="$canManageTasks ? $technicians : collect()" :periods="$technicianPeriods"
        :active-task-counts="$technicianActiveTaskCounts" :schedule-ranges="$scheduleRanges"
        :phases="$selectablePhases"
        :update-action="$canManageTasks ? route('technician.tasks.update', $task->task_id) : null"
        update-method="POST" />

    @can('complete', $task)
        <x-task-complete-modal :task="$task"
            :action="route('technician.tasks.complete', $task->task_id)" method="POST" />
    @endcan

    @can('delete', $task)
        <x-task-delete-modal :task="$task"
            :action="route('technician.tasks.destroy', $task->task_id)" />
    @endcan
@endforeach
