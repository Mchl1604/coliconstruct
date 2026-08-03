@extends('layouts.portalNav')

@section('title', 'My Projects')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">My Projects</h4>
            <p class="text-secondary small mb-0">The projects you are assigned to.</p>
        </div>

        <span class="badge bg-secondary">{{ $projects->count() }} assigned</span>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">

            {{-- Same tabs the Super Admin projects table carries. Overdue is
                 derived rather than stored, so it gets its own tab and is
                 taken out of Pending and Ongoing. --}}
            <ul class="nav nav-tabs projects-status-tabs mb-3 px-1"
                data-project-status-tabs="leadProjectsTable">
                <li class="nav-item">
                    <button type="button" class="nav-link active" data-status-filter="all">All</button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link" data-status-filter="pending">Pending</button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link" data-status-filter="ongoing">Ongoing</button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link" data-status-filter="overdue">
                        Overdue
                        @if ($overdueCount > 0)
                            <span class="badge badge-overdue ms-1">{{ $overdueCount }}</span>
                        @endif
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link" data-status-filter="completed">Completed</button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link" data-status-filter="cancelled">Cancelled</button>
                </li>
            </ul>

            <div class="table-responsive">
                <table id="leadProjectsTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Reference No.</th>
                            <th>Client</th>
                            <th>Client Type</th>
                            <th>Project Type</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        {{-- No blade fallback row here: a single colspan cell has fewer
                             cells than the header, which DataTables cannot parse. Its
                             own emptyTable message covers it. --}}
                        @foreach ($projects as $project)
                            @php
                                $client = $project->clients->first();
                            @endphp
                            <tr data-status="{{ $project->status }}"
                                data-overdue="{{ $project->isOverdue() ? '1' : '0' }}">
                                <td>{{ $project->reference_no }}</td>
                                <td>
                                    {{ $client?->client_type === 'Commercial'
                                        ? ($client->company_name ?? $client->fullname)
                                        : ($client?->fullname ?? 'N/A') }}
                                </td>
                                <td>{{ $client?->client_type ?? 'N/A' }}</td>
                                <td>
                                    @forelse ($project->projectTypes as $projectType)
                                        <span class="project-type-chip">{{ $projectType->type_name }}</span>
                                    @empty
                                        <span class="text-muted small">N/A</span>
                                    @endforelse
                                </td>
                                <td><x-project-status-badge :project="$project" /></td>
                                <td class="text-center">
                                    <div class="projects-action-buttons">
                                        <a class="btn btn-sm btn-primary py-1 px-2"
                                            href="{{ route('technician.lead.projects.show', $project->project_id) }}"
                                            title="View project">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </a>

                                        @if (in_array($project->status, ['pending', 'ongoing'], true) && ! $project->on_hold)
                                            <button type="button" class="btn btn-sm btn-success py-1 px-2"
                                                data-complete-project="{{ $project->project_id }}"
                                                data-project-reference="{{ $project->reference_no }}"
                                                title="Complete project">
                                                <i class="bi bi-check-lg" aria-hidden="true"></i>
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

    {{-- ============================ COMPLETE PROJECT MODAL ============================ --}}
    <div class="modal fade" id="completeProjectModal" tabindex="-1" aria-hidden="true" data-complete-project-modal>
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" data-complete-project-form enctype="multipart/form-data">
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
                    <h5 class="fw-bold mb-3" data-complete-project-reference>&nbsp;</h5>

                    <div class="text-secondary small py-2" data-complete-project-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Checking whether anything is still outstanding&hellip;
                    </div>

                    {{-- Everything that still stands in the way, listed rather than
                         collapsed into a single refusal. --}}
                    <div class="d-none" data-complete-project-blocked>
                        <div class="alert alert-warning mb-0">
                            <p class="fw-semibold mb-2">
                                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                This project cannot be completed yet.
                            </p>
                            <ul class="mb-0 ps-3" data-complete-project-blockers></ul>
                        </div>
                    </div>

                    <div class="d-none" data-complete-project-ready>
                        <p class="mb-3">
                            Every task on this project is completed. Marking it complete makes the
                            project view only and releases any dates still booked ahead.
                        </p>

                        @include('technician.lead.partials.completion-fields', ['suffix' => 'List'])
                    </div>

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-complete-project-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" data-complete-project-submit disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-spinner></span>
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                        Mark as Completed
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            window.leadRoutes = {
                projectDetails: @json(route('technician.lead.projects.details', ['project' => '__ID__'])),
                completeProject: @json(route('technician.lead.projects.complete', ['project' => '__ID__'])),
            };
        </script>
        <script src="/js/imagePreview.js"></script>
        <script src="/js/projectStatusTabs.js"></script>
        <script src="/js/technician/leadProjects.js"></script>
    @endpush
@endsection
