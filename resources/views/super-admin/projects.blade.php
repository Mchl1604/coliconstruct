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

        <button type="button" class="btn btn-sm btn-primary"
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

                    <button type="button" class="nav-link" data-status-filter="completed">Completed</button>
                </li>

                <li class="nav-item">
                    <button type="button" class="nav-link" data-status-filter="cancelled">Cancelled</button>
                </li>
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
                            
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($projects as $project)
                            <tr data-status="{{ $project->status }}">
                                <td>{{ $project->project_id }}</td>
                                <td>{{ $project->reference_no }}</td>
                                <td>{{ $project->clients->first()->fullname ?? 'N/A' }}</td>
                                <td>{{ $project->clients->first()->client_type }}</td>
                                <td>
                                    {{ $project->projectTypes->pluck('type_name')->join(', ') ?: 'N/A' }}
                                </td>
                                <td>₱ {{ number_format($project->quotation, 2) }}</td>
                                <td>
                                    @if ($project->on_hold)
                                        <span class="badge bg-secondary">On Hold</span>
                                    @elseif ($project->status === 'pending')
                                        <span class="badge bg-warning">Pending</span>
                                    @elseif ($project->status === 'ongoing')
                                        <span class="badge bg-primary">Ongoing</span>
                                    @elseif ($project->status === 'completed')
                                        <span class="badge bg-success">Completed</span>
                                    @elseif ($project->status === 'cancelled')
                                        <span class="badge bg-danger">Cancelled</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <div class="projects-action-buttons">
                                        <button class="btn btn-sm btn-primary py-1 px-2"
                                            onclick="window.location='{{ route('super-admin.projects.show', $project->project_id) }}'">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        @if ($project->on_hold !== true)
                                            <button class="btn btn-sm btn-warning py-1 px-2" data-bs-toggle="modal"
                                                data-bs-target="#onHoldModal{{ $project->project_id }}">
                                                <i class="bi bi-pause"></i>
                                            </button>
                                        @endif
                                        @if ($project->on_hold === true)
                                            <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                                                data-bs-target="#resumeModal{{ $project->project_id }}">
                                                <i class="bi bi-play"></i>
                                            </button>
                                        @endif
                                        <button class="btn btn-sm btn-danger py-1 px-2">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <!-- ON HOLD MODAL -->
                            <div class="modal fade" id="onHoldModal{{ $project->project_id }}" tabindex="-1"
                                aria-labelledby="onHoldModalLabel{{ $project->project_id }}" aria-hidden="true">

                                <div class="modal-dialog">
                                    <div class="modal-content">

                                        <div class="modal-header">
                                            <h5 class="modal-title" id="onHoldModalLabel{{ $project->project_id }}">
                                                Put Project On Hold
                                            </h5>

                                            <button type="button" class="btn-close" data-bs-dismiss="modal">
                                            </button>
                                        </div>

                                        <div class="modal-body">
                                            Are you sure you want to put
                                            <strong>{{ $project->reference_no }}</strong>
                                            on hold?
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                Cancel
                                            </button>

                                            <form method="POST"
                                                action="{{ route('super-admin.projects.hold', $project->project_id) }}">

                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="btn btn-warning">
                                                    Put on Hold
                                                </button>
                                            </form>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            <!-- RESUME MODAL -->
                            <div class="modal fade" id="resumeModal{{ $project->project_id }}"
                                tabindex="-1" aria-labelledby="resumeModalLabel{{ $project->project_id }}"
                                aria-hidden="true">

                                <div class="modal-dialog">
                                    <div class="modal-content">

                                        <div class="modal-header">
                                            <h5 class="modal-title" id="resumeModalLabel{{ $project->project_id }}">
                                                Resume Project
                                            </h5>

                                            <button type="button" class="btn-close" data-bs-dismiss="modal">
                                            </button>
                                        </div>

                                        <div class="modal-body">
                                            Are you sure you want to resume
                                            <strong>{{ $project->reference_no }}</strong>?
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                Cancel
                                            </button>

                                            <form method="POST"
                                                action="{{ route('super-admin.projects.resume', $project->project_id) }}">

                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="btn btn-success">
                                                    Resume Project
                                                </button>
                                            </form>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        @endforeach


                    </tbody>

                </table>

            </div>

        </div>
    </div>


    @push('scripts')
        <script>
            $(function() {

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

                tabButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        tabButtons.forEach(function(item) {
                            item.classList.remove('active');
                        });

                        button.classList.add('active');

                        const status = button.getAttribute('data-status-filter');

                        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(
                            filterFn) {
                            return !filterFn._projectsStatusFilter;
                        });

                        const filterFn = function(settings, data, dataIndex) {
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
