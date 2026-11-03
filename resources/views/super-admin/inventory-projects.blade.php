@extends('layouts.superadminNav')

@section('title', 'Projects')

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
    <link href="/css/inventory.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Projects</h4>
            <p class="text-secondary small mb-0">Open one to manage its materials.</p>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-4">
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
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            window.serverTable(document.getElementById("inventoryProjectsTable"), {
                autoWidth: false,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [[0, "desc"]],
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
