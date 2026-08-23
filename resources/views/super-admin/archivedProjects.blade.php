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

                    <tbody>
                        {{-- No blade fallback row here: a single colspan cell has
                             fewer cells than the header, which DataTables cannot
                             parse ("Requested unknown parameter"). Its own
                             emptyTable message covers it (see the init below). --}}
                        @foreach ($projects as $project)
                            @php
                                $client = $project->clients->first();
                                // The same rule the active Projects table uses:
                                // a company is named by its company, a
                                // homeowner by their name.
                                $clientName =
                                    $client?->client_type === 'Commercial'
                                        ? $client->company_name ?: $client->fullname
                                        : $client?->fullname;
                            @endphp

                            <tr>
                                <td>{{ $project->displayCode() }}</td>
                                <td>{{ $project->reference_no ?? 'N/A' }}</td>
                                <td>{{ $clientName ?: 'N/A' }}</td>
                                <td>{{ $client?->client_type ?? 'N/A' }}</td>
                                <td>
                                    @forelse ($project->projectTypes as $projectType)
                                        <span class="project-type-chip">{{ $projectType->type_name }}</span>
                                    @empty
                                        <span class="text-muted small">N/A</span>
                                    @endforelse
                                </td>
                                <td class="text-success fw-semibold">
                                    &#8369; {{ number_format((float) $project->quotation, 2) }}
                                </td>
                                <td data-order="{{ $project->archived_at?->timestamp ?? 0 }}">
                                    {{ \App\Support\BusinessTime::format($project->archived_at, 'M d, Y', 'N/A') }}
                                </td>
                                <td>{{ $project->archivedByUser?->fullName() ?? '—' }}</td>
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
                                            Restore <strong>{{ $project->reference_no ?? $project->name }}</strong>?
                                            It returns as <strong>Unscheduled</strong> - its schedule and
                                            technicians must be set again.
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
                        @endforeach
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
                    // Most recently archived first, read from the timestamp on
                    // the cell rather than from the formatted date.
                    order: [
                        [6, 'desc']
                    ],
                    columnDefs: [{
                        targets: -1,
                        orderable: false
                    }, {
                        // A project ID is a label, not a quantity: without
                        // this DataTables types it as numeric and right-aligns
                        // it. Ordering stays numeric.
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
