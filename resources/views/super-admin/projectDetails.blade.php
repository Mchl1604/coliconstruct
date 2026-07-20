@extends('layouts.superadminNav')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="/css/super-admin/projectDetails.css">
    @endpush
    <div class="container-fluid py-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="fw-bold">Project Details</h2>

            <a href="{{ route('super-admin.projects') }}" class="btn btn-outline-secondary">
                Back to Projects
            </a>
        </div>

        <!-- Project Information -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="d-flex align-items-center mb-2">
                            <h2 class="fw-bold mb-0">
                                {{ $project->clients->first()->fullname ?? 'N/A' }}
                            </h2>

                            <button class="btn btn-outline-info border btn-sm ms-2" data-bs-toggle="modal"
                                data-bs-target="#editProjectDetailsModal">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                        <span class="fw-bold me-4 mb-3">
                            {{ $project->reference_no }}
                        </span>

                        <div class="text-muted">
                            <span class="me-2">
                                <i class="bi bi-file-earmark-text"></i>
                                Project ID: {{ $project->project_id }}
                            </span>

                            <span>
                                <i class="bi bi-geo-alt"></i>
                                {{ $project->address }}
                            </span>
                        </div>

                        @php
                            $client = $project->clients->first();
                            $clientTypeClass = match (strtolower($client?->client_type ?? '')) {
                                'residential' => 'bi bi-house-door',
                                'commercial' => 'bi bi-building',
                                default => 'bi bi-person',
                            };
                        @endphp

                        <div class="text-muted">
                            <span>
                                <i class="{{ $clientTypeClass }}"></i>
                                {{ $client?->client_type ?? 'N/A' }}
                            </span>

                            @if (strtolower($client?->client_type ?? '') === 'commercial')
                                <span class="ms-3">
                                    Company:
                                    {{ $project->clients->first()->company_name ?? 'N/A' }}
                                </span>
                            @endif
                        </div>

                        <div class="text-muted mb-3">
                            <span>
                                <i class="bi bi-telephone"></i>
                                {{ $client?->contact_number ?? 'N/A' }}
                            </span>

                            <span class="ms-3">
                                <i class="bi bi-envelope"></i>
                                {{ $client?->email_address ?? 'N/A' }}
                            </span>
                        </div>

                        @foreach ($project->projectTypes as $type)
                            <span class="badge rounded-pill border text-dark fs-6 px-3 py-2">
                                {{ $type->type_name }}
                            </span>
                        @endforeach
                    </div>
                    <div>
                        @php
                            $statusClass = match ($project->status) {
                                'pending' => 'bg-warning text-dark',
                                'ongoing' => 'bg-primary',
                                'completed' => 'bg-success',
                                'cancelled' => 'bg-danger',
                                default => 'bg-secondary',
                            };
                        @endphp

                        <span class="badge rounded-pill fs-6 px-4 py-3 {{ $statusClass }}">
                            {{ ucfirst($project->status) }}
                        </span>

                    </div>

                </div>

                <hr>

                @php
                    $documentsByType = $project->documents->keyBy('document_type');
                @endphp

                <div class="d-flex gap-2">

                    @php
                        $assessmentDocument = $documentsByType->get('assessment');
                        $quotationDocument = $documentsByType->get('quotation');
                        $contractDocument = $documentsByType->get('contract');
                    @endphp

                    @if ($assessmentDocument)
                        <a href="{{ asset($assessmentDocument->document_path) }}" class="btn btn-outline-primary"
                            target="_blank" rel="noopener noreferrer">
                            Assessment
                        </a>
                    @else
                        <button class="btn btn-outline-primary" disabled>
                            Assessment
                        </button>
                    @endif

                    @if ($quotationDocument)
                        <a href="{{ asset($quotationDocument->document_path) }}" class="btn btn-outline-primary"
                            target="_blank" rel="noopener noreferrer">
                            Quotation
                        </a>
                    @else
                        <button class="btn btn-outline-primary" disabled>
                            Quotation
                        </button>
                    @endif

                    @if ($project->clients->first()->client_type === 'Commercial')
                        @if ($contractDocument)
                            <a href="{{ asset($contractDocument->document_path) }}" class="btn btn-outline-success"
                                target="_blank" rel="noopener noreferrer">
                                Contract
                            </a>
                        @else
                            <button class="btn btn-outline-success" disabled>
                                Contract
                            </button>
                        @endif
                    @endif

                </div>
                <div class="mt-3">
                    <span class="fw-bold me-2">
                        Quotation:
                    </span>
                    <span>
                        ₱ {{ number_format($project->quotation, 2) }}
                    </span>
                </div>
                <div class="mt-3">
                    <span class="fw-bold me-2">
                        Project Description:
                    </span>
                    <p>
                        {{ $project->description ?? 'N/A' }}
                    </p>
                </div>

            </div>
        </div>
        <!-- Team + Date -->
        <div class="row mb-4">

            <!-- Assigned Team -->
            <div class="col-lg-6 mb-3">

                <div class="card shadow-sm h-100">

                    <div class="card-header bg-white d-flex justify-content-between align-items-center">

                        <h4 class="mb-0 fw-bold">
                            Assigned Team
                        </h4>

                        <button class="btn btn-outline-primary">
                            Edit Schedule
                        </button>

                    </div>

                    <div class="card-body p-0">

                        <ul class="list-group list-group-flush">

                            <li class="list-group-item d-flex align-items-center justify-content-between">
                                Tech. Carl Dominguez

                                <span class="badge bg-primary">
                                    Lead
                                </span>
                            </li>

                            <li class="list-group-item">
                                Tech. Anne Mendoza
                            </li>

                            <li class="list-group-item">
                                Tech. Lito Ramos
                            </li>

                        </ul>

                    </div>

                </div>

            </div>

            <!-- Project Schedule -->
            <div class="col-lg-6 mb-3">

                <div class="card shadow-sm h-100">

                    <div class="card-header bg-white">

                        <h4 class="mb-0 fw-bold">
                            Project Schedule
                        </h4>

                    </div>

                    <div class="card-body">

                        <div class="mb-3">

                            <label class="text-muted">
                                Start Date
                            </label>

                            <h5 class="fw-bold">
                                Apr 14, 2026
                            </h5>

                        </div>

                        <div>

                            <label class="text-muted">
                                End Date
                            </label>

                            <h5 class="fw-bold">
                                Apr 22, 2026
                            </h5>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- Project Activity -->
        <div class="card shadow-sm">

            <div class="card-header bg-white">

                <h4 class="fw-bold mb-0">
                    Project Activity
                </h4>

            </div>

            <div class="card-body">

                <!-- Main Tabs -->
                <ul class="nav nav-tabs mb-4" id="activityTabs">

                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#reports">

                            Technician Reports

                        </button>
                    </li>

                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tasks">

                            Tasks

                        </button>
                    </li>

                </ul>

                <div class="tab-content">

                    <!-- Technician Reports -->
                    <div class="tab-pane fade show active" id="reports">
                        <div class="d-flex justify-content-between align-items-center mb-3">

                            <form method="GET" action="{{ route('super-admin.projects.show', $project->project_id) }}">

                                <select class="form-select" name="report_type" onchange="this.form.submit()">

                                    <option value="" {{ request('report_type') == '' ? 'selected' : '' }}>
                                        All Reports
                                    </option>

                                    <option value="progress" {{ request('report_type') == 'progress' ? 'selected' : '' }}>
                                        Progress Reports
                                    </option>

                                    <option value="incident" {{ request('report_type') == 'incident' ? 'selected' : '' }}>
                                        Incident Reports
                                    </option>

                                </select>

                            </form>

                            <button class="btn btn-primary" data-bs-toggle="modal"
                                data-bs-target="#addTechnicianReportModal">

                                <i class="bi bi-plus-lg me-1"></i>

                                Add Report

                            </button>

                        </div>

                        <!-- Report Card -->
                        @forelse($reports as $report)
                            <div
                                class="card mb-3
    {{ $report->report_type == 'progress' ? 'border-primary bg-primary-subtle' : 'border-danger bg-danger-subtle' }}">

                                <div class="card-header d-flex justify-content-between">

                                    <div>

                                        <span
                                            class="badge
                {{ $report->report_type == 'progress' ? 'bg-primary' : 'bg-danger' }}">

                                            {{ ucfirst($report->report_type) }}

                                        </span>

                                        <h5 class="mt-2 mb-0">
                                            {{ $report->report_title }}
                                        </h5>

                                    </div>

                                    <small class="text-muted">

                                        {{ \Carbon\Carbon::parse($report->report_date)->format('M d, Y') }}

                                    </small>

                                </div>

                                <div class="card-body">

                                    <p>
                                        {{ $report->report_description }}
                                    </p>

                                    @if ($report->images->count())
                                        <h6>Pictures</h6>

                                        <div class="row g-3">

                                            @foreach ($report->images as $image)
                                                <div class="col-lg-3 col-md-4 col-6">

                                                    <a href="{{ asset('storage/' . $image->image_path) }}"
                                                        target="_blank">

                                                        <img src="{{ asset('storage/' . $image->image_path) }}"
                                                            class="img-fluid rounded border"
                                                            style="height:170px;width:100%;object-fit:cover;">

                                                    </a>

                                                </div>
                                            @endforeach

                                        </div>
                                    @endif

                                </div>

                            </div>

                        @empty

                            <div class="alert alert-info">

                                No technician reports found.

                            </div>
                        @endforelse
                    </div>

                    <!-- Tasks -->
                    <div class="tab-pane fade" id="tasks">
                        <div class="d-flex justify-content-between align-items-center mb-3">

                            <h5 class="mb-0 fw-bold">
                                Task List
                            </h5>

                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                                <i class="bi bi-plus-lg me-1"></i>
                                Add Task
                            </button>

                        </div>

                        <div class="table-responsive">

                            <table id="tasksTable" class="table table-hover table-striped align-middle mb-0">

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

                                <tbody>

                                    @foreach ($tasks as $task)
                                        <tr data-status="{{ $task->status }}">

                                            <td>
                                                <div class="fw-semibold">
                                                    {{ $task->task_title }}
                                                </div>

                                                <small class="text-muted">
                                                    {{ \Illuminate\Support\Str::limit($task->task_description, 60) }}
                                                </small>
                                            </td>

                                            <td>
                                                {{ optional($task->technician)->name ?? 'Unassigned' }}
                                            </td>

                                            <td>
                                                {{ \Carbon\Carbon::parse($task->start_date)->format('M d, Y') }}
                                            </td>

                                            <td>
                                                {{ \Carbon\Carbon::parse($task->due_date)->format('M d, Y') }}
                                            </td>

                                            <td>
                                                @switch($task->status)
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

                                            <td class="text-start">

                                                <div class="d-flex justify-content-start gap-2">

                                                    {{-- View / Edit --}}
                                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                                        data-bs-target="#taskModal{{ $task->task_id }}">

                                                        <i class="bi bi-eye"></i>

                                                    </button>

                                                    {{-- Complete --}}
                                                    @if ($task->status != 'completed')
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

            </div>

        </div>
    </div>

    <!-- EDIT PROJECT DETAILS MODAL -->
    <div class="modal fade" id="editProjectDetailsModal" tabindex="-1" aria-labelledby="editProjectDetailsModalLabel"
        aria-hidden="true">

        <div class="modal-dialog modal-xl modal-dialog-centered" style="max-height: calc(100vh - 3rem);">

            <div class="modal-content border-0 shadow d-flex flex-column" style="max-height: calc(100vh - 3rem);">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="editProjectDetailsModalLabel">
                        <i class="bi bi-pencil-square me-2"></i>
                        Edit Project Details
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <form class="d-flex flex-column flex-grow-1 overflow-hidden"
                    action="{{ route('super-admin.projects.update', $project->project_id) }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="modal-body flex-grow-1 overflow-auto">

                        <!-- Client Information -->
                        <h6 class="text-primary fw-bold mb-3">
                            <i class="bi bi-person-circle me-2"></i>
                            Client Information
                        </h6>

                        <div class="row g-3">

                            <div class="col-md-5">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name"
                                    value="{{ $project->clients->first()->firstname ?? '' }}">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Middle Initial</label>
                                <input type="text" maxlength="1" class="form-control text-center"
                                    name="middle_initial" value="{{ $project->clients->first()->middlename ?? '' }}">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name"
                                    value="{{ $project->clients->first()->surname ?? '' }}">
                            </div>

                        </div>

                        @if ($project->clients->first()->client_type === 'Commercial')
                            <div class="mt-3">
                                <label class="form-label">Company Name</label>

                                <input type="text" class="form-control" name="company_name"
                                    value="{{ $project->clients->first()->company_name ?? '' }}">
                            </div>
                        @endif


                        <div class="mt-4">

                            <label class="form-label">Address</label>

                            <input type="text" class="form-control" name="address" value="{{ $project->address }}">

                        </div>

                        <div class="row g-3 mt-1">

                            <div class="col-md-6">
                                <label class="form-label">
                                    Contact Number
                                </label>

                                <input type="text" class="form-control" name="contact_number"
                                    value="{{ $project->clients->first()->contact_number ?? '' }}">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Email Address
                                </label>

                                <input type="email" class="form-control" name="email_address"
                                    value="{{ $project->clients->first()->email_address ?? '' }}">
                            </div>

                        </div>

                        <hr class="my-4">

                        <!-- Project Information -->
                        <h6 class="text-primary fw-bold mb-3">
                            <i class="bi bi-folder2-open me-2"></i>
                            Project Information
                        </h6>
                        <div class="mb-4">

                            <label class="form-label fw-semibold">
                                Project Types
                            </label>

                            <div class="dropdown mb-3">

                                <button class="btn btn-outline-primary dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown">

                                    <i class="bi bi-plus-lg me-1"></i>
                                    Add Project Type

                                </button>

                                <ul class="dropdown-menu">

                                    @foreach ($projectTypes->reject(fn($type) => $project->projectTypes->contains('type_id', $type->type_id)) as $type)
                                        <li>
                                            <button type="button" class="dropdown-item add-project-type"
                                                data-type-id="{{ $type->type_id }}"
                                                data-type-name="{{ $type->type_name }}">

                                                {{ $type->type_name }}

                                            </button>
                                        </li>
                                    @endforeach

                                </ul>

                            </div>

                            <div id="projectTypesContainer" class="d-flex flex-wrap gap-2 mb-3">

                                @foreach ($project->projectTypes as $type)
                                    <span class="badge bg-primary d-flex align-items-center px-3 py-2"
                                        data-type-id="{{ $type->type_id }}">

                                        {{ $type->type_name }}

                                        <button type="button" class="btn-close btn-close-white ms-2 remove-project-type"
                                            data-type-id="{{ $type->type_id }}" aria-label="Remove">
                                        </button>

                                    </span>
                                @endforeach

                            </div>



                            <!-- Hidden inputs submitted with the form -->
                            <div id="projectTypesInputs">

                                @foreach ($project->projectTypes as $type)
                                    <input type="hidden" name="project_types[]" value="{{ $type->type_id }}"
                                        data-type-id="{{ $type->type_id }}">
                                @endforeach

                            </div>

                        </div>


                        <div class="mb-4">

                            <label class="form-label">
                                Quotation
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    ₱
                                </span>

                                <input type="number" class="form-control" name="quotation"
                                    value="{{ $project->quotation }}">

                            </div>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Project Description
                            </label>

                            <textarea class="form-control" rows="2" name="project_description">{{ $project->description }}</textarea>

                        </div>

                        <hr class="my-4">

                        <!-- Documents -->
                        <h6 class="text-primary fw-bold mb-3">
                            <i class="bi bi-file-earmark-arrow-up me-2"></i>
                            Update Documents
                        </h6>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <div class="border rounded p-3 h-100">

                                    <label class="form-label fw-semibold">
                                        Assessment
                                    </label>

                                    <input type="file" class="form-control" name="assessmentDocument">

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="border rounded p-3 h-100">

                                    <label class="form-label fw-semibold">
                                        Quotation
                                    </label>

                                    <input type="file" class="form-control" name="quotationDocument">

                                </div>

                            </div>

                            @if ($project->clients->first()->client_type === 'Commercial')
                                <div class="col-md-4">

                                    <div class="border rounded p-3 h-100">

                                        <label class="form-label fw-semibold">
                                            Contract
                                        </label>

                                        <input type="file" class="form-control" name="contractDocument">
                                    </div>

                                </div>
                            @endif

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">

                            Cancel

                        </button>

                        <button type="submit" class="btn btn-primary">

                            <i class="bi bi-check-lg me-1"></i>

                            Save Changes
                        </button>

                    </div>

                </form>

            </div>

        </div>
    </div>

    <!-- END OF EDIT PROJECT DETAILS MODAL -->

    <!-- Add Technician Report Modal -->
    <div class="modal fade" id="addTechnicianReportModal" tabindex="-1" aria-labelledby="addTechnicianReportModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form action="{{ route('super-admin.technician.reports.store', $project->project_id) }}" method="POST"
                enctype="multipart/form-data">
                @csrf

                <div class="modal-content">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addTechnicianReportModalLabel">
                            <i class="fas fa-file-alt me-2"></i>
                            Add Technician Report
                        </h5>

                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <!-- Report Type -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Report Type
                            </label>

                            <select class="form-select" name="report_type" required>
                                <option value="">Select Report Type</option>
                                <option value="progress">Progress Report</option>
                                <option value="incident">Incident Report</option>
                            </select>
                        </div>

                        <!-- Report Title -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Report Title
                            </label>

                            <input type="text" class="form-control" name="report_title"
                                placeholder="Enter report title" required>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Report Description
                            </label>

                            <textarea class="form-control" name="report_description" rows="5"
                                placeholder="Describe the work completed or incident..." required></textarea>
                        </div>

                        <!-- Upload Images -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Upload Images
                            </label>

                            <input type="file" class="form-control" name="images[]" id="reportImages"
                                accept="image/*" multiple required>

                            <small class="text-muted">
                                You may upload multiple images (JPG, PNG, JPEG).
                            </small>
                        </div>

                        <!-- Image Preview -->
                        <div class="row g-2" id="imagePreview"></div>

                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-save me-1"></i>
                            Submit Report
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>
    <!-- End of Add Technician Report Modal -->

    <!-- Add Task Modal -->
    <div class="modal fade" id="addTaskModal" tabindex="-1" aria-labelledby="addTaskModalLabel" aria-hidden="true">

        <div class="modal-dialog modal-xl">

            <form action="{{ route('super-admin.task.store', $project->project_id) }}" method="POST">
                @csrf

                <div class="modal-content">

                    <div class="modal-header bg-primary text-white">

                        <h5 class="modal-title">
                            <i class="bi bi-list-task me-2"></i>
                            Create Task
                        </h5>

                        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

                    </div>

                    <div class="modal-body">

                        <!-- Task Title -->
                        <div class="mb-3">

                            <label class="form-label fw-semibold">
                                Task Title
                            </label>

                            <input type="text" class="form-control" name="task_title" placeholder="Enter task title"
                                required>

                        </div>

                        <!-- Description -->
                        <div class="mb-4">

                            <label class="form-label fw-semibold">
                                Task Description
                            </label>

                            <textarea class="form-control" name="task_description" rows="4" placeholder="Describe the task..." required></textarea>

                        </div>

                        <!-- Dates -->
                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="form-label fw-semibold">
                                    Start Date
                                </label>

                                <input type="date" id="taskStartDate" name="start_date" class="form-control"
                                    min="{{ \Carbon\Carbon::parse($project->schedule->start_datetime)->format('Y-m-d') }}"
                                    max="{{ \Carbon\Carbon::parse($project->schedule->end_datetime)->format('Y-m-d') }}"
                                    required>

                            </div>

                            <div class="col-md-6 mb-3">

                                <label class="form-label fw-semibold">
                                    Due Date
                                </label>

                                <input type="date" id="taskDueDate" name="due_date" class="form-control"
                                    min="{{ \Carbon\Carbon::parse($project->schedule->start_datetime)->format('Y-m-d') }}"
                                    max="{{ \Carbon\Carbon::parse($project->schedule->end_datetime)->format('Y-m-d') }}"
                                    required>

                            </div>

                        </div>

                        <hr>

                        <label class="form-label fw-bold mb-3">
                            Assign To
                        </label>

                        <div class="row g-3">

                            @foreach ($project->projectTechnicians as $projectTechnician)
                                @php
                                    $technician = $projectTechnician->technician;
                                @endphp

                                <div class="col-md-4">

                                    <label class="w-100">

                                        <input type="radio" class="btn-check" name="technician_id"
                                            value="{{ $technician->technician_id }}" required>

                                        <div class="card technician-card h-100">

                                            <div class="card-body text-center">

                                                <div class="display-5 text-primary mb-2">

                                                    <i class="bi bi-person-circle"></i>

                                                </div>

                                                <h6 class="fw-bold mb-1">

                                                    {{ $technician->name }}

                                                </h6>

                                                <small class="text-muted">

                                                    {{ $technician->tasks_count ?? 0 }}
                                                    Active Tasks

                                                </small>

                                            </div>

                                        </div>

                                    </label>

                                </div>
                            @endforeach

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                            Cancel

                        </button>

                        <button type="submit" class="btn btn-primary">

                            <i class="bi bi-check-lg me-1"></i>

                            Create Task

                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>

    @foreach ($tasks as $task)
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

                            <label class="form-label fw-semibold">

                                Task Title

                            </label>

                            <input type="text" class="form-control" name="task_title"
                                value="{{ $task->task_title }}" {{ $task->status == 'completed' ? 'readonly' : '' }}>

                        </div>

                        {{-- Description --}}
                        <div class="mb-3">

                            <label class="form-label fw-semibold">

                                Description

                            </label>

                            <textarea class="form-control" rows="4" name="task_description"
                                {{ $task->status == 'completed' ? 'readonly' : '' }}>{{ $task->task_description }}</textarea>

                        </div>

                        <div class="row">

                            {{-- Start Date --}}
                            <div class="col-md-6">

                                <label class="form-label">

                                    Start Date

                                </label>

                                <input type="date" class="form-control" name="start_date"
                                    value="{{ $task->start_date }}"
                                    min="{{ \Carbon\Carbon::parse($project->schedule->start_datetime)->format('Y-m-d') }}"
                                    max="{{ \Carbon\Carbon::parse($project->schedule->end_datetime)->format('Y-m-d') }}"
                                    {{ $task->status == 'completed' ? 'readonly' : '' }}>

                            </div>

                            {{-- Due Date --}}
                            <div class="col-md-6">

                                <label class="form-label">

                                    Due Date

                                </label>

                                <input type="date" class="form-control" name="due_date"
                                    value="{{ $task->due_date }}"
                                    min="{{ \Carbon\Carbon::parse($project->schedule->start_datetime)->format('Y-m-d') }}"
                                    max="{{ \Carbon\Carbon::parse($project->schedule->end_datetime)->format('Y-m-d') }}"
                                    {{ $task->status == 'completed' ? 'readonly' : '' }}>

                            </div>

                        </div>

                        <hr>

                        <label class="form-label fw-bold">

                            Assign Technician

                        </label>

                        <div class="row g-3">

                            @foreach ($project->projectTechnicians as $projectTechnician)
                                @php
                                    $technician = $projectTechnician->technician;
                                @endphp

                                <div class="col-md-4">

                                    <label class="w-100">

                                        <input type="radio" class="btn-check" name="technician_id"
                                            value="{{ $technician->technician_id }}"
                                            {{ $task->technician_id == $technician->technician_id ? 'checked' : '' }}
                                            {{ $task->status == 'completed' ? 'disabled' : '' }}>

                                        <div class="card technician-card h-100">

                                            <div class="card-body text-center">

                                                <div class="display-5 text-primary">

                                                    <i class="bi bi-person-circle"></i>

                                                </div>

                                                <h6 class="fw-bold mt-2">

                                                    {{ $technician->name }}

                                                </h6>

                                                <small class="text-muted">

                                                    {{ $technician->tasks_count ?? 0 }}

                                                    Active Tasks

                                                </small>

                                            </div>

                                        </div>

                                    </label>

                                </div>
                            @endforeach

                        </div>
                        @if($task->status == 'completed')

    <hr>

    <h6 class="fw-bold mb-3">
        <i class="bi bi-images me-2"></i>
        Completion Images
    </h6>

    @if($task->images->count())

        <div class="row g-3">

            @foreach($task->images as $image)

                <div class="col-lg-4 col-md-6">

                    <a href="{{ asset('storage/' . $image->image_path) }}"
                        target="_blank">

                        <img
                            src="{{ asset('storage/' . $image->image_path) }}"
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

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                            Close

                        </button>

                        @if ($task->status != 'completed')
                            <button class="btn btn-primary">

                                <i class="bi bi-save me-1"></i>

                                Save Changes

                            </button>
                        @else
                        @endif

                    </div>

                </div>

                </form>

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

                            <p class="mb-1">

                                Are you sure you want to mark

                            </p>

                            <h5 class="fw-bold">

                                "{{ $task->task_title }}"

                            </h5>

                            <p class="text-muted mb-0">

                                as completed?

                            </p>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                            Cancel

                        </button>

                        <button type="submit" class="btn btn-success">

                            <i class="bi bi-check-lg me-1"></i>

                            Mark as Completed

                        </button>

                    </div>

                </div>

                </form>

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

                            <p class="mb-1">

                                This will permanently delete

                            </p>

                            <h5 class="fw-bold">

                                "{{ $task->task_title }}"

                            </h5>

                            <p class="text-danger mb-0">

                                This action cannot be undone.

                            </p>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                            Cancel

                        </button>

                        <button type="submit" class="btn btn-danger">

                            <i class="bi bi-trash me-1"></i>

                            Delete Task

                        </button>

                    </div>

                </div>

                </form>

            </div>

        </div>
    @endforeach


    @push('scripts')
        <script src="/js/super-admin/projectDetails.js"></script>
    @endpush
@endsection
