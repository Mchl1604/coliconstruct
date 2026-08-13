@extends('layouts.portalNav')

@section('title', 'Project Details')

@push('styles')
    <link rel="stylesheet" href="/css/super-admin/projectDetails.css">
    <link rel="stylesheet" href="/css/super-admin/projects.css">
    <link rel="stylesheet" href="/css/taskModal.css">
@endpush

@section('content')
    @php
        $client = $project->clients->first();
        $documentsByType = $project->documents->keyBy('document_type');
        $assessmentDocument = $documentsByType->get('assessment');
        $contractDocument = $documentsByType->get('contract');

        $clientTypeClass = match (strtolower($client?->client_type ?? '')) {
            'residential' => 'bi bi-house-door',
            'commercial' => 'bi bi-building',
            default => 'bi bi-person',
        };

        // Closing a project is a lead's call. A technician reads this page and
        // completes their own tasks on it, nothing more.
        $canCloseProject = $canCloseProjects
            && in_array($project->status, ['pending', 'ongoing'], true)
            && ! $project->on_hold;

        $canComplete = $canCloseProject && $completionBlockers === [];
    @endphp

    {{-- `project-details-page` is what applies the brand blue from the
         client's own project page; the layout below is unchanged. --}}
    <div class="container-fluid py-2 project-details-page">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="fw-bold mb-0 text-brand-blue">Project Details</h2>

            <div class="d-flex gap-2">
                @if ($canCloseProject)
                    <button type="button" class="btn btn-success" data-bs-toggle="modal"
                        data-bs-target="#completeProjectModal">
                        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                        Complete Project
                    </button>
                @endif

                <a href="{{ route('technician.projects') }}" class="btn btn-outline-secondary">
                    Back to My Projects
                </a>
            </div>
        </div>

        {{-- Overdue: the last scheduled day has passed but the project is still
             open. A lead cannot reschedule, so the only way out offered here is
             to close it off. --}}
        @if ($project->isOverdue())
            <div class="alert alert-warning border-0 shadow-sm mb-4 overdue-banner" role="alert">
                <div class="d-flex flex-wrap align-items-start gap-3">
                    <div class="overdue-banner-icon">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    </div>

                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-1">This project is overdue</h5>
                        <p class="mb-0">
                            Its last scheduled day was
                            <strong>{{ $project->scheduleEndsOn()->format('F j, Y') }}</strong>
                            ({{ $project->scheduleEndsOn()->diffForHumans() }}), but the project is still
                            <strong>{{ $project->status }}</strong>.
                            Close it off once the work is finished, or ask an administrator to extend the
                            schedule.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Project Information -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-3">
                    <div>
                        <h2 class="fw-bold mb-2">{{ $client?->fullname ?? 'N/A' }}</h2>

                        <span class="fw-bold me-4 mb-3 project-reference">{{ $project->reference_no }}</span>

                        <div class="text-muted">
                            <span class="me-2">
                                <i class="bi bi-file-earmark-text text-brand-blue" aria-hidden="true"></i>
                                Project ID: {{ $project->project_id }}
                            </span>

                            <span>
                                <i class="bi bi-geo-alt text-brand-blue" aria-hidden="true"></i>
                                {{ $project->address }}
                            </span>
                        </div>

                        <div class="text-muted">
                            <span>
                                <i class="{{ $clientTypeClass }}" aria-hidden="true"></i>
                                {{ $client?->client_type ?? 'N/A' }}
                            </span>

                            @if (strtolower($client?->client_type ?? '') === 'commercial')
                                <span class="ms-3">Company: {{ $client?->company_name ?? 'N/A' }}</span>
                            @endif
                        </div>

                        <div class="text-muted mb-3">
                            <span>
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                {{ $client?->contact_number ?? 'N/A' }}
                            </span>

                            <span class="ms-3">
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                {{ $client?->email_address ?? 'N/A' }}
                            </span>
                        </div>

                        @foreach ($project->projectTypes as $type)
                            <span class="badge rounded-pill fs-6 px-3 py-2 project-type-badge">
                                {{ $type->type_name }}
                            </span>
                        @endforeach
                    </div>

                    <div>
                        <span class="badge rounded-pill fs-6 px-4 py-3 {{ $project->statusBadgeClass() }}">
                            {{ $project->statusLabel() }}
                        </span>
                    </div>
                </div>

                @if ($project->isCompleted())
                    <hr>
                    <div class="completion-report">
                        <h5 class="fw-bold text-success mb-3">
                            <i class="bi bi-check-circle me-2" aria-hidden="true"></i>
                            Completion Report
                        </h5>

                        <div class="mb-2">
                            <span class="fw-semibold me-2">Completion Date:</span>
                            <span>{{ $project->completed_at?->format('M d, Y') ?? 'N/A' }}</span>
                        </div>

                        <div class="mb-2">
                            <span class="fw-semibold d-block">Completion Summary:</span>
                            <p class="mb-0">{{ $project->completion_summary ?? 'N/A' }}</p>
                        </div>

                        @if ($project->completion_remarks)
                            <div class="mb-2">
                                <span class="fw-semibold d-block">Completion Remarks:</span>
                                <p class="mb-0">{{ $project->completion_remarks }}</p>
                            </div>
                        @endif

                        @if ($project->completionPhotos->isNotEmpty())
                            <div class="mt-3">
                                <span class="fw-semibold d-block mb-2">Completion Photos:</span>
                                <div class="row g-3">
                                    @foreach ($project->completionPhotos as $photo)
                                        <div class="col-lg-3 col-md-4 col-6">
                                            <a href="{{ asset($photo->photo_path) }}" target="_blank"
                                                rel="noopener noreferrer">
                                                <img src="{{ asset($photo->photo_path) }}"
                                                    class="img-fluid rounded border" alt="Completion photo"
                                                    style="height:170px;width:100%;object-fit:cover;">
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                <hr>

                {{-- No quotation document either: what a project is worth is
                     commercial information, not something the crew running it
                     needs. --}}
                <div class="d-flex gap-2 flex-wrap">
                    @if ($assessmentDocument)
                        <a href="{{ asset($assessmentDocument->document_path) }}" class="btn btn-outline-primary"
                            target="_blank" rel="noopener noreferrer">Assessment</a>
                    @else
                        <button type="button" class="btn btn-outline-primary" disabled>Assessment</button>
                    @endif

                    @if ($client?->client_type === 'Commercial')
                        @if ($contractDocument)
                            <a href="{{ asset($contractDocument->document_path) }}" class="btn btn-outline-success"
                                target="_blank" rel="noopener noreferrer">Contract</a>
                        @else
                            <button type="button" class="btn btn-outline-success" disabled>Contract</button>
                        @endif
                    @endif
                </div>

                {{-- No quotation here: what a project is worth is commercial
                     information, not something the crew running it needs. --}}
                <div class="mt-3">
                    <span class="fw-bold me-2">Project Description:</span>
                    <p>{{ $project->description ?? 'N/A' }}</p>
                </div>
            </div>
        </div>

        <!-- Team + Schedule -->
        <div class="row mb-4">
            <div class="col-lg-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h4 class="mb-0 fw-bold">Assigned Team</h4>
                    </div>

                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            @forelse ($project->projectTechnicians as $projectTechnician)
                                @php
                                    $technician = $projectTechnician->technician;
                                @endphp

                                @if ($technician)
                                    <li class="list-group-item d-flex align-items-start gap-3">
                                        <x-user-avatar :user="$technician->account" size="md" />

                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="fw-semibold">{{ $technician->name }}</span>

                                                @if ($technician->technician_id === $technicianId)
                                                    <span class="badge bg-info text-dark">You</span>
                                                @endif

                                                @if (optional($technician->account)->role === 'lead_technician')
                                                    <span class="badge project-lead-badge">Lead Technician</span>
                                                @else
                                                    <span class="badge bg-secondary">Technician</span>
                                                @endif
                                            </div>

                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                @forelse ($technician->skills->sortBy('skill_name') as $skill)
                                                    <span class="technician-chip">{{ $skill->skill_name }}</span>
                                                @empty
                                                    <span class="text-muted small">No specialties assigned.</span>
                                                @endforelse
                                            </div>
                                        </div>
                                    </li>
                                @endif
                            @empty
                                <li class="list-group-item text-muted">No technicians assigned.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h4 class="mb-0 fw-bold">Project Schedule</h4>
                    </div>

                    <div class="card-body">
                        <div><strong>Schedules:</strong></div>
                        <ul>
                            {{-- Shared formatter, so the hours a technician is
                                 expected on site read the same here as they do
                                 on the administrator's copy of this page. --}}
                            @forelse ($project->schedules as $schedule)
                                <li class="list-group-item">
                                    <strong>{{ $schedule->describe() }}</strong>
                                    @if ($schedule->isPartialDay())
                                        <span class="badge bg-info text-dark ms-1">Partial Day</span>
                                    @endif
                                </li>
                            @empty
                                <li class="list-group-item text-muted">No schedule set.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Project Activity -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h4 class="fw-bold mb-0">Project Activity</h4>
            </div>

            <div class="card-body">
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#reports" type="button"
                            role="tab">
                            Technician Reports
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tasks" type="button"
                            role="tab">
                            Tasks
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- Technician Reports -->
                    <div class="tab-pane fade show active" id="reports" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <form method="GET"
                                action="{{ route('technician.projects.show', $project->project_id) }}">
                                <select class="form-select" name="report_type" onchange="this.form.submit()">
                                    <option value="" @selected(request('report_type') == '')>All Reports</option>
                                    @foreach ($reportTypes as $value => $label)
                                        <option value="{{ $value }}" @selected(request('report_type') == $value)>
                                            {{ $label }}s
                                        </option>
                                    @endforeach
                                </select>
                            </form>

                            @if ($canSubmitReport)
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                    data-bs-target="#addReportModal">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                    Add Report
                                </button>
                            @endif
                        </div>

                        @forelse ($reports as $report)
                            <div
                                class="card mb-3 {{ $report->report_type == 'progress' ? 'border-primary bg-primary-subtle' : 'border-danger bg-danger-subtle' }}">
                                <div class="card-header d-flex justify-content-between bg-transparent">
                                    <div>
                                        <span class="badge {{ $report->typeBadgeClass() }}">
                                            {{ $report->typeLabel() }}
                                        </span>
                                        <h5 class="mt-2 mb-0">{{ $report->report_title }}</h5>
                                        <small class="text-muted">
                                            by {{ $report->submitterName() }}
                                        </small>
                                    </div>

                                    <small class="text-muted">
                                        {{ $report->report_date?->format('M d, Y') ?? '—' }}
                                    </small>
                                </div>

                                <div class="card-body">
                                    <p>{{ $report->report_description }}</p>

                                    @if ($report->images->count())
                                        <h6>Pictures</h6>
                                        <div class="row g-3">
                                            @foreach ($report->images as $image)
                                                <div class="col-lg-3 col-md-4 col-6">
                                                    <a href="{{ asset('storage/' . $image->image_path) }}"
                                                        target="_blank" rel="noopener noreferrer">
                                                        <img src="{{ asset('storage/' . $image->image_path) }}"
                                                            class="img-fluid rounded border" alt="Report attachment"
                                                            style="height:170px;width:100%;object-fit:cover;">
                                                    </a>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="alert alert-info">No technician reports found.</div>
                        @endforelse
                    </div>

                    <!-- Tasks -->
                    <div class="tab-pane fade" id="tasks" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h5 class="mb-0 fw-bold">Task List</h5>

                            @if ($canManageTasks)
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                    data-bs-target="#addTaskModal">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                    Assign New Task
                                </button>
                            @endif
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-info">
                                    <tr>
                                        <th>Task</th>
                                        <th>Assigned Technician</th>
                                        <th>Start Date</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @forelse ($tasks as $task)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $task->task_title }}</div>
                                                <small class="text-muted">
                                                    {{ \Illuminate\Support\Str::limit($task->task_description, 60) }}
                                                </small>
                                            </td>

                                            <td>
                                                {{ $task->technician?->name ?? 'Unassigned' }}
                                                @if ($task->technician_id === $technicianId)
                                                    <span class="badge bg-info text-dark ms-1">You</span>
                                                @endif
                                            </td>

                                            <td>
                                                {{ $task->start_date ? \Carbon\Carbon::parse($task->start_date)->format('M d, Y') : 'Unassigned' }}
                                            </td>

                                            <td>
                                                {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('M d, Y') : 'Unassigned' }}
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
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-secondary text-center py-4">
                                                No tasks have been created on this project yet.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================ PER-TASK MODALS ============================ --}}
    @foreach ($tasks as $task)
        {{-- The same dialogs the Super Admin portal opens. --}}
        <x-task-details-modal :task="$task"
            :technicians="$canManageTasks ? $technicians : collect()"
            :active-task-counts="$technicianActiveTaskCounts" :schedule-ranges="$scheduleRanges"
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

    {{-- ============================ ASSIGN NEW TASK ============================ --}}
    @if ($canManageTasks)
        <div class="modal fade" id="addTaskModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <form class="modal-content"
                    action="{{ route('technician.tasks.store', $project->project_id) }}" method="POST">
                    @csrf

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-list-task me-2" aria-hidden="true"></i>
                            Create Task
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="newTaskTitle">Task Name</label>
                            <input type="text" class="form-control" id="newTaskTitle" name="task_title"
                                placeholder="Enter task title" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="newTaskDescription">Description</label>
                            <textarea class="form-control" id="newTaskDescription" name="task_description" rows="4"
                                placeholder="Describe the task..." required></textarea>
                        </div>

                        <div class="row" data-task-date-row data-schedule-ranges='@json($scheduleRanges)'>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="newTaskStartDate">Start Date</label>
                                <input type="text" class="form-control" id="newTaskStartDate" name="start_date"
                                    placeholder="Select start date" data-task-start required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="newTaskDueDate">Due Date</label>
                                <input type="text" class="form-control" id="newTaskDueDate" name="due_date"
                                    placeholder="Select due date" data-task-due required>
                            </div>

                            <div class="col-12 mb-3">
                                <div class="form-text">
                                    Allowed:
                                    {{ app(\App\Services\TaskScheduleRules::class)->describeWindow($scheduleRanges->all()) ?: 'No schedule set' }}
                                    @if ($scheduleRanges->count() > 1)
                                        . This project is booked
                                        {{ app(\App\Services\TaskScheduleRules::class)->describe($scheduleRanges->all()) }},
                                        and a task may span the gap between those dates.
                                    @endif
                                </div>
                            </div>
                        </div>

                        <hr>

                        <label class="form-label fw-bold mb-3">Assigned Technician</label>

                        <div class="task-assign-row">
                            @foreach ($technicians as $technician)
                                @php
                                    $activeCount = $technicianActiveTaskCounts[$technician->technician_id] ?? 0;
                                @endphp
                                <label>
                                    <input type="radio" class="btn-check" name="technician_id"
                                        value="{{ $technician->technician_id }}" required>

                                    <div class="task-assign-card">
                                        <x-user-avatar :user="$technician->account" size="lg"
                                            class="task-assign-avatar" />
                                        <div class="task-assign-name">{{ $technician->name }}</div>
                                        <div class="task-assign-count">
                                            {{ $activeCount }}
                                            Active Task{{ $activeCount == 1 ? '' : 's' }}
                                        </div>
                                        @if (optional($technician->account)->role === 'lead_technician')
                                            <span class="badge bg-primary task-assign-lead">Lead</span>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                            Create Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ============================ ADD REPORT ============================ --}}
    @if ($canSubmitReport)
        <div class="modal fade" id="addReportModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <form class="modal-content"
                    action="{{ route('technician.reports.store', $project->project_id) }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>
                            Add Technician Report
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="newReportType">Report Type</label>
                            <select class="form-select" id="newReportType" name="report_type" required>
                                <option value="" selected disabled>Select report type&hellip;</option>
                                @foreach ($reportTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="newReportTitle">Report Title</label>
                            <input type="text" class="form-control" id="newReportTitle" name="report_title"
                                placeholder="Enter report title" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="newReportDescription">Description</label>
                            <textarea class="form-control" id="newReportDescription" name="report_description" rows="5"
                                placeholder="Describe the work completed or the incident..." required></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="newReportImages">Attachment</label>
                            <input type="file" class="form-control" id="newReportImages" name="images[]"
                                accept=".jpg,.jpeg,.png" multiple data-image-input
                                data-image-preview-target="#newReportPreview">
                            <div class="form-text">JPG, JPEG or PNG, up to 5 MB each. Optional.</div>
                        </div>

                        <div class="row g-2" id="newReportPreview"></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1" aria-hidden="true"></i>
                            Submit Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ============================ COMPLETE PROJECT ============================ --}}
    @if ($canCloseProject)
        <div class="modal fade" id="completeProjectModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form class="modal-content"
                    action="{{ route('technician.projects.complete', $project->project_id) }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-check-circle me-2" aria-hidden="true"></i>
                            Complete Project
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-1 text-secondary small">You are closing out</p>
                        <h5 class="fw-bold mb-3">{{ $project->reference_no }}</h5>

                        @if ($canComplete)
                            <p class="mb-3">
                                Every task on this project is completed. Marking it complete makes the
                                project view only and releases any dates still booked ahead.
                            </p>

                            @include('technician.partials.completion-fields', ['suffix' => 'Details'])
                        @else
                            <div class="alert alert-warning mb-0">
                                <p class="fw-semibold mb-2">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                    This project cannot be completed yet.
                                </p>
                                <ul class="mb-0 ps-3">
                                    @foreach ($completionBlockers as $blocker)
                                        <li>{{ $blocker }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" @disabled(! $canComplete)>
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                            Mark as Completed
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @push('scripts')
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        {{-- The same range-aware task date pickers the Super Admin task forms use. --}}
        <script src="/js/super-admin/taskDatePickers.js"></script>
        <script src="/js/imagePreview.js"></script>
    @endpush
@endsection
