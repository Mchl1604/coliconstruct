{{-- One row of a project's task board, rendered by App\Services\TaskBoard
     for serverTable.js. $canManage and $viewerTechnicianId come with it. --}}
@php
    $isMine = $viewerTechnicianId !== null
        && (int) $task->technician_id === (int) $viewerTechnicianId;
    $canComplete = $task->isOpen()
        && $task->status !== 'unassigned'
        && ($canManage || $isMine);
    // Work that has not been due to start yet
    // cannot be ticked off - see TaskPolicy,
    // which refuses the request as well. The
    // crew's rule, not the office's: this board
    // is shared, and $viewerTechnicianId is
    // null for an administrator, who may still
    // close a task on the crew's behalf.
    $startsLater = $canComplete
        && $viewerTechnicianId !== null
        && $task->startsInFuture();
    $canComplete = $canComplete && ! $startsLater;
    // What, if anything, is stopping this task
    // proceeding. One rule decides it - see
    // Task::assignmentGap() - and the attention
    // chips above the board filter on this
    // attribute.
    $gap = $task->assignmentGap();
@endphp
<tr @if ($gap) data-task-gap="{{ $gap }}" @endif>
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
        {{-- The assignee's own picture beside
             their name; an unassigned task has
             nobody to show. --}}
        <div class="d-flex align-items-center gap-2">
            @if ($task->technician?->account)
                <x-technician-link :technician="$task->technician">
                    <x-user-avatar :user="$task->technician->account" size="xs" />
                </x-technician-link>
            @endif

            <span>
                {{-- A link for an administrator,
                     plain text in the portal. --}}
                <x-technician-link :technician="$task->technician" />
                @if ($isMine)
                    <span class="badge bg-info text-dark ms-1">You</span>
                @endif
                <x-task-holder-flag :task="$task" />
            </span>
        </div>
    </td>
    <td>
        {{ $task->start_date ? \Carbon\CarbonImmutable::parse($task->start_date)->format(\App\Support\BusinessTime::DATE) : '—' }}
    </td>
    <td>
        {{ $task->due_date ? \Carbon\CarbonImmutable::parse($task->due_date)->format(\App\Support\BusinessTime::DATE) : '—' }}
    </td>
    <td>
        <span class="badge {{ $task->statusBadgeClass() }}">
            {{ $task->statusLabel() }}
        </span>

        {{-- What is actually missing, said in
             as many words. The status badge
             alone cannot: a task with an owner
             and no due date reads "Pending",
             and one with dates and no owner
             reads "Unassigned" whether or not
             the dates are the problem. --}}
        @if ($gap)
            <span class="badge task-gap-badge gap-{{ $gap }} mt-1 d-block">
                <i class="bi bi-exclamation-triangle-fill me-1"
                    aria-hidden="true"></i>
                {{ $task->assignmentGapLabel() }}
            </span>
        @endif
    </td>
    <td class="text-center">
        <div class="d-flex justify-content-center gap-2">
            {{-- A completed task keeps the eye but opens
                 view only, showing what was submitted on
                 completion. --}}
            <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                data-bs-toggle="modal"
                data-bs-target="#taskModal{{ $task->task_id }}"
                title="{{ $task->isCompleted() || ! $canManage ? 'View task' : 'View / edit task' }}">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>

            @if ($canComplete)
                <button type="button" class="btn btn-sm btn-success py-1 px-2"
                    data-bs-toggle="modal"
                    data-bs-target="#completeTaskModal{{ $task->task_id }}"
                    title="{{ $isMine ? 'Mark as completed' : 'Mark as completed on their behalf' }}">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                </button>
            @elseif ($startsLater)
                {{-- Left in place, disabled, saying why. It
                     becomes live on its own the day the task
                     starts, with nobody to change anything. --}}
                <button type="button" class="btn btn-sm btn-success py-1 px-2" disabled
                    title="{{ \App\Models\Task::NOT_STARTED_REFUSAL }}">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                </button>
            @endif

            @if ($canManage)
                <button type="button" class="btn btn-sm btn-danger py-1 px-2"
                    data-bs-toggle="modal"
                    data-bs-target="#deleteTaskModal{{ $task->task_id }}"
                    title="Delete task">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
            @endif
        </div>
    </td>
</tr>
