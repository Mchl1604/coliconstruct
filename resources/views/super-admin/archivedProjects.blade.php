@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/restoreConflicts.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Archived Projects</h4>
            <p class="text-secondary small mb-0">Kept, but hidden from active projects.</p>
        </div>

        <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="window.location='{{ route('super-admin.projects') }}'">
            <i class="bi bi-arrow-left me-1"></i>
            Back to Projects
        </button>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table id="archivedProjectsTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Project ID</th>
                            <th>Reference No.</th>
                            <th>Client</th>
                            <th>Client Type</th>
                            <th>Project Type</th>
                            <th>Quotation</th>
                            <th>Archived Date</th>
                            <th>Archived By</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    {{-- Filled a page at a time by serverTable.js from
                         ProjectController::archivedTable(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- The Restore dialogs for the rows on screen, swapped in with every
         page. Kept outside the table: DataTables owns the rows in that
         tbody, and a <div> is not valid inside one. --}}
    <div data-archived-projects-dialogs></div>

    <x-schedule-conflict-modal />

    @push('scripts')
        <script src="/js/super-admin/scheduleRecovery.js"></script>
    @endpush

    @push('scripts')
        <script>
            $(function() {
                // One page of the archive per request - see
                // ProjectController::archivedTable().
                window.serverTable('#archivedProjectsTable', {
                    url: @json(route('super-admin.projects.archived.table')),
                    extras: '[data-archived-projects-dialogs]',
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [10, 25, 50, 100],
                    info: false,
                    // Most recently archived first.
                    order: [
                        [6, 'desc']
                    ],
                    columnDefs: [{
                        targets: -1,
                        orderable: false
                    }, {
                        // A project ID is a label, not a quantity.
                        targets: 0,
                        className: 'dt-left'
                    }],
                    language: {
                        search: "",
                        searchPlaceholder: "Search archived projects...",
                        emptyTable: "No archived projects.",
                        zeroRecords: "No archived projects match your search."
                    }
                });
            });
        </script>
    @endpush
@endsection
