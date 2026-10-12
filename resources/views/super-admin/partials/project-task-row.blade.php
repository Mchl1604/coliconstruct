{{-- One row of the Tasks tab on Project Details, rendered by
     ProjectController::tasksTable() for serverTable.js. $isReadOnly and
     $canTakeWork come with it. --}}
<tr data-status="{{ $task->derivedStatus() }}">

    <td>
        <div class="fw-semibold">
            {{ $task->task_title }}
        </div>

        <small class="text-muted">
            {{ \Illuminate\Support\Str::limit($task->task_description, 60) }}
        </small>
    </td>

    {{-- Which stage of the project this task
         belongs to. The column sorts by the
         phase's sequence on the server, so by
         phase number rather than alphabetically
         by phase title. --}}
    <td>
        <x-task-phase-cell :task="$task" />
    </td>

    <td>
        {{-- The person's own picture beside
             their name, the way the Assign To
             cards and the team panel show them.
             An unassigned task falls back to the
             default avatar rather than leaving a
             hole in the column. --}}
        <div class="d-flex align-items-center gap-2">
            <x-user-avatar :user="$task->technician?->account"
                size="sm"
                :alt="$task->technician?->name ?? 'Unassigned'" />
            <span>
                <x-technician-link :technician="$task->technician" />
                <x-task-holder-flag :task="$task" />
            </span>
        </div>
    </td>

    <td>
        {{ $task->start_date ? \Carbon\Carbon::parse($task->start_date)->format(\App\Support\BusinessTime::DATE) : 'Unassigned' }}
    </td>

    <td>
        {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format(\App\Support\BusinessTime::DATE) : 'Unassigned' }}
    </td>

    <td>
        {{-- Asked of the task rather than
             switched on its status column.
             This table used to read the column
             directly, which is how a task whose
             due date went by last month still
             said "Pending" here while the Tasks
             board called the same task Overdue.
             See TaskStatus. --}}
        <span class="badge {{ $task->statusBadgeClass() }}">
            {{ $task->statusLabel() }}
        </span>
    </td>

    <td class="text-start">

        <div class="d-flex justify-content-start gap-2">

            {{-- View / Edit. A completed task keeps the eye
                 but opens view only, showing what was
                 submitted on completion. --}}
            <button type="button" class="btn btn-sm btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#taskModal{{ $task->task_id }}"
                title="{{ $task->isCompleted() || $isReadOnly ? 'View task' : 'View / edit task' }}">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>

            {{-- A locked project's task history is a record,
                 so completing and deleting both drop away - and
                 so does a paused project's, until it is
                 resumed. --}}
            @if ($canTakeWork)
                @if ($task->status != 'completed' && $task->status != 'unassigned')
                    <button type="button" class="btn btn-sm btn-success"
                        data-bs-toggle="modal"
                        data-bs-target="#completeTaskModal{{ $task->task_id }}"
                        title="Mark as completed">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                    </button>
                @endif

                <button type="button" class="btn btn-sm btn-danger"
                    data-bs-toggle="modal"
                    data-bs-target="#deleteTaskModal{{ $task->task_id }}"
                    title="Delete task">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
            @endif

        </div>

    </td>

</tr>
