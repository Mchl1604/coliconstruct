{{-- One row of the Tasks tab on a project, rendered by
     TechnicianPortalController::projectTasksTable() for serverTable.js.
     $canManageTasks and $technicianId come with it. --}}
<tr data-status="{{ $task->derivedStatus() }}">
    <td>
        <div class="fw-semibold">{{ $task->task_title }}</div>
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
        {{-- The person's own picture beside their
             name, as on the administrative copy of
             this table. The "You" badge stays: this
             is the one table a technician reads
             looking for their own work. --}}
        <div class="d-flex align-items-center gap-2">
            <x-user-avatar :user="$task->technician?->account"
                size="sm"
                :alt="$task->technician?->name ?? 'Unassigned'" />
            <span>
                {{ $task->technician?->name ?? 'Unassigned' }}
                <x-task-holder-flag :task="$task" />
            </span>
            @if ($task->technician_id === $technicianId)
                <span class="badge bg-info text-dark">You</span>
            @endif
        </div>
    </td>

    <td>
        {{ $task->start_date ? \Carbon\Carbon::parse($task->start_date)->format(\App\Support\BusinessTime::DATE) : 'Unassigned' }}
    </td>

    <td>
        {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format(\App\Support\BusinessTime::DATE) : 'Unassigned' }}
    </td>

    <td>
        <span class="badge {{ $task->statusBadgeClass() }}">
            {{ $task->statusLabel() }}
        </span>
    </td>

    <td class="text-center">
        <div class="d-flex justify-content-center gap-2">
            <button type="button" class="btn btn-sm btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#taskModal{{ $task->task_id }}"
                title="{{ $task->isCompleted() || ! $canManageTasks ? 'View task' : 'View / edit task' }}">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>

            {{-- A lead may close anything on a project
                 they run, not only their own work. --}}
            @can('complete', $task)
                <button type="button" class="btn btn-sm btn-success"
                    data-bs-toggle="modal"
                    data-bs-target="#completeTaskModal{{ $task->task_id }}"
                    title="{{ $task->technician_id === $technicianId ? 'Mark as completed' : 'Mark as completed on their behalf' }}">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                </button>
            @elsecan('blockedByStartDate', $task)
                {{-- Work that has not been due to start
                     yet. The button stays, disabled and
                     saying why, rather than vanishing and
                     leaving the reader to guess - and it
                     becomes live on its own the day the
                     task begins. The backend refuses it
                     either way; see TaskPolicy. --}}
                <button type="button" class="btn btn-sm btn-success" disabled
                    title="{{ \App\Models\Task::NOT_STARTED_REFUSAL }}">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                </button>
            @endcan

            @can('delete', $task)
                <button type="button" class="btn btn-sm btn-danger"
                    data-bs-toggle="modal"
                    data-bs-target="#deleteTaskModal{{ $task->task_id }}"
                    title="Delete task">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
            @endcan
        </div>
    </td>
</tr>
