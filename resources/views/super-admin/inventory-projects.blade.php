@extends('layouts.superadminNav')

@section('title', 'Projects')

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
    <link href="/css/inventory.css" rel="stylesheet">
    <link href="/css/super-admin/projects.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Projects</h4>
            <p class="text-secondary small mb-0">Open one to manage its materials.</p>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            {{-- The same tabs, and counts, as the office's Projects page -
                 see Project::statusTabs(). --}}
            <ul class="nav nav-tabs projects-status-tabs mb-3 px-1"
                data-project-status-tabs="inventoryProjectsTable">
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
                <table class="table table-hover align-middle mb-0" id="inventoryProjectsTable"
                    data-server-table-url="{{ route('inventory.projects.table') }}">
                    <thead class="table-info">
                        <tr>
                            <th>Reference</th>
                            <th>Project</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    {{-- Filled a page at a time by serverTable.js from
                         InventoryController::projectsTable(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="/js/projectStatusTabs.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            window.serverTable(document.getElementById("inventoryProjectsTable"), {
                params: function () {
                    return { tab: window.projectStatusTabs.current("inventoryProjectsTable") };
                },
                autoWidth: false,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [[3, "asc"], [0, "desc"]],
                columnDefs: [{ targets: [2, 4], orderable: false }],
                language: {
                    search: "",
                    searchPlaceholder: "Search projects...",
                    emptyTable: "No projects yet.",
                    zeroRecords: "Nothing matches.",
                },
            });
        });
    </script>
@endpush
