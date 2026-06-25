@extends('layouts.superadminNav')

@push('styles')
<link href="/css/super-admin/projects.css" rel="stylesheet">
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-1">Projects</h4>
        <p class="text-secondary small mb-0">Manage project records.</p>
    </div>

    <button type="button"
        class="btn btn-sm btn-primary"
        onclick="window.location='{{ route('super-admin.projects.create') }}'">
    <i class="bi bi-plus-lg me-1"></i>
    Add Project
</button>
</div>

<div class="card shadow-sm border-0 rounded-2">
    <div class="card-body p-2">

        <ul class="nav nav-tabs projects-status-tabs mb-3 px-1">
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
                <button type="button" class="nav-link" data-status-filter="cancelled">Cancelled</button>
            </li>
        </ul>

        <div class="table-responsive">

            <table id="projectsTable"
                class="table table-hover table-striped align-middle mb-0">

                <thead class="table-info">
                    <tr>
                        <th>Project ID</th>
                        <th>Reference No.</th>
                        <th>Client</th>
                        <th>Project Type</th>
                        <th>Quotation</th>
                        <th>Status</th>
                        <th>Timeline</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <tr data-status="ongoing">
                        <td>PRJ-001</td>
                        <td>REF-2026-001</td>
                        <td>Juan Dela Cruz</td>
                        <td>Installation</td>
                        <td>₱ 50,000.00</td>

                        <td>
                            <span class="badge bg-success">
                                Ongoing
                            </span>
                        </td>

                        <td>Jun 20, 2026 - Jul 20, 2026</td>

                        <td class="text-center">
                            <div class="projects-action-buttons">
                                <button class="btn btn-sm btn-primary py-1 px-2">
                                    <i class="bi bi-eye"></i>
                                </button>

                                <button class="btn btn-sm btn-warning py-1 px-2">
                                    <i class="bi bi-pause"></i>
                                </button>

                                <button class="btn btn-sm btn-danger py-1 px-2">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

    </div>
</div>

@push('scripts')
<script>
$(function () {

    const table = $('#projectsTable').DataTable({
        responsive: true,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        info: false,
        language: {
            search: "",
            searchPlaceholder: "Search projects..."
        }
    });

    const tabButtons = document.querySelectorAll('[data-status-filter]');

    tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            tabButtons.forEach(function (item) {
                item.classList.remove('active');
            });

            button.classList.add('active');

            const status = button.getAttribute('data-status-filter');

            $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function (filterFn) {
                return !filterFn._projectsStatusFilter;
            });

            const filterFn = function (settings, data, dataIndex) {
                if (settings.nTable.id !== 'projectsTable') {
                    return true;
                }

                if (status === 'all') {
                    return true;
                }

                const rowNode = table.row(dataIndex).node();
                return rowNode && rowNode.getAttribute('data-status') === status;
            };

            filterFn._projectsStatusFilter = true;
            $.fn.dataTable.ext.search.push(filterFn);
            table.draw();
        });
    });

});
</script>
@endpush

@endsection
