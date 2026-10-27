{{-- The dialogs behind the rows on one page of a project's task board -
     view and edit, complete, delete - rendered by App\Services\TaskBoard
     beside the rows, and swapped in whole with every page. Shared by both
     portals. --}}
@foreach ($tasks as $task)
    @php
        $isMine = $viewerTechnicianId !== null
            && (int) $task->technician_id === (int) $viewerTechnicianId;
    @endphp

    <x-task-details-modal :task="$task"
        :technicians="$canManage ? $technicians : collect()"
        :active-task-counts="$activeTaskCounts" :schedule-ranges="$ranges" :periods="$periods"
        :phases="$phases"
        :update-action="$canManage ? route($updateRoute, $task->task_id) : null"
        :update-method="$updateMethod" />

    @if ($task->isOpen()
        && $task->status !== 'unassigned'
        && ($canManage || $isMine)
        && ! $task->waitsForStartDate(auth()->user()))
        <x-task-complete-modal :task="$task"
            :action="route($completeRoute, $task->task_id)" :method="$completeMethod" />
    @endif

    @if ($canManage)
        <x-task-delete-modal :task="$task" :action="route($deleteRoute, $task->task_id)" />
    @endif
@endforeach
