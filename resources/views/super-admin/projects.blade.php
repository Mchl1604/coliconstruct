@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    {{-- The Schedule Conflict dialog a refused Resume opens, and the date
         pickers inside it. Shared with the archive's Restore, which is the
         same dialog - see scheduleRecovery.js. --}}
    <link href="/css/super-admin/restoreConflicts.css" rel="stylesheet">
@endpush

@section('content')
    @php
        // Archiving a project - and reading the archive - belongs to the Super
        // Admin. An Admin creates, views, edits and closes projects.
        $canArchive = (bool) auth()->user()?->isSuperAdmin();
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Projects</h4>
            <p class="text-secondary small mb-0">Manage project records.</p>
        </div>

        <div class="d-flex gap-2">
            @if ($canArchive)
                <button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="window.location='{{ route('super-admin.projects.archived') }}'">
                    <i class="bi bi-archive me-1"></i>
                    View Archived Projects
                </button>
            @endif

            <button type="button" class="btn btn-sm btn-primary"
                onclick="window.location='{{ route('super-admin.projects.create') }}'">
                <i class="bi bi-plus-lg me-1"></i>
                Add Project
            </button>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">

            {{-- Every tab carries its count, in the pattern Needs
                 Rescheduling and On Hold already used. The figures come from
                 Project::tabCounts(), which groups by the very method each row
                 is labelled with, so a badge can never promise more rows than
                 its tab shows.

                 A hold is a state the badge already prints, so the table has to
                 be able to list it. Held work files itself under Pending
                 otherwise - its stored status is Unscheduled - which is a tab it
                 does not belong in and a count it quietly inflates.

                 There is no Unscheduled tab. Work that was never booked and
                 work that has run out of days are the same job to whoever is
                 reading this table, so both sit under Needs Rescheduling -
                 while each row keeps its own badge, so an unscheduled project
                 still reads Unscheduled. See Project::needsScheduling().

                 The last few tabs are the attention ones, drawn only while they
                 hold something: they answer what needs doing rather than what
                 state work is in, and they are where the dashboard's Urgent
                 Actions land. --}}
            <ul class="nav nav-tabs projects-status-tabs mb-3 px-1"
                data-project-status-tabs="projectsTable">
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

                <table id="projectsTable" class="table table-hover table-striped align-middle mb-0">

                    <thead class="table-info">
                        <tr>
                            <th>Project ID</th>
                            <th>Reference No.</th>
                            <th>Client</th>
                            <th>Client Type</th>
                            <th>Project Type</th>
                            <th>Quotation</th>
                            <th>Status</th>
                            <th>Phases</th>

                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    {{-- Filled a page at a time by serverTable.js from
                         ProjectController::table() - see the init below. --}}
                    <tbody></tbody>

                </table>

            {{-- The dialogs behind the rows on screen, swapped in with every
                 page - see super-admin/partials/project-dialogs. Outside the
                 table: a <div> is not valid inside a <tbody>. --}}
            <div data-projects-dialogs></div>


            </div>

        </div>
    </div>


    <x-schedule-conflict-modal />

    @push('scripts')
        <script src="/js/super-admin/scheduleRecovery.js"></script>
    @endpush

    @push('scripts')
        {{-- The tabs themselves: the ?status= deep link the dashboard's
             figures use, and the memory that brings somebody back to the tab
             they left. Shared with the technician portal's My Projects rather
             than written twice. Before the table, which asks it which tab to
             fetch. --}}
        <script src="/js/projectStatusTabs.js"></script>

        <script>
            $(function() {

                // One page of rows per request, filtered by the open tab on
                // the server - see ProjectController::table().
                window.serverTable('#projectsTable', {
                    url: @json(route('super-admin.projects.table')),
                    extras: '[data-projects-dialogs]',
                    params: function() {
                        return {
                            tab: window.projectStatusTabs.current('projectsTable')
                        };
                    },
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [10, 25, 50, 100],
                    info: false,
                    // In the order the tabs are read, newest first within
                    // each - see Project::tabRank().
                    order: [
                        [6, 'asc'],
                        [0, 'desc']
                    ],
                    columnDefs: [{
                        // A project ID is a label, not a quantity.
                        targets: 0,
                        className: 'dt-left'
                    }, {
                        // Phases and the buttons have no order of their own.
                        targets: [7, -1],
                        orderable: false
                    }],
                    language: {
                        search: "",
                        searchPlaceholder: "Search projects...",
                        emptyTable: "No projects found.",
                        zeroRecords: "No projects match your search."
                    }
                });

            });
        </script>
    @endpush
@endsection