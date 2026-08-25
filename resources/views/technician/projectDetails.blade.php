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
        // Grouped, not keyed: a project holds any number of files of each
        // type, and keyBy would quietly show only the last of them.
        $documentsByType = $project->documents->groupBy('document_type');

        // The types the crew may see, in the order Document::TYPES states -
        // so this page can never fall out of step with the administrative one
        // about what a type is called or where it sits. Quotation is dropped
        // because what a project is worth is commercial information, and
        // Contract only exists on Commercial work.
        $crewDocumentTypes = collect(\App\Models\Document::TYPES)
            ->reject(fn ($label, $type) => $type === 'quotation'
                || ($type === 'contract' && $client?->client_type !== 'Commercial'));

        $clientTypeClass = match (strtolower($client?->client_type ?? '')) {
            'residential' => 'bi bi-house-door',
            'commercial' => 'bi bi-building',
            default => 'bi bi-person',
        };

        // Closing a project is a lead's call, and only on work that is
        // actually under way: $canCloseProject arrives from
        // ProjectPolicy::offersCompletion(), which refuses a Pending,
        // Unscheduled or paused project outright. A technician reads this page
        // and completes their own tasks on it, nothing more.
        $canComplete = $canCloseProject && $completionBlockers === [];
    @endphp

    {{-- `project-details-page` is what applies the brand blue from the
         client's own project page; the layout below is unchanged. --}}
    <div class="container-fluid py-2 project-details-page">

        {{-- Paused. Everything below stays readable, which is the point of
             keeping it - what is unavailable is the work: adding a report,
             editing a task, and closing the project. The controls for those
             are already absent (the policy refuses them while a project is on
             hold); this says why. --}}
        @if ($project->on_hold)
            <div class="alert alert-secondary border-0 shadow-sm" role="alert">
                <i class="bi bi-pause-circle me-1" aria-hidden="true"></i>
                <strong>This project is on hold.</strong>
                Reports and task edits resume when an administrator lifts the hold.
            </div>
        @endif

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
             to close it off.

             Not shown to a plain technician - see $showsOverdueNotice in
             TechnicianPortalController::showProject. Neither way out is theirs
             to take, so the notice would only be a warning about a decision
             somebody else has to make. --}}
        @if ($showsOverdueNotice && $project->isOverdue())
            <div class="alert alert-warning border-0 shadow-sm mb-4 overdue-banner" role="alert">
                <div class="d-flex flex-wrap align-items-start gap-3">
                    <div class="overdue-banner-icon">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    </div>

                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-1">This project is overdue</h5>
                        <p class="mb-0">
                            Last scheduled day was
                            <strong>{{ $project->scheduleEndsOn()->format('F j, Y') }}</strong>.
                            Close it off, or ask an administrator to extend the schedule.
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
                                Project ID: {{ $project->displayCode() }}
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
                        {{-- The shared component, same size and shape as the
                             administrative page draws it. Written out by hand
                             here before, which is how the same project ends up
                             wearing two different badges depending on who is
                             looking at it. --}}
                        <x-project-status-badge :project="$project" class="rounded-pill fs-6 px-4 py-3" />
                    </div>
                </div>

                {{-- Readable from the moment completion is filed, so a lead can
                     check what they submitted while the client decides. --}}
                @if ($project->hasCompletionReport() && ! $project->isCancelled())
                    <hr>
                    <div class="completion-report">
                        <h5 class="fw-bold text-success mb-3">
                            <i class="bi bi-check-circle me-2" aria-hidden="true"></i>
                            Completion Report
                            @unless ($project->isCompleted())
                                <span class="badge bg-secondary align-middle ms-2">Awaiting client confirmation</span>
                            @endunless
                        </h5>

                        <div class="mb-2">
                            <span class="fw-semibold me-2">Completion Date:</span>
                            <span>{{ \App\Support\BusinessTime::format($project->completed_at, 'M d, Y', 'N/A') }}</span>
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
                                            <a href="{{ $photo->url() }}" target="_blank"
                                                rel="noopener noreferrer">
                                                <img src="{{ $photo->url() }}"
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

                {{-- The same grouped cards the administrative page draws, from
                     the same markup and the same stylesheet: a file is a named
                     row you can read, not a button labelled "Assessment 2" that
                     tells you nothing about what is inside it.

                     What differs is which types are here and what may be done
                     with them, and neither of those is a matter of design:
                     Quotation is withheld from the crew, and nothing carries a
                     remove control, because the crew reads these files and an
                     administrator manages them. --}}
                <div class="project-document-groups">
                    @foreach ($crewDocumentTypes as $type => $label)
                        @php $files = $documentsByType->get($type, collect()); @endphp

                        <div class="project-document-group">
                            <div class="project-document-group-head">
                                <span class="fw-semibold">{{ $label }}</span>
                                @if ($files->isNotEmpty())
                                    <span class="badge project-document-count">{{ $files->count() }}</span>
                                @endif
                            </div>

                            @forelse ($files as $document)
                                <div class="project-document-file">
                                    <a href="{{ $document->url() }}" target="_blank"
                                        rel="noopener noreferrer" class="project-document-link"
                                        title="{{ $document->document_name }}">
                                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                        <span>{{ $document->document_name }}</span>
                                    </a>
                                </div>
                            @empty
                                <span class="text-muted small">No {{ strtolower($label) }} uploaded.</span>
                            @endforelse
                        </div>
                    @endforeach
                </div>

                {{-- The administrative page prints the quotation figure above
                     this; here there is nothing between the files and the
                     description, for the same reason the Quotation documents
                     are absent. --}}
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

                        {{-- Deactivating a technician keeps their bookings rather than
                             handing the dates back silently, so the lead running the job
                             has to be told who can no longer work it. The team itself is
                             an administrator's to change, so this asks for the two things
                             a lead can actually do about it. --}}
                        @if ($flagsInactiveCrew && $project->needsRecrew())
                            <div class="alert alert-warning rounded-0 border-0 border-bottom mb-0" role="alert">
                                <i class="bi bi-person-exclamation me-1" aria-hidden="true"></i>
                                <strong>This team needs attention.</strong>
                                @unless ($project->hasLead())
                                    No lead technician. Ask an administrator to assign one.
                                @endunless
                                @if ($project->inactiveCrew()->isNotEmpty())
                                    {{ $project->inactiveCrewNames() }}
                                    can no longer sign in. Move their tasks and ask an administrator
                                    to update the team.
                                @endif
                            </div>
                        @endif

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

                                                @if ($flagsInactiveCrew && ! $technician->isAssignable())
                                                    <span class="badge bg-warning text-dark">Account inactive</span>
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
                                <div class="card-header d-flex justify-content-between">
                                    <div>
                                        <span class="badge {{ $report->typeBadgeClass() }}">
                                            {{ $report->typeLabel() }}
                                        </span>
                                        <h5 class="mt-2 mb-0">{{ $report->report_title }}</h5>

                                        {{-- Who filed it, beside their picture,
                                             exactly as the administrative page
                                             signs a report. --}}
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            @if ($report->submitterAvatarUrl())
                                                <img class="user-avatar user-avatar-xs"
                                                    src="{{ $report->submitterAvatarUrl() }}" alt=""
                                                    loading="lazy">
                                            @endif
                                            <small class="text-muted">
                                                by {{ $report->submitterName() }}
                                            </small>
                                        </div>
                                    </div>

                                    <div class="text-end">
                                        <small class="text-muted d-block">
                                            {{ $report->report_date?->format('M d, Y') ?? '—' }}
                                        </small>

                                        {{-- A lead archives the reports they filed
                                             themselves and no others: the button is
                                             drawn from the same policy the endpoint
                                             enforces, so what is missing here is
                                             refused there. --}}
                                        @can('archive', $report)
                                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2"
                                                data-bs-toggle="modal"
                                                data-bs-target="#archiveReportModal{{ $report->id }}">
                                                <i class="bi bi-archive me-1" aria-hidden="true"></i>
                                                Archive
                                            </button>
                                        @endcan
                                    </div>
                                </div>

                                <div class="card-body">
                                    <p>{{ $report->report_description }}</p>

                                    @if ($report->images->count())
                                        <h6>Pictures</h6>
                                        <div class="row g-3">
                                            @foreach ($report->images as $image)
                                                <div class="col-lg-3 col-md-4 col-6">
                                                    <a href="{{ $image->url() }}"
                                                        target="_blank" rel="noopener noreferrer">
                                                        <img src="{{ $image->url() }}"
                                                            class="img-fluid rounded border" alt="Report attachment"
                                                            style="height:170px;width:100%;object-fit:cover;">
                                                    </a>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @can('archive', $report)
                                {{-- ==================== ARCHIVE REPORT ==================== --}}
                                <div class="modal fade" id="archiveReportModal{{ $report->id }}" tabindex="-1"
                                    aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">

                                            <div class="modal-header">
                                                <h5 class="modal-title">Archive Report</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                    aria-label="Close"></button>
                                            </div>

                                            <div class="modal-body">
                                                Archive <strong>{{ $report->displayCode() }} &mdash;
                                                    {{ $report->report_title }}</strong>?

                                                <p class="text-secondary small mb-0 mt-2">
                                                    It comes off this project's report list and off your Reports
                                                    page. The report, its images and its attachments are kept, and
                                                    it can be restored from Archived Reports.
                                                </p>
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary"
                                                    data-bs-dismiss="modal">Cancel</button>

                                                <form method="POST"
                                                    action="{{ route('technician-reports.archive', $report->id) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-warning">
                                                        <i class="bi bi-archive me-1" aria-hidden="true"></i>
                                                        Archive Report
                                                    </button>
                                                </form>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            @endcan
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
                                    Add Task
                                </button>
                            @endif
                        </div>

                        <div class="table-responsive">
                            <table id="portalTasksTable"
                                class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-info">
                                    <tr>
                                        <th>Task</th>
                                        <th>Assigned To</th>
                                        <th>Start Date</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>

                                {{-- No blade fallback row: a single colspan cell has
                                     fewer cells than the header, which DataTables
                                     cannot parse. Its own emptyTable message covers
                                     it, the same way it does on My Projects. --}}
                                <tbody>
                                    @foreach ($tasks as $task)
                                        <tr data-status="{{ $task->status }}">
                                            <td>
                                                <div class="fw-semibold">{{ $task->task_title }}</div>
                                                <small class="text-muted">
                                                    {{ \Illuminate\Support\Str::limit($task->task_description, 60) }}
                                                </small>
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
                                                    <span>{{ $task->technician?->name ?? 'Unassigned' }}</span>
                                                    @if ($task->technician_id === $technicianId)
                                                        <span class="badge bg-info text-dark">You</span>
                                                    @endif
                                                </div>
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
                                    @endforeach
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
                                    {{ app(\App\Services\TaskScheduleRules::class)->describeSelectable($scheduleRanges->all()) }}
                                </div>
                            </div>
                        </div>

                        <hr>

                        <label class="form-label fw-bold mb-3">Assign To</label>

                        <div class="task-assign-row">
                            @foreach ($technicians as $technician)
                                @php
                                    $activeCount = $technicianActiveTaskCounts[$technician->technician_id] ?? 0;
                                    // Still listed, because deactivating an
                                    // account does not take somebody off a team -
                                    // but not selectable: they cannot open the
                                    // project, close the task, or be told they
                                    // have one.
                                    $cannotReceiveWork = ! $technician->isAssignable();
                                @endphp
                                <label>
                                    <input type="radio" class="btn-check" name="technician_id"
                                        value="{{ $technician->technician_id }}" required
                                        @disabled($cannotReceiveWork)>

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
                                        @if ($cannotReceiveWork)
                                            <span class="badge bg-warning text-dark task-assign-inactive">
                                                Account inactive
                                            </span>
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
                                All tasks are complete. Submitting this sends the project to the client and
                                makes it view only.
                            </p>

                            @include('technician.partials.completion-fields', ['suffix' => 'Details'])
                        @else
                            <div class="alert alert-warning mb-0">
                                <p class="fw-semibold mb-2">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                    This project cannot be completed yet.
                                </p>
                                {{-- Each one with the way to deal with it. A
                                     refusal that only says what is wrong
                                     leaves the lead to go and find the tasks
                                     themselves; the link puts them on the
                                     tab that holds them. --}}
                                <ul class="mb-0 ps-3">
                                    @foreach ($completionBlockers as $blocker)
                                        <li class="mb-1">
                                            {{ $blocker['message'] }}
                                            @if ($blocker['action'])
                                                <a class="alert-link d-inline-flex align-items-center gap-1"
                                                    href="{{ $blocker['action']['url'] }}">
                                                    {{ $blocker['action']['label'] }}
                                                    <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                                                </a>
                                            @endif
                                        </li>
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

        {{-- The task list is a DataTable here for the same reason it is one on
             the administrative copy of this page: a project can run to dozens
             of tasks, and finding one should not mean scrolling.

             Built through the portal's own helper, so it carries the search box
             and the paging every other table in this portal has, and page
             lengths matched to the administrative task list.

             Built when the tab is first opened rather than at load: a table
             measured while its pane is hidden has no width to measure, and its
             columns come out wrong. --}}
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tab = document.querySelector('button[data-bs-target="#tasks"]');

                if (!tab) {
                    return;
                }

                let table = null;

                tab.addEventListener('shown.bs.tab', function() {
                    if (table) {
                        table.columns.adjust();

                        return;
                    }

                    table = window.portal.dataTable('#portalTasksTable', 'tasks', {
                        pageLength: 5,
                        lengthMenu: [5, 10, 25, 50],
                        columnDefs: [{
                            targets: -1,
                            orderable: false
                        }],
                    });
                });
            });
        </script>

        {{-- A completion blocker links to the tab that holds what is blocking
             it, and the panes on this page are tabs rather than sections, so
             the fragment has to be turned into a tab switch.

             Last on purpose: opening the Tasks tab is what builds the table
             above, and a listener that has not been registered yet cannot
             hear it. --}}
        <script src="/js/tabFromHash.js"></script>
    @endpush
@endsection
