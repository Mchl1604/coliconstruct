@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Archived Projects</h4>
            <p class="text-secondary small mb-0">Archived projects are preserved but removed from the active list.</p>
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
                            <th>Project Name</th>
                            <th>Client</th>
                            <th>Address</th>
                            <th>Archived Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($projects as $project)
                            <tr>
                                <td>{{ $project->name }}</td>
                                <td>{{ $project->clients->first()->fullname ?? 'N/A' }}</td>
                                <td>{{ $project->address }}</td>
                                <td>
                                    {{ $project->archived_at ? \Carbon\Carbon::parse($project->archived_at)->format('M d, Y') : 'N/A' }}
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                                        data-bs-target="#restoreProjectModal{{ $project->project_id }}">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                                        Restore
                                    </button>
                                </td>
                            </tr>

                            <!-- RESTORE PROJECT MODAL -->
                            <div class="modal fade" id="restoreProjectModal{{ $project->project_id }}" tabindex="-1"
                                aria-labelledby="restoreProjectModalLabel{{ $project->project_id }}" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">

                                        <div class="modal-header">
                                            <h5 class="modal-title" id="restoreProjectModalLabel{{ $project->project_id }}">
                                                Restore Project
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            Restore <strong>{{ $project->name }}</strong>? It will move back into the
                                            active Projects list as <strong>Not Yet Scheduled</strong> and must be
                                            scheduled and assigned technicians again.
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                Cancel
                                            </button>

                                            <form method="POST"
                                                action="{{ route('super-admin.projects.restore', $project->project_id) }}">
                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="btn btn-success">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>
                                                    Restore Project
                                                </button>
                                            </form>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No archived projects.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            $(function() {
                $('#archivedProjectsTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [10, 25, 50, 100],
                    info: false,
                    language: {
                        search: "",
                        searchPlaceholder: "Search archived projects..."
                    }
                });
            });
        </script>
    @endpush
@endsection
