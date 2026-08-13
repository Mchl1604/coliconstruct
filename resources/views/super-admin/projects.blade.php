@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
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
                            <tr data-status="{{ $project->status }}"
                                data-overdue="{{ $project->isOverdue() ? '1' : '0' }}">
                                <td>{{ $project->project_id }}</td>
                                <td>{{ $project->reference_no }}</td>
                                <td>
                                    @php
                                        $client = $project->clients->first();
                                    @endphp
                                    {{ $client?->client_type === 'Commercial' ? ($client->company_name ?? $client->fullname) : $client?->fullname ?? 'N/A' }}
                                </td>
                                <td>{{ $client?->client_type }}</td>
                                <td>
                                    @forelse ($project->projectTypes as $projectType)
                                        <span class="project-type-chip">{{ $projectType->type_name }}</span>
                                    @empty
                                        <span class="text-muted small">N/A</span>
                                    @endforelse
                                </td>
                                <td class="text-success fw-semibold">₱ {{ number_format($project->quotation, 2) }}</td>
                                <td>
                                    <x-project-status-badge :project="$project" />
                                </td>
                                <td class="text-center">
                                    @php
                                        $isReadOnly = in_array($project->status, ['completed', 'cancelled', 'archived'], true);
                                    @endphp
                                    <div class="projects-action-buttons">
                                        <button class="btn btn-sm btn-primary py-1 px-2"
                                            onclick="window.location='{{ route('super-admin.projects.show', $project->project_id) }}'">
                                            <i class="bi bi-eye"></i>
                                        </button>

                                        @if (! $isReadOnly)
                                            @if ($project->on_hold !== true && $project->status !== 'unscheduled')
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

                                            @if (in_array($project->status, ['pending', 'ongoing'], true))
                                                <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                                                    data-bs-target="#completeProjectModal{{ $project->project_id }}"
                                                    title="Complete Project">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            @endif

                                            @if ($canArchive)
                                                <button class="btn btn-sm btn-dark py-1 px-2" data-bs-toggle="modal"
                                                    data-bs-target="#archiveProjectModal{{ $project->project_id }}"
                                                    title="Archive Project">
                                                    <i class="bi bi-archive"></i>
                                                </button>
                                            @endif
                                        @endif
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

                            <!-- COMPLETE PROJECT MODAL -->
                            <div class="modal fade" id="completeProjectModal{{ $project->project_id }}" tabindex="-1"
                                aria-labelledby="completeProjectModalLabel{{ $project->project_id }}" aria-hidden="true">

                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">

                                        <form method="POST"
                                            action="{{ route('super-admin.projects.complete', $project->project_id) }}"
                                            enctype="multipart/form-data">
                                            @csrf

                                            <div class="modal-header bg-success text-white">
                                                <h5 class="modal-title" id="completeProjectModalLabel{{ $project->project_id }}">
                                                    <i class="bi bi-check-circle me-2"></i>
                                                    Complete Project &mdash; {{ $project->reference_no }}
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>

                                            <div class="modal-body">
                                                <p class="mb-3">
                                                    Are you sure you want to mark
                                                    <strong>{{ $project->reference_no }}</strong>
                                                    as completed? The project's schedule, technicians, and task
                                                    history will remain on record, but the project will become
                                                    view only.
                                                </p>

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Completion Date</label>
                                                    <input type="date" class="form-control" name="completion_date"
                                                        value="{{ now()->format('Y-m-d') }}" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Completion Summary</label>
                                                    <textarea class="form-control" name="completion_summary" rows="3"
                                                        placeholder="Summarize the work that was completed..." required></textarea>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Completion Remarks</label>
                                                    <textarea class="form-control" name="completion_remarks" rows="2"
                                                        placeholder="Any additional remarks (optional)"></textarea>
                                                </div>

                                                <div class="mb-1">
                                                    <label class="form-label fw-semibold">Upload Completion Photos</label>
                                                    <input type="file" class="form-control" name="completion_photos[]"
                                                        accept=".jpg,.jpeg,.png" multiple>
                                                    <div class="form-text">JPG, JPEG, or PNG. You can select multiple photos.</div>
                                                </div>
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    Cancel
                                                </button>
                                                <button type="submit" class="btn btn-success">
                                                    <i class="bi bi-check-lg me-1"></i>
                                                    Confirm Completion
                                                </button>
                                            </div>
                                        </form>

                                    </div>
                                </div>
                            </div>

                            <!-- ARCHIVE PROJECT MODAL -->
                            @if ($canArchive)
                            <div class="modal fade" id="archiveProjectModal{{ $project->project_id }}" tabindex="-1"
                                aria-labelledby="archiveProjectModalLabel{{ $project->project_id }}" aria-hidden="true">

                                <div class="modal-dialog">
                                    <div class="modal-content">

                                        <div class="modal-header">
                                            <h5 class="modal-title" id="archiveProjectModalLabel{{ $project->project_id }}">
                                                Archive Project
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            Archive <strong>{{ $project->reference_no }}</strong>? Its schedule and
                                            assigned technicians will be released, but all project information will
                                            be preserved and can be restored later.
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                Cancel
                                            </button>

                                            <form method="POST"
                                                action="{{ route('super-admin.projects.archive', $project->project_id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-dark">
                                                    <i class="bi bi-archive me-1"></i>
                                                    Archive Project
                                                </button>
                                            </form>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            @endif
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
                            const rowStatus = rowNode && rowNode.getAttribute('data-status');
                            const isOverdue = rowNode && rowNode.getAttribute('data-overdue') === '1';

                            if (status === 'overdue') {
                                return isOverdue;
                            }

                            if (status === 'pending') {
                                return !isOverdue && (rowStatus === 'pending' || rowStatus === 'unscheduled');
                            }

                            // Ongoing means "on track": overdue work has its
                            // own tab so the two don't double-count.
                            if (status === 'ongoing') {
                                return rowStatus === 'ongoing' && !isOverdue;
                            }

                            return rowStatus === status;
                        };

                        filterFn._projectsStatusFilter = true;
                        $.fn.dataTable.ext.search.push(filterFn);
                        table.draw();
                    });
                });

                // The dashboard's status chart links here with ?status=, so a
                // slice arrives on this page with its tab already chosen.
                const requestedStatus = new URLSearchParams(window.location.search).get('status');

                if (requestedStatus) {
                    const requestedTab = document.querySelector(
                        '[data-status-filter="' + requestedStatus + '"]'
                    );

                    if (requestedTab) {
                        requestedTab.click();
                    }
                }

            });
        </script>
    @endpush
@endsection