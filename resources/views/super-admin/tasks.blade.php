@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <style>
        /* Scoped to the Task page only — does not touch projectDetails.css */
        .task-assign-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .task-assign-card {
            cursor: pointer;
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            text-align: center;
            min-width: 160px;
            transition: .15s ease;
        }

        .task-assign-card:hover {
            background: #f4f8ff;
        }

        .btn-check:checked + .task-assign-card {
            background: #e9f2ff;
            border-color: #0d6efd;
        }

        .task-assign-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #0d6efd;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 0.75rem;
        }

        .task-assign-name {
            font-weight: 700;
            margin-bottom: 0.15rem;
        }

        .task-assign-count {
            color: #6c757d;
            font-size: 0.9rem;
        }
    </style>
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Task</h4>
            <p class="text-secondary small mb-0">All tasks across every project.</p>
        </div>

        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="bi bi-plus-lg me-1"></i>
            Add New Task
        </button>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">

            <div class="d-flex align-items-center gap-2 mb-3 px-1" style="max-width: 360px;">
                <label for="taskProjectFilter" class="fw-semibold small text-nowrap mb-0">Project:</label>
                <select id="taskProjectFilter" class="form-select form-select-sm">
                    <option value="all">All Projects</option>
                    @foreach ($filterProjects as $project)
                        <option value="{{ $project->project_id }}">
                            {{ $project->reference_no }} &mdash; {{ $project->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="table-responsive">
                <table id="tasksTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Project</th>
                            <th>Task</th>
                            <th>Assigned To</th>
                            <th>Start Date</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        {{-- No blade fallback row here: a single colspan cell has
                             fewer cells than the header, which DataTables cannot
                             parse ("Requested unknown parameter"). Its own
                             emptyTable message covers it (see the init below). --}}
                        @foreach ($tasks as $task)
                            <tr data-project-id="{{ $task->project_id }}" data-status="{{ $task->status }}">
                                <td>
                                    @if ($task->project)
                                        <a href="{{ route('super-admin.projects.show', $task->project_id) }}">
                                            {{ $task->project->reference_no }}
                                        </a>
                                        <div class="small text-muted">{{ $task->project->name }}</div>
                                        <x-project-status-badge :project="$task->project" class="mt-1" />
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $task->task_title }}</div>
                                    <small class="text-muted">
                                        {{ \Illuminate\Support\Str::limit($task->task_description, 60) }}
                                    </small>
                                </td>
                                <td>{{ optional($task->technician)->name ?? 'Unassigned' }}</td>
                                <td>
                                    {{ $task->start_date ? \Carbon\Carbon::parse($task->start_date)->format('M d, Y') : 'Unassigned' }}
                                </td>
                                <td>
                                    {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('M d, Y') : 'Unassigned' }}
                                </td>
                                <td>
                                    @switch($task->status)
                                        @case('unassigned')
                                            <span class="badge bg-warning text-dark">Unassigned</span>
                                        @break

                                        @case('pending')
                                            <span class="badge bg-secondary">Pending</span>
                                        @break

                                        @case('ongoing')
                                            <span class="badge bg-primary">Ongoing</span>
                                        @break

                                        @case('completed')
                                            <span class="badge bg-success">Completed</span>
                                        @break

                                        @case('cancelled')
                                            <span class="badge bg-danger">Cancelled</span>
                                        @break
                                    @endswitch
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">

                                        {{-- View / Edit --}}
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                            data-bs-target="#taskModal{{ $task->task_id }}">
                                            <i class="bi bi-eye"></i>
                                        </button>

                                        {{-- Complete --}}
                                        @if ($task->status != 'completed' && $task->status != 'unassigned')
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal"
                                                data-bs-target="#completeTaskModal{{ $task->task_id }}">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        @endif

                                        {{-- Delete --}}
                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal"
                                            data-bs-target="#deleteTaskModal{{ $task->task_id }}">
                                            <i class="bi bi-trash"></i>
                                        </button>

                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- ADD TASK MODAL: pick a project first, then the rest of the form appears -->
    <div class="modal fade" id="addTaskModal" tabindex="-1" aria-labelledby="addTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <form id="addTaskForm" method="POST" action="">
                @csrf

                <div class="modal-content">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addTaskModalLabel">
                            <i class="bi bi-list-task me-2"></i>
                            Create Task
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <!-- Step 1: choose the project -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Project</label>
                            <select id="taskProjectSelect" class="form-select" required>
                                <option value="" selected disabled>Select a project&hellip;</option>
                                @foreach ($schedulableProjects as $project)
                                    <option value="{{ $project->project_id }}">
                                        {{ $project->reference_no }} &mdash; {{ $project->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Choose the project first &mdash; the technician list below depends on who's
                                assigned to it.
                            </div>
                            <div id="taskProjectError" class="text-danger small mt-1 d-none"></div>
                        </div>

                        <div id="addTaskFieldsWrapper" class="d-none">

                            <hr>

                            <!-- Task Title -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Task Title</label>
                                <input type="text" class="form-control" name="task_title"
                                    placeholder="Enter task title" required>
                            </div>

                            <!-- Description -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Task Description</label>
                                <textarea class="form-control" name="task_description" rows="4"
                                    placeholder="Describe the task..." required></textarea>
                            </div>

                            <!-- Dates -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Start Date</label>
                                    <input type="text" id="modalTaskStartDate" name="start_date" class="form-control"
                                        placeholder="Select start date" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Due Date</label>
                                    <input type="text" id="modalTaskDueDate" name="due_date" class="form-control"
                                        placeholder="Select due date" required>
                                </div>

                                <div class="col-12 mb-3">
                                    <div class="form-text" id="taskScheduleRangesHint"></div>
                                </div>
                            </div>

                            <hr>

                            <label class="form-label fw-bold mb-3">Assign To</label>

                            <div id="taskTechnicianOptions" class="task-assign-row">
                                <!-- populated by JS based on the selected project -->
                            </div>

                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="addTaskSubmitBtn" class="btn btn-primary" disabled>
                            <i class="bi bi-check-lg me-1"></i>
                            Create Task
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <!-- PER-TASK VIEW / EDIT, COMPLETE, AND DELETE MODALS -->
    @foreach ($tasks as $task)
        @php
            $taskProject = $task->project;
            $taskScheduleStart = $taskProject ? $taskProject->schedules->min('start_datetime') : null;
            $taskScheduleEnd = $taskProject ? $taskProject->schedules->max('end_datetime') : null;
            // Every range individually - min/max alone would let a task be
            // placed in the gap between two of them.
            $taskScheduleRanges = $taskProject
                ? $taskProject->schedules
                    ->map(fn($schedule) => [
                        'start' => \Carbon\Carbon::parse($schedule->start_datetime)->format('Y-m-d'),
                        'end' => \Carbon\Carbon::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('Y-m-d'),
                    ])
                    ->values()
                : collect();
        @endphp

        <div class="modal fade" id="taskModal{{ $task->task_id }}" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">

                    <div
                        class="modal-header
                    {{ $task->status == 'completed' ? 'bg-success' : 'bg-primary' }}
                    text-white">

                        <h5 class="modal-title">
                            <i class="bi bi-list-task me-2"></i>
                            {{ $task->status == 'completed' ? 'View Task' : 'Edit Task' }}
                        </h5>

                        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

                    </div>

                    <div class="modal-body">
                        @if ($task->status != 'completed')
                            <form action="{{ route('super-admin.tasks.update', $task->task_id) }}" method="POST">
                            @else
                                <form action="javascript:void(0);">
                        @endif

                        @csrf
                        @method('PUT')

                        {{-- Task Title --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Task Title</label>
                            <input type="text" class="form-control" name="task_title"
                                value="{{ $task->task_title }}" {{ $task->status == 'completed' ? 'readonly' : '' }}>
                        </div>

                        {{-- Description --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-control" rows="4" name="task_description"
                                {{ $task->status == 'completed' ? 'readonly' : '' }}>{{ $task->task_description }}</textarea>
                        </div>

                        <div class="row" data-task-date-row
                            data-schedule-ranges='@json($taskScheduleRanges)'>
                            {{-- Start Date --}}
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input type="text" class="form-control" name="start_date"
                                    value="{{ $task->start_date }}" data-task-start
                                    placeholder="Select start date"
                                    {{ $task->status == 'completed' ? 'readonly' : '' }}>
                            </div>

                            {{-- Due Date --}}
                            <div class="col-md-6">
                                <label class="form-label">Due Date</label>
                                <input type="text" class="form-control" name="due_date"
                                    value="{{ $task->due_date }}" data-task-due
                                    placeholder="Select due date"
                                    {{ $task->status == 'completed' ? 'readonly' : '' }}>
                            </div>
                        </div>

                        <div class="form-text">
                            Allowed:
                            {{ $taskScheduleRanges->map(fn($range) => \Carbon\Carbon::parse($range['start'])->format('M j, Y') . ' – ' . \Carbon\Carbon::parse($range['end'])->format('M j, Y'))->join('; ') ?: 'No schedule set' }}
                        </div>

                        <hr>

                        <label class="form-label fw-bold">Assign Technician</label>

                        <div class="task-assign-row">
                            @if ($taskProject)
                                @foreach ($taskProject->projectTechnicians as $projectTechnician)
                                    @php
                                        $technician = $projectTechnician->technician;
                                    @endphp

                                    @if ($technician)
                                        <label>
                                            <input type="radio" class="btn-check" name="technician_id"
                                                value="{{ $technician->technician_id }}"
                                                {{ $task->technician_id == $technician->technician_id ? 'checked' : '' }}
                                                {{ $task->status == 'completed' ? 'disabled' : '' }}>

                                            <div class="task-assign-card">
                                                <div class="task-assign-avatar">
                                                    <i class="bi bi-person-fill"></i>
                                                </div>
                                                <div class="task-assign-name">{{ $technician->name }}</div>
                                                <div class="task-assign-count">
                                                    {{ $technicianActiveTaskCounts[$technician->technician_id] ?? 0 }}
                                                    Active Task{{ ($technicianActiveTaskCounts[$technician->technician_id] ?? 0) == 1 ? '' : 's' }}
                                                </div>
                                            </div>
                                        </label>
                                    @endif
                                @endforeach
                            @endif
                        </div>

                        @if ($task->status == 'completed')
                            <hr>

                            <h6 class="fw-bold mb-3">
                                <i class="bi bi-images me-2"></i>
                                Completion Images
                            </h6>

                            @if ($task->images->count())
                                <div class="row g-3">
                                    @foreach ($task->images as $image)
                                        <div class="col-lg-4 col-md-6">
                                            <a href="{{ asset('storage/' . $image->image_path) }}" target="_blank">
                                                <img src="{{ asset('storage/' . $image->image_path) }}"
                                                    class="img-fluid rounded border shadow-sm"
                                                    style="height:200px;width:100%;object-fit:cover;">
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="alert alert-warning mb-0">
                                    <i class="bi bi-exclamation-circle me-2"></i>
                                    <strong>No image available.</strong>
                                    This task was marked as completed without any uploaded completion images.
                                </div>
                            @endif
                        @endif

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>

                        @if ($task->status != 'completed')
                            <button class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>
                                Save Changes
                            </button>
                        @endif
                    </div>

                    </form>

                </div>
            </div>
        </div>

        <div class="modal fade" id="completeTaskModal{{ $task->task_id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-check-circle me-2"></i>
                            Complete Task
                        </h5>
                        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <form action="{{ route('super-admin.tasks.complete', $task->task_id) }}" method="POST">
                            @csrf
                            @method('PATCH')

                            <p class="mb-1">Are you sure you want to mark</p>
                            <h5 class="fw-bold">"{{ $task->task_title }}"</h5>
                            <p class="text-muted mb-0">as completed?</p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg me-1"></i>
                            Mark as Completed
                        </button>
                    </div>

                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteTaskModal{{ $task->task_id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-trash me-2"></i>
                            Delete Task
                        </h5>
                        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <form action="{{ route('super-admin.tasks.destroy', $task->task_id) }}" method="POST">
                            @csrf
                            @method('DELETE')

                            <p class="mb-1">This will permanently delete</p>
                            <h5 class="fw-bold">"{{ $task->task_title }}"</h5>
                            <p class="text-danger mb-0">This action cannot be undone.</p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>
                            Delete Task
                        </button>
                    </div>

                    </form>
                </div>
            </div>
        </div>
    @endforeach

    @push('scripts')
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script>
            // A project's schedule can have gaps (July 10-15 and July 20-25).
            // Tasks must sit inside ONE range, so the pickers enable only the
            // days those ranges actually cover and the due date is pinned to
            // the same range as the start date. The server re-checks this.
            function describeRanges(ranges) {
                return (ranges || []).map(function(range) {
                    const format = { month: 'short', day: 'numeric', year: 'numeric' };
                    return new Date(range.start + 'T00:00:00').toLocaleDateString(undefined, format) +
                        ' – ' +
                        new Date(range.end + 'T00:00:00').toLocaleDateString(undefined, format);
                }).join('; ');
            }

            function enableSpecs(ranges) {
                return (ranges || []).map(function(range) {
                    return { from: range.start, to: range.end };
                });
            }

            function rangeContaining(ranges, dateString) {
                return (ranges || []).find(function(range) {
                    return dateString >= range.start && dateString <= range.end;
                }) || null;
            }

            function applyScheduleRanges(startInput, dueInput, ranges) {
                if (!window.flatpickr || !startInput || !dueInput) {
                    return;
                }

                [startInput, dueInput].forEach(function(input) {
                    if (input._flatpickr) {
                        input._flatpickr.destroy();
                    }
                });

                if (!ranges || !ranges.length) {
                    return;
                }

                const duePicker = window.flatpickr(dueInput, {
                    dateFormat: 'Y-m-d',
                    allowInput: false,
                    enable: enableSpecs(ranges),
                });

                window.flatpickr(startInput, {
                    dateFormat: 'Y-m-d',
                    allowInput: false,
                    enable: enableSpecs(ranges),
                    onChange: function(selectedDates, dateStr) {
                        const active = rangeContaining(ranges, dateStr);

                        if (!active) {
                            return;
                        }

                        // Confine the due date to the same range, so a task
                        // can never straddle a gap.
                        duePicker.set('enable', [{ from: dateStr, to: active.end }]);

                        if (dueInput.value && (dueInput.value < dateStr || dueInput.value > active.end)) {
                            duePicker.clear(false);
                        }
                    },
                });

                // Re-applying an existing start value narrows the due picker
                // straight away when editing a saved task.
                if (startInput.value) {
                    const active = rangeContaining(ranges, startInput.value);

                    if (active) {
                        duePicker.set('enable', [{ from: startInput.value, to: active.end }]);
                    }
                }
            }

            // Per-task edit modals carry their project's ranges inline.
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('[data-task-date-row]').forEach(function(row) {
                    const startInput = row.querySelector('[data-task-start]');
                    const dueInput = row.querySelector('[data-task-due]');

                    if (!startInput || !dueInput || startInput.hasAttribute('readonly')) {
                        return;
                    }

                    let ranges = [];

                    try {
                        ranges = JSON.parse(row.dataset.scheduleRanges || '[]');
                    } catch (error) {
                        ranges = [];
                    }

                    applyScheduleRanges(startInput, dueInput, ranges);
                });
            });
        </script>
        <script>
            $(function() {
                const table = $('#tasksTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [10, 25, 50, 100],
                    info: false,
                    language: {
                        search: "",
                        searchPlaceholder: "Search tasks...",
                        emptyTable: "No tasks found.",
                        zeroRecords: "No tasks match your filters."
                    }
                });

                $('#taskProjectFilter').on('change', function() {
                    const projectId = $(this).val();

                    $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
                        return !fn._taskProjectFilter;
                    });

                    const filterFn = function(settings, data, dataIndex) {
                        if (settings.nTable.id !== 'tasksTable') {
                            return true;
                        }

                        if (projectId === 'all') {
                            return true;
                        }

                        const rowNode = table.row(dataIndex).node();
                        return rowNode && rowNode.getAttribute('data-project-id') === projectId;
                    };

                    filterFn._taskProjectFilter = true;
                    $.fn.dataTable.ext.search.push(filterFn);
                    table.draw();
                });

                const projectSelect = document.getElementById('taskProjectSelect');
                const fieldsWrapper = document.getElementById('addTaskFieldsWrapper');
                const submitBtn = document.getElementById('addTaskSubmitBtn');
                const errorBox = document.getElementById('taskProjectError');
                const technicianOptions = document.getElementById('taskTechnicianOptions');
                const startInput = document.getElementById('modalTaskStartDate');
                const dueInput = document.getElementById('modalTaskDueDate');
                const form = document.getElementById('addTaskForm');
                const addTaskModalEl = document.getElementById('addTaskModal');

                function resetTaskForm() {
                    form.reset();
                    fieldsWrapper.classList.add('d-none');
                    submitBtn.disabled = true;
                    errorBox.classList.add('d-none');
                    errorBox.textContent = '';
                    technicianOptions.innerHTML = '';
                    form.action = '';
                }

                addTaskModalEl.addEventListener('hidden.bs.modal', resetTaskForm);

                projectSelect.addEventListener('change', function() {
                    const projectId = projectSelect.value;

                    fieldsWrapper.classList.add('d-none');
                    submitBtn.disabled = true;
                    errorBox.classList.add('d-none');
                    errorBox.textContent = '';
                    technicianOptions.innerHTML = '';

                    if (!projectId) {
                        return;
                    }

                    form.action = `/super-admin/projects/${projectId}/task`;

                    fetch(`/super-admin/projects/${projectId}/task-form-data`, {
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                        .then(async (response) => {
                            const payload = await response.json();

                            if (!response.ok) {
                                throw new Error(payload.error || 'Unable to load project details.');
                            }

                            return payload;
                        })
                        .then((data) => {
                            applyScheduleRanges(startInput, dueInput, data.ranges || []);

                            const hint = document.getElementById('taskScheduleRangesHint');

                            if (hint) {
                                hint.textContent = (data.ranges || []).length
                                    ? 'Allowed: ' + describeRanges(data.ranges) +
                                    ((data.ranges.length > 1) ?
                                        '. A task cannot span the gap between two ranges.' : '.')
                                    : '';
                            }

                            if (!data.technicians.length) {
                                errorBox.textContent =
                                    'This project has no assigned technicians yet.';
                                errorBox.classList.remove('d-none');
                                return;
                            }

                            data.technicians.forEach((technician) => {
                                const label = document.createElement('label');

                                label.innerHTML = `
                                    <input type="radio" class="btn-check" name="technician_id"
                                        value="${technician.technician_id}" required>
                                    <div class="task-assign-card">
                                        <div class="task-assign-avatar">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                        <div class="task-assign-name">${technician.name}</div>
                                        <div class="task-assign-count">${technician.active_task_count} Active Task${technician.active_task_count === 1 ? '' : 's'}</div>
                                    </div>
                                `;

                                technicianOptions.appendChild(label);
                            });

                            fieldsWrapper.classList.remove('d-none');
                            submitBtn.disabled = false;
                        })
                        .catch((err) => {
                            errorBox.textContent = err.message;
                            errorBox.classList.remove('d-none');
                        });
                });
            });
        </script>
    @endpush
@endsection