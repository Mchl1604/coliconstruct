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

            {{-- Same tabs the Super Admin projects table carries, counts and
                 all. Overdue is derived rather than stored, so it gets its own
                 tab and is taken out of Pending and Ongoing; a hold is a state
                 the badge already prints, so it gets one too - held work files
                 itself under Pending otherwise, which is a tab it does not
                 belong in and a count it quietly inflates. --}}
            <ul class="nav nav-tabs projects-status-tabs mb-3 px-1"
                data-project-status-tabs="portalProjectsTable">
                @foreach ($statusTabs as $tab)
                    <li class="nav-item">
                        <button type="button"
                            class="nav-link {{ $tab['key'] === 'all' ? 'active' : '' }}"
                            data-status-filter="{{ $tab['key'] }}">
                            {{ $tab['label'] }}
                            <span class="badge {{ $tab['badge'] }} ms-1">{{ $tab['count'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="table-responsive">
                <table id="portalProjectsTable" class="table table-hover table-striped align-middle mb-0">
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
                                // Somebody on this crew can no longer sign in.
                                // Flagged on the row itself, exactly as the
                                // administrative table flags it, so the lead
                                // does not have to open every project to find
                                // the one that is short-handed.
                                $needsRecrew = $flagsInactiveCrew && $project->needsRecrew();
                                // Exactly as the administrative table decides
                                // it - Project::isActiveToday() - so a lead and
                                // an administrator looking at the same project
                                // see the same flag.
                                $isActiveToday = $project->isActiveToday();
                            @endphp
                            <tr data-tab="{{ $project->tabKey() }}"
                                data-status="{{ $project->status }}"
                                data-overdue="{{ $project->isOverdue() ? '1' : '0' }}"
                                data-on-hold="{{ $project->on_hold ? '1' : '0' }}"
                                data-active-today="{{ $isActiveToday ? '1' : '0' }}"
                                class="{{ $isActiveToday ? 'project-row-active-today' : '' }} {{ $needsRecrew ? 'project-row-needs-recrew' : '' }}">
                                <td>
                                    {{ $project->reference_no }}

                                    <x-project-active-today-flag :project="$project" />

                                    @if ($needsRecrew)
                                        <span class="project-recrew-flag"
                                            title="{{ $project->hasLead()
                                                ? $project->inactiveCrewNames().' can no longer sign in. Open the project to move their tasks, and ask an administrator to update the team.'
                                                : 'This project has no lead technician. Ask an administrator to assign one.' }}">
                                            <i class="bi bi-person-exclamation" aria-hidden="true"></i>
                                            {{ $project->recrewFlagLabel() }}
                                        </span>
                                    @endif
                                </td>
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
                                            href="{{ route('technician.projects.show', $project->project_id) }}"
                                            title="View project">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </a>

                                        {{-- Only work that is actually under
                                             way. A Pending, Unscheduled or
                                             paused project has had nobody on
                                             site yet, so there is nothing to
                                             close out and no button offered -
                                             see Project::isCompletable(). --}}
                                        @if ($canCloseProjects && $project->isCompletable())
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
                            All tasks are complete. Marking it complete makes the project view only.
                        </p>

                        @include('technician.partials.completion-fields', ['suffix' => 'List'])
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
            window.portalRoutes = {
                projectDetails: @json(route('technician.projects.details', ['project' => '__ID__'])),
                completeProject: @json(route('technician.projects.complete', ['project' => '__ID__'])),
            };
        </script>
        <script src="/js/imagePreview.js"></script>
        <script src="/js/projectStatusTabs.js"></script>
        <script src="/js/technician/projects.js"></script>
    @endpush
@endsection
