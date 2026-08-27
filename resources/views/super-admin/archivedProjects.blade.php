@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/restoreConflicts.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
                                    {{ \App\Support\BusinessTime::format($project->archived_at, \App\Support\BusinessTime::DATE, 'N/A') }}
                                </td>
                                <td>{{ $project->archivedByUser?->fullName() ?? '—' }}</td>
                                <td class="text-center">
                                    <div class="projects-action-buttons">
                                        {{-- The same Project Details page every other
                                             listing opens, and the same blue View button
                                             the active Projects table draws. Reading an
                                             archived project does not take it out of the
                                             archive: nothing about the row changes, and
                                             Restore below is still the only way back into
                                             the active list. --}}
                                        <a class="btn btn-sm btn-primary py-1 px-2"
                                            href="{{ route('super-admin.projects.show', $project->project_id) }}"
                                            title="View Project Details">
                                            <i class="bi bi-eye me-1"></i>
                                            View
                                        </a>

                                        <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                                            data-bs-target="#restoreProjectModal{{ $project->project_id }}">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                                            Restore
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- RESTORE PROJECT MODALS

         Kept outside the table rather than beside each row. DataTables owns
         the rows in that tbody - it empties and rebuilds them on every page,
         sort and search - and anything sitting between them that is not a
         <tr> is dropped the first time it redraws, taking the dialog its own
         Restore button points at with it. --}}
    @foreach ($projects as $project)
        <div class="modal fade" id="restoreProjectModal{{ $project->project_id }}" tabindex="-1"
            aria-labelledby="restoreProjectModalLabel{{ $project->project_id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    @php
                        // What it will come back as. Null for anything
                        // archived before archiving preserved state:
                        // there is no schedule or team behind those, so
                        // Unscheduled is the honest answer and the one
                        // they have always restored to.
                        $returnsAs = $project->statusToRestore();
                    @endphp

                    <div class="modal-header">
                        <h5 class="modal-title" id="restoreProjectModalLabel{{ $project->project_id }}">
                            Restore {{ $project->reference_no ?? $project->name }}?
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    {{-- One line. Whether the calendar allows it is not a
                         warning to read here - it is a question with an
                         answer, asked the moment Restore is pressed, and
                         shown in full in the Schedule Conflict dialog when
                         the answer is no. --}}
                    <div class="modal-body">
                        @if ($returnsAs)
                            It returns as
                            <strong>{{ \App\Models\Project::statusLabelFor($returnsAs) }}</strong>
                            with its original schedule and team.
                        @else
                            It returns as <strong>Unscheduled</strong> - this project was
                            archived before archiving kept schedules, so its dates and team
                            must be set again.
                        @endif

                        <div class="alert alert-danger mt-3 mb-0 d-none" role="alert"
                            data-recovery-error></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>

                        {{-- Sent with fetch rather than as a plain submit so
                             a refused restore can open the Schedule Conflict
                             dialog with the clash in it, rather than bouncing
                             the page and reducing it to a toast. This is
                             still a real form: a browser running no script
                             submits it, and the endpoint answers both. --}}
                        <form method="POST" data-recovery-form
                            data-project-id="{{ $project->project_id }}"
                            data-reference="{{ $project->reference_no ?? $project->name }}"
                            data-conflicts-url="{{ route('super-admin.projects.restore-conflicts', $project->project_id) }}"
                            data-recovery-failure="Unable to restore project. Nothing was changed."
                            action="{{ route('super-admin.projects.restore', $project->project_id) }}">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-success" data-recovery-submit>
                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                Restore Project
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    @endforeach

    <x-schedule-conflict-modal />

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="/js/super-admin/scheduleRecovery.js"></script>
    @endpush

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
