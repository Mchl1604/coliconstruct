@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    {{-- The Schedule Conflict dialog a refused Resume opens, and the date
         pickers inside it. Shared with the archive's Restore, which is the
         same dialog - see scheduleRecovery.js. --}}
    <link href="/css/super-admin/restoreConflicts.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

            {{-- Every tab carries its count, in the pattern Overdue and On
                 Hold already used. The figures come from Project::tabCounts(),
                 which groups by the very method each row is labelled with, so
                 a badge can never promise more rows than its tab shows.

                 A hold is a state the badge already prints, so the table has to
                 be able to list it. Held work files itself under Pending
                 otherwise - its stored status is Unscheduled - which is a tab it
                 does not belong in and a count it quietly inflates.

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

                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($projects as $project)
                            @php
                                // Somebody on this team can no longer sign in.
                                // Surfaced on the row itself rather than only
                                // inside Project Details: an administrator
                                // should not have to open every project to find
                                // the ones that need re-crewing.
                                $needsRecrew = $project->needsRecrew();
                                // A crew is on this job today. Derived from the
                                // booked ranges rather than the status column -
                                // see Project::isActiveToday(), the one rule all
                                // four portals ask.
                                $isActiveToday = $project->isActiveToday();
                            @endphp
                            <tr data-tab="{{ $project->tabKey() }}"
                                data-status="{{ $project->status }}"
                                data-overdue="{{ $project->isOverdue() ? '1' : '0' }}"
                                data-on-hold="{{ $project->on_hold ? '1' : '0' }}"
                                data-active-today="{{ $isActiveToday ? '1' : '0' }}"
                                {{-- The attention tabs this row also answers -
                                     overlapping, so a list rather than a second
                                     status. See Project::attentionTabKeys(). --}}
                                data-tab-extra="{{ implode(' ', $project->attentionTabKeys()) }}"
                                class="{{ $isActiveToday ? 'project-row-active-today' : '' }} {{ $needsRecrew ? 'project-row-needs-recrew' : '' }}">
                                <td>
                                    {{ $project->displayCode() }}

                                    <x-project-active-today-flag :project="$project" />

                                    @if ($needsRecrew)
                                        <span class="project-recrew-flag"
                                            title="{{ $project->hasLead()
                                                ? $project->inactiveCrewNames().' can no longer sign in. Open the project to reassign the work.'
                                                : 'This project has no lead technician. Open the project and choose one in Assigned Team.' }}">
                                            <i class="bi bi-person-exclamation" aria-hidden="true"></i>
                                            {{ $project->recrewFlagLabel() }}
                                        </span>
                                    @endif
                                </td>
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

                                    {{-- A project waiting on a reply that cannot arrive
                                         reads exactly like one whose client is still
                                         thinking about it, and for the length of the
                                         confirmation window it sat here indistinguishable
                                         from it. This is the difference, said in one word
                                         beside the status rather than by changing it: the
                                         project really is Awaiting Client Confirmation,
                                         and nothing about the underlying state is being
                                         bent to carry a display concern.

                                         It disappears on its own the moment the client
                                         registers - see CompletionConfirmability. --}}
                                    @if ($project->isAwaitingClientConfirmation()
                                        && ($confirmability[$project->project_id] ?? null) === \App\Services\CompletionConfirmability::UNREACHABLE)
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle d-inline-flex align-items-center gap-1 mt-1"
                                            title="No registered client can confirm this project online. It completes automatically when the window ends, unless an administrator records a confirmation given another way.">
                                            <i class="bi bi-person-slash" aria-hidden="true"></i>
                                            No client to confirm
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @php
                                        // Asked of the model rather than restated here, which is
                                        // how this list came to be missing a status the rest of
                                        // the system already treats as locked.
                                        $isReadOnly = $project->isReadOnly();
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

                                            {{-- Only work that is actually under way. An
                                                 Unscheduled or paused project has had nobody on site
                                                 yet, so there is nothing to close out - the same rule
                                                 the technician portal draws the button by. A Super
                                                 Admin additionally gets Pending work, which is theirs
                                                 to close out early. See Project::isCompletableBy(),
                                                 which ProjectController::complete() asks again. --}}
                                            @if ($project->isCompletableBy(auth()->user()))
                                                <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                                                    data-bs-target="#completeProjectModal{{ $project->project_id }}"
                                                    title="Complete Project">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            @endif

                                        @endif

                                        {{-- Outside the read-only branch on purpose.
                                             A Completed or Cancelled project is exactly
                                             what an archive is for: there is nothing
                                             left to do about it, and leaving it in the
                                             active listing forever is why that list
                                             grew without end. The model decides which
                                             statuses qualify, and the endpoint asks it
                                             the same question - see
                                             Project::isArchivable(). --}}
                                        @if ($canArchive && $project->isArchivable())
                                            <button class="btn btn-sm btn-dark py-1 px-2" data-bs-toggle="modal"
                                                data-bs-target="#archiveProjectModal{{ $project->project_id }}"
                                                title="Archive Project">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach


                    </tbody>

                </table>

            {{-- The row dialogs, gathered here rather than written inside the
                 table. A <div> is not valid as a child of <tbody>: a browser
                 repairs that by lifting the markup out of the table entirely, so
                 the rendered page stops matching its own source - and this table
                 is redrawn by DataTables on every sort, search and page change.
                 Outside the table there is nothing to repair and nothing to
                 redraw. --}}
            @foreach ($projects as $project)
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
                                            Put <strong>{{ $project->reference_no }}</strong> on hold?
                                            Dates from tomorrow are released.
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
                                            Resume <strong>{{ $project->reference_no }}</strong>?
                                            The dates it kept come back into force, so its team has to
                                            still be free for them.

                                            <div class="alert alert-danger mt-3 mb-0 d-none" role="alert"
                                                data-recovery-error></div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                Cancel
                                            </button>

                                            {{-- Sent with fetch rather than as a plain submit so a
                                                 refused resume can open the Schedule Conflict dialog
                                                 with the clash in it, rather than bouncing the page
                                                 and reducing it to a toast. This is still a real
                                                 form: a browser running no script submits it, and
                                                 the endpoint answers both. --}}
                                            <form method="POST" data-recovery-form
                                                data-conflicts-url="{{ route('super-admin.projects.resume-conflicts', $project->project_id) }}"
                                                data-recovery-failure="Unable to resume project. Nothing was changed."
                                                action="{{ route('super-admin.projects.resume', $project->project_id) }}">

                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="btn btn-success"
                                                    data-recovery-submit>
                                                    Resume Project
                                                </button>
                                            </form>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <!-- COMPLETE PROJECT MODAL -->
                            @if ($project->isCompletableBy(auth()->user()))
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
                                                    Mark <strong>{{ $project->reference_no }}</strong> as completed?
                                                </p>

                                                {{-- Said before the project starts waiting, because
                                                     afterwards it is a window too late to be useful.
                                                     Not a blocker, and deliberately not styled as one:
                                                     completion is never refused for want of a
                                                     registered client. The same notice the project's
                                                     own page shows, because both dialogs complete a
                                                     project on identical terms. --}}
                                                @if (($confirmability[$project->project_id] ?? null) === \App\Services\CompletionConfirmability::UNREACHABLE)
                                                    <div class="alert alert-warning d-flex gap-2" role="status">
                                                        <i class="bi bi-person-slash fs-5 lh-1" aria-hidden="true"></i>
                                                        <div>
                                                            <p class="fw-semibold mb-1">
                                                                No registered client can currently confirm this project.
                                                            </p>
                                                            <p class="mb-0 small">
                                                                It will go to Awaiting Client Confirmation as usual and
                                                                complete automatically after
                                                                {{ \App\Models\Project::completionConfirmationDays() }}
                                                                days if no confirmation is received.
                                                            </p>
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- What the completion rules object to. A lead technician
                                                     is refused outright; an administrator may go ahead, but
                                                     the reason is written onto the project and into the
                                                     activity log, so it is never a silent decision. --}}
                                                @php($blockers = $completionBlockers[$project->project_id] ?? [])

                                                @if (! empty($blockers))
                                                    <div class="alert alert-warning" role="alert">
                                                        <p class="fw-semibold mb-2">
                                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                                            This project is not ready to be completed
                                                        </p>
                                                        <ul class="mb-2 ps-3">
                                                            @foreach ($blockers as $blocker)
                                                                <li>{{ $blocker }}</li>
                                                            @endforeach
                                                        </ul>
                                                        <label class="form-label fw-semibold mb-1"
                                                            for="overrideReason{{ $project->project_id }}">
                                                            Reason for completing it anyway
                                                        </label>
                                                        <textarea class="form-control"
                                                            id="overrideReason{{ $project->project_id }}"
                                                            name="completion_override_reason" rows="2" minlength="10"
                                                            maxlength="500" required
                                                            placeholder="Why is this being completed with the above outstanding?"></textarea>
                                                    </div>
                                                @endif

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Completion Date</label>
                                                    <input type="date" class="form-control" name="completion_date"
                                                        value="{{ \App\Support\BusinessTime::today()->format('Y-m-d') }}"
                                                        max="{{ \App\Support\BusinessTime::today()->format('Y-m-d') }}" required>
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
                            @endif

                            <!-- ARCHIVE PROJECT MODAL -->
                            @if ($canArchive && $project->isArchivable())
                            <div class="modal fade" id="archiveProjectModal{{ $project->project_id }}" tabindex="-1"
                                aria-labelledby="archiveProjectModalLabel{{ $project->project_id }}" aria-hidden="true">

                                <div class="modal-dialog">
                                    <div class="modal-content">

                                        <div class="modal-header">
                                            <h5 class="modal-title" id="archiveProjectModalLabel{{ $project->project_id }}">
                                                Archive {{ $project->reference_no ?? $project->name }}?
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        {{-- The same short confirmation the Project
                                             Details page asks, so archiving reads the
                                             same wherever it is started from. --}}
                                        <div class="modal-body">
                                            <strong>Nothing is deleted.</strong> Its schedule, team, tasks,
                                            reports, documents and history stay with it on the Archived
                                            Projects page, and its technicians are freed for those dates.
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


            </div>

        </div>
    </div>


    <x-schedule-conflict-modal />

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="/js/super-admin/scheduleRecovery.js"></script>
    @endpush

    @push('scripts')
        <script>
            $(function() {

                $('#projectsTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [10, 25, 50, 100],
                    info: false,
                    // DataTables types a column of bare numbers as numeric and
                    // right-aligns it. A project ID is a label, not a
                    // quantity, so it is put back with every other column.
                    // Ordering stays numeric.
                    columnDefs: [{
                        targets: 0,
                        className: 'dt-left'
                    }],
                    language: {
                        search: "",
                        searchPlaceholder: "Search projects..."
                    }
                });

            });
        </script>

        {{-- The tabs themselves: filtering, the ?status= deep link the
             dashboard's figures use, and the memory that brings somebody back
             to the tab they left. Shared with the technician portal's My
             Projects rather than written twice. --}}
        <script src="/js/projectStatusTabs.js"></script>
    @endpush
@endsection