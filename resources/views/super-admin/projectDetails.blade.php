@extends('layouts.superadminNav')

@section('content')
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

                    @if($project->clients->first()->client_type === 'Commercial')
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

                        <!-- Dropdown -->
                        <div class="d-flex justify-content-end mb-3">

                            <select class="form-select w-auto">

                                <option selected>
                                    All Reports
                                </option>

                                <option>
                                    Progress Report
                                </option>

                                <option>
                                    Incident Report
                                </option>

                            </select>

                        </div>

                        <!-- Report Card -->
                        <div class="card border-primary-subtle bg-light mb-3">

                            <div class="card-body">

                                <small class="text-muted">
                                    Date
                                </small>

                                <h4 class="fw-bold">
                                    Apr 22, 2026
                                </h4>

                                <div class="mt-3">

                                    <small class="text-muted">
                                        Description
                                    </small>

                                    <p>
                                        Electrical rough-ins were completed for the
                                        remaining units and final checks were logged.
                                    </p>

                                </div>

                                <small class="text-muted">
                                    Pictures
                                </small>

                                <div class="row mt-2">

                                    <div class="col-md-3">

                                        <img src="https://placehold.co/300x220" class="img-fluid rounded border">

                                    </div>

                                </div>

                            </div>

                        </div>

                        <!-- Another Report -->

                        <div class="card border-primary-subtle bg-light">

                            <div class="card-body">

                                <small class="text-muted">
                                    Date
                                </small>

                                <h4 class="fw-bold">
                                    Apr 20, 2026
                                </h4>

                                <div class="mt-3">

                                    <small class="text-muted">
                                        Description
                                    </small>

                                    <p>
                                        Refrigerant line pressure testing passed and
                                        insulation wrap was applied to the new runs.
                                    </p>

                                </div>

                                <small class="text-muted">
                                    Pictures
                                </small>

                                <div class="row mt-2 g-3">

                                    <div class="col-md-3">
                                        <img src="https://placehold.co/300x220" class="img-fluid rounded border">
                                    </div>

                                    <div class="col-md-3">
                                        <img src="https://placehold.co/300x220" class="img-fluid rounded border">
                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- Tasks -->
                    <div class="tab-pane fade" id="tasks">

                        <div class="table-responsive">

                            <table class="table align-middle">

                                <thead>

                                    <tr>

                                        <th>Task</th>
                                        <th>Assigned To</th>
                                        <th>Status</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <tr>

                                        <td>Install Indoor Units</td>

                                        <td>Tech. Carl Dominguez</td>

                                        <td>
                                            <span class="badge bg-success">
                                                Completed
                                            </span>
                                        </td>

                                    </tr>

                                    <tr>

                                        <td>Pressure Testing</td>

                                        <td>Tech. Anne Mendoza</td>

                                        <td>
                                            <span class="badge bg-warning text-dark">
                                                Ongoing
                                            </span>
                                        </td>

                                    </tr>

                                    <tr>

                                        <td>Final Inspection</td>

                                        <td>Tech. Lito Ramos</td>

                                        <td>
                                            <span class="badge bg-secondary">
                                                Pending
                                            </span>
                                        </td>

                                    </tr>

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        </div>
    </div>

    <!-- EDIT PROJECT DETAILS MODAL -->
   <div class="modal fade" id="editProjectDetailsModal" tabindex="-1"
    aria-labelledby="editProjectDetailsModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-height: calc(100vh - 3rem);">

        <div class="modal-content border-0 shadow d-flex flex-column" style="max-height: calc(100vh - 3rem);">

            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="editProjectDetailsModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>
                    Edit Project Details
                </h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form class="d-flex flex-column flex-grow-1 overflow-hidden" action="{{ route('super-admin.projects.update', $project->project_id) }}" method="POST" enctype="multipart/form-data">
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
                            <input type="text"
                                class="form-control"
                                name="first_name"
                                value="{{ $project->clients->first()->firstname ?? '' }}">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Middle Initial</label>
                            <input type="text"
                                maxlength="1"
                                class="form-control text-center"
                                name="middle_initial"
                                value="{{ $project->clients->first()->middlename ?? '' }}">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">Last Name</label>
                            <input type="text"
                                class="form-control"
                                name="last_name"
                                value="{{ $project->clients->first()->surname ?? '' }}">
                        </div>

                    </div>

                    @if ($project->clients->first()->client_type === 'Commercial')

                        <div class="mt-3">
                            <label class="form-label">Company Name</label>

                            <input type="text"
                                class="form-control"
                                name="company_name"
                                value="{{ $project->clients->first()->company_name ?? '' }}">
                        </div>

                    @endif


                    <div class="mt-4">

                        <label class="form-label">Address</label>

                        <input type="text"
                            class="form-control"
                            name="address"
                            value="{{ $project->address }}">

                    </div>

                    <div class="row g-3 mt-1">

                        <div class="col-md-6">
                            <label class="form-label">
                                Contact Number
                            </label>

                            <input type="text"
                                class="form-control"
                                name="contact_number"
                                value="{{ $project->clients->first()->contact_number ?? '' }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Email Address
                            </label>

                            <input type="email"
                                class="form-control"
                                name="email_address"
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

        <button class="btn btn-outline-primary dropdown-toggle"
                type="button"
                data-bs-toggle="dropdown">

            <i class="bi bi-plus-lg me-1"></i>
            Add Project Type

        </button>

        <ul class="dropdown-menu">

    @foreach(
        $projectTypes->reject(fn($type) => $project->projectTypes->contains('type_id', $type->type_id))
        as $type
    )

        <li>
            <button
                type="button"
                class="dropdown-item add-project-type"
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

                <button type="button"
                        class="btn-close btn-close-white ms-2 remove-project-type"
                        data-type-id="{{ $type->type_id }}"
                        aria-label="Remove">
                </button>

            </span>
        @endforeach

    </div>

    

    <!-- Hidden inputs submitted with the form -->
    <div id="projectTypesInputs">

        @foreach($project->projectTypes as $type)
            <input type="hidden"
                   name="project_types[]"
                   value="{{ $type->type_id }}"
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

                            <input type="number"
                                class="form-control"
                                name="quotation"
                                value="{{ $project->quotation }}">

                        </div>

                    </div>

                     <div class="mb-3">

                        <label class="form-label">
                            Project Description
                        </label>

                        <textarea
                            class="form-control"
                            rows="2"
                            name="project_description">{{ $project->description }}</textarea>

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

                                <input type="file"
                                    class="form-control"
                                    name="assessmentDocument">

                            </div>

                        </div>

                        <div class="col-md-4">

                            <div class="border rounded p-3 h-100">

                                <label class="form-label fw-semibold">
                                    Quotation
                                </label>

                                <input type="file"
                                    class="form-control"
                                    name="quotationDocument">

                            </div>

                        </div>

                        @if ($project->clients->first()->client_type === 'Commercial')

                            <div class="col-md-4">

                                <div class="border rounded p-3 h-100">

                                    <label class="form-label fw-semibold">
                                        Contract
                                    </label>

                                    <input type="file"
                                        class="form-control"
                                        name="contractDocument">
                                </div>

                            </div>

                        @endif

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary">

                        <i class="bi bi-check-lg me-1"></i>

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>
</div>
@push('scripts')
  <script src="/js/super-admin/projectDetails.js"></script>
@endpush
    @endsection
