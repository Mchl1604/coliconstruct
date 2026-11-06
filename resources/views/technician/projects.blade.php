@extends('layouts.portalNav')

@section('title', 'My Projects')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    {{-- The phase setup alert below the heading. --}}
    <link href="/css/projectPhases.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">My Projects</h4>
            <p class="text-secondary small mb-0">The projects you are assigned to.</p>
        </div>

        <span class="badge bg-secondary">{{ $assignedCount }} assigned</span>
    </div>

    {{-- A lead has no dashboard, so what needs arranging is said here. Empty
         for a plain technician, who cannot set a phase structure up. --}}
    <x-phase-setup-alert :projects="$phaseSetupProjects" />

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">

            {{-- Same tabs the Super Admin projects table carries, counts and
                 all. Needing dates is derived rather than stored, so it gets
                 its own tab and is taken out of Pending and Ongoing; a hold is
                 a state the badge already prints, so it gets one too - held
                 work files itself under Pending otherwise, which is a tab it
                 does not belong in and a count it quietly inflates. --}}
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
                            <th>Phases</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>

                    {{-- Filled a page at a time by serverTable.js from
                         TechnicianPortalController::projectsTable(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            window.portalRoutes = {
                projectDetails: @json(route('technician.projects.details', ['project' => '__ID__'])),
            };
        </script>
        <script>
            window.portalRoutes.projectsTable = @json(route('technician.projects.table'));
        </script>
        <script src="/js/imagePreview.js"></script>
        <script src="/js/projectStatusTabs.js"></script>
        <script src="/js/technician/projects.js"></script>
    @endpush
@endsection
