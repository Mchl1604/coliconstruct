@props([
    'projects',
    'tasksByProject',
    'techniciansByProject' => collect(),
    'rangesByProject' => collect(),
    'activeTaskCounts' => collect(),
    // Per project: may the viewer edit and delete tasks on it?
    'manageable' => collect(),
    // The viewer's own technician record, for the "You" badge. Null for an
    // administrator, who holds no tasks of their own.
    'viewerTechnicianId' => null,
    // Route names, so each portal points the dialogs at its own endpoints.
    'updateRoute',
    'completeRoute',
    'deleteRoute',
    'updateMethod' => 'PUT',
    'completeMethod' => 'PATCH',
    'emptyMessage' => 'There are no projects to show.',
])

{{--
    The task board: one card per project, each holding its own DataTable.

    Shared by the Super Admin Tasks page and the lead technician Tasks page so
    the two read identically. The filter above it is the page's own, and works
    by hiding cards - see taskBoard.js.
--}}
<div data-task-board>
    @forelse ($projects as $project)
        @php
            $projectTasks = $tasksByProject[$project->project_id] ?? collect();
            $projectTechnicians = $techniciansByProject[$project->project_id] ?? collect();
            $projectRanges = $rangesByProject[$project->project_id] ?? collect();
            $canManage = (bool) ($manageable[$project->project_id] ?? false);
        @endphp

        <div class="card shadow-sm border-0 rounded-2 mb-3" data-project-card
            data-project-id="{{ $project->project_id }}">
            <div class="card-body p-3">

                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <div class="technician-eyebrow">{{ $project->reference_no }}</div>
                        <h5 class="fw-bold mb-1">{{ $project->name }}</h5>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <x-project-status-badge :project="$project" />
                            <span class="text-muted small">
                                {{ $projectTasks->count() }}
                                {{ \Illuminate\Support\Str::plural('task', $projectTasks->count()) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle mb-0" data-project-tasks-table>
                        <thead class="table-info">
                            <tr>
                                <th>Task Name</th>
                                <th>Assigned Technician</th>
                                <th>Start Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            {{-- No blade fallback row here: a single colspan cell has
                                 fewer cells than the header, which DataTables cannot
                                 parse. Its own emptyTable message covers it. --}}
                            @foreach ($projectTasks as $task)
                                @php
                                    $isMine = $viewerTechnicianId !== null
                                        && (int) $task->technician_id === (int) $viewerTechnicianId;
                                    $canComplete = $task->isOpen()
                                        && $task->status !== 'unassigned'
                                        && ($canManage || $isMine);
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $task->task_title }}</div>
                                        <small class="text-muted">
                                            {{ \Illuminate\Support\Str::limit($task->task_description, 60) }}
                                        </small>
                                    </td>
                                    <td>
                                        {{ $task->technician?->name ?? 'Unassigned' }}
                                        @if ($isMine)
                                            <span class="badge bg-info text-dark ms-1">You</span>
                                        @endif
                                    </td>
                                    <td data-order="{{ $task->start_date ? \Carbon\CarbonImmutable::parse($task->start_date)->timestamp : 0 }}">
                                        {{ $task->start_date ? \Carbon\CarbonImmutable::parse($task->start_date)->format('M j, Y') : '—' }}
                                    </td>
                                    <td data-order="{{ $task->due_date ? \Carbon\CarbonImmutable::parse($task->due_date)->timestamp : 0 }}">
                                        {{ $task->due_date ? \Carbon\CarbonImmutable::parse($task->due_date)->format('M j, Y') : '—' }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $task->statusBadgeClass() }}">
                                            {{ $task->statusLabel() }}
                                        </span>
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
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Per-task dialogs, shared by both portals. --}}
        @foreach ($projectTasks as $task)
            @php
                $isMine = $viewerTechnicianId !== null
                    && (int) $task->technician_id === (int) $viewerTechnicianId;
            @endphp

            <x-task-details-modal :task="$task"
                :technicians="$canManage ? $projectTechnicians : collect()"
                :active-task-counts="$activeTaskCounts" :schedule-ranges="$projectRanges"
                :update-action="$canManage ? route($updateRoute, $task->task_id) : null"
                :update-method="$updateMethod" />

            @if ($task->isOpen() && $task->status !== 'unassigned' && ($canManage || $isMine))
                <x-task-complete-modal :task="$task"
                    :action="route($completeRoute, $task->task_id)" :method="$completeMethod" />
            @endif

            @if ($canManage)
                <x-task-delete-modal :task="$task" :action="route($deleteRoute, $task->task_id)" />
            @endif
        @endforeach
    @empty
        <div class="card shadow-sm border-0 rounded-2">
            <div class="card-body">
                <div class="schedule-empty-state">{{ $emptyMessage }}</div>
            </div>
        </div>
    @endforelse

    {{-- Shown when the filter leaves nothing on screen. --}}
    <div class="card shadow-sm border-0 rounded-2 d-none" data-board-no-match>
        <div class="card-body">
            <div class="schedule-empty-state">No project matches that filter.</div>
        </div>
    </div>
</div>
