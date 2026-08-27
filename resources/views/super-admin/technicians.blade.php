@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    {{-- The searchable technician picker on the Schedules tab, the same
         control the Export Logs dialog uses. --}}
    <link href="/css/actorSearch.css" rel="stylesheet">
    <link href="/css/calendar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Technicians</h4>
            <p class="text-secondary small mb-0">Manage technician specialties and their project schedules.</p>
        </div>
    </div>

    @php
        // A specialty notification links here, and the reviewer wants the
        // Details table: the highlighted rows are the ones waiting on them.
        $pendingTechnicianIds = collect($pendingTechnicianIds);
    @endphp

    @if ($pendingTechnicianIds->isNotEmpty())
        <div class="alert technician-pending-alert d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-patch-question-fill" aria-hidden="true"></i>
            <span>
                <strong>{{ $pendingTechnicianIds->count() }}</strong>
                {{ Str::plural('technician', $pendingTechnicianIds->count()) }}
                {{ $pendingTechnicianIds->count() === 1 ? 'is' : 'are' }}
                waiting on a specialty decision.
            </span>

            {{-- Shown only when the dashboard's Urgent Actions narrowed this
                 table to the people waiting - the way back out, so a table
                 filtered by a link never has to be reloaded to escape. --}}
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto d-none"
                data-specialty-filter-clear>
                Show all technicians
            </button>
        </div>
    @endif

    <ul class="nav nav-tabs technician-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="technicianDetailsTab" data-bs-toggle="tab"
                data-bs-target="#technicianDetailsPane" type="button" role="tab"
                aria-controls="technicianDetailsPane" aria-selected="true">
                <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>
                Details
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="technicianSchedulesTab" data-bs-toggle="tab"
                data-bs-target="#technicianSchedulesPane" type="button" role="tab"
                aria-controls="technicianSchedulesPane" aria-selected="false">
                <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>
                Schedules
            </button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ============================ TAB 1: DETAILS ============================ --}}
        <div class="tab-pane fade show active" id="technicianDetailsPane" role="tabpanel"
            aria-labelledby="technicianDetailsTab">

            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table id="techniciansTable" class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-info">
                                <tr>
                                    <th>Technician ID</th>
                                    <th>Employee ID</th>
                                    <th>Technician</th>
                                    <th>Specialty</th>
                                    <th>Position</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                {{-- No blade fallback row here: a single colspan cell has
                                     fewer cells than the header, which DataTables cannot
                                     parse ("Requested unknown parameter"). Its own
                                     emptyTable message covers it (see the init below). --}}
                                @foreach ($technicians as $technician)
                                    @php
                                        $isLead = optional($technician->account)->role === 'lead_technician';
                                    @endphp
                                    @php
                                        $awaitingDecision = $pendingTechnicianIds->contains($technician->technician_id);
                                    @endphp

                                    <tr data-technician-row="{{ $technician->technician_id }}"
                                        class="{{ $awaitingDecision ? 'technician-row-pending' : '' }}">
                                        <td>{{ $technician->displayCode() }}</td>
                                        {{-- The code on their staff account,
                                             which is how they are referred to
                                             everywhere outside this page. --}}
                                        <td>{{ $technician->account?->user_code ?? '—' }}</td>
                                        <td>
                                            {{-- Picture beside the name, the
                                                 same one they set on their own
                                                 profile page. --}}
                                            <div class="d-flex align-items-center gap-2">
                                                <x-user-avatar :user="$technician->account" size="sm" />
                                                <div>
                                                    <div class="fw-semibold">
                                                        {{ $technician->name }}

                                                        @if ($awaitingDecision)
                                                            <span class="badge technician-pending-badge ms-1">
                                                                <i class="bi bi-patch-question me-1"
                                                                    aria-hidden="true"></i>
                                                                Specialty request
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="small text-muted">
                                                        {{ $technician->account?->email }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-technician-specialties="{{ $technician->technician_id }}">
                                            @forelse ($technician->skills->sortBy('skill_name') as $skill)
                                                <span class="technician-chip">{{ $skill->skill_name }}</span>
                                            @empty
                                                <span class="text-muted small">No specialties assigned.</span>
                                            @endforelse
                                        </td>
                                        <td>
                                            <span class="badge {{ $isLead ? 'bg-primary' : 'bg-secondary' }}">
                                                {{ $isLead ? 'Lead Technician' : 'Technician' }}
                                            </span>
                                        </td>
                                        <td>
                                            {{-- Whether the account can still be
                                                 used, in the words and colours the
                                                 profile page already prints it in.
                                                 It belongs on this page because a
                                                 deactivated technician keeps every
                                                 booking they were holding, so the
                                                 row looks entirely normal until
                                                 somebody tries to give them work. --}}
                                            <span class="badge {{ $technician->account?->statusBadgeClass() ?? 'bg-dark' }}">
                                                {{ $technician->account?->statusLabel() ?? 'No account' }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                                data-view-technician="{{ $technician->technician_id }}"
                                                title="View technician details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ TAB 2: SCHEDULES ============================ --}}
        <div class="tab-pane fade" id="technicianSchedulesPane" role="tabpanel"
            aria-labelledby="technicianSchedulesTab">

            <div class="card shadow-sm border-0 rounded-2 mb-3">
                <div class="card-body p-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label for="technicianPicker" class="form-label small fw-semibold mb-1">
                                Technician
                            </label>
                            {{-- The same typeahead the Export Logs dialog uses -
                                 see the actor search in configuration.blade.php
                                 and renderActorResults() beside it.

                                 It replaced a native <datalist>, which browsers
                                 disagree about badly enough to be unusable here:
                                 some match only the start of the value, so
                                 typing a surname found nobody; some show the
                                 label instead of the value, hiding the name; and
                                 none of them can show what the account is or
                                 whether it is still active. A list drawn by the
                                 page matches on any part of any field, says who
                                 each person is, and behaves the same everywhere.

                                 An inactive account is still listed: their
                                 calendar is worth reading, because they are
                                 still holding every date they were booked on.
                                 It is only new work they cannot take. --}}
                            <div class="config-actor-search" data-technician-search>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-search" aria-hidden="true"></i>
                                    </span>
                                    <input type="text" id="technicianPicker" class="form-control"
                                        placeholder="Search by technician ID, name or role&hellip;"
                                        autocomplete="off" role="combobox" aria-expanded="false"
                                        aria-controls="technicianPickerResults" data-technician-picker>
                                    <button type="button" class="btn btn-outline-secondary d-none"
                                        aria-label="Clear the chosen technician" data-technician-picker-clear>
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ul class="config-actor-results d-none" id="technicianPickerResults" role="listbox"
                                    data-technician-picker-results></ul>
                            </div>

                            <div class="form-text" data-technician-picker-hint>
                                Pick a technician to load their calendar.
                            </div>
                        </div>

                        <div class="col-md-6 text-md-end">
                            <button type="button" class="btn btn-primary d-none" data-add-to-project-open>
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                Add Technician to Project
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Split panel: calendar on the left, always-visible project
                 details on the right. No modal is used for project details. --}}
            <div class="row g-3 technician-split">

                {{-- ---------------- LEFT: calendar ---------------- --}}
                <div class="col-12 col-xl-8">
                    <div class="card shadow-sm border-0 rounded-2 h-100">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                <div>
                                    <div class="technician-eyebrow">Calendar View</div>
                                    <h5 class="fw-bold mb-0" data-calendar-technician-name>
                                        No technician selected
                                    </h5>
                                </div>
                                <span class="schedule-count-pill d-none" data-calendar-assignment-count></span>
                            </div>

                            {{-- Read from the model so this key and the bookings
                                 it explains cannot drift apart - written out by
                                 hand it kept Overdue's old orange after the
                                 colour changed. --}}
                            <div class="schedule-legend mb-3">
                                @foreach (\App\Models\Project::calendarLegend() as $entry)
                                    <span class="schedule-legend-item">
                                        <i class="schedule-dot" style="background:{{ $entry['colour'] }}"></i>
                                        {{ $entry['label'] }}
                                    </span>
                                @endforeach
                            </div>

                            <div id="technicianCalendar" class="calendar-standard d-none"></div>

                            <div class="schedule-empty-state" data-calendar-placeholder>
                                Please select a technician to view their schedule.
                            </div>

                            <div class="schedule-empty-state mt-3 d-none" data-calendar-empty>
                                This technician has no scheduled projects yet.
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---------------- RIGHT: project details ---------------- --}}
                <div class="col-12 col-xl-4">
                    <div class="card shadow-sm border-0 rounded-2 h-100" data-details-panel>
                        <div class="card-body p-3">

                            {{-- State 1: no technician chosen --}}
                            <div data-panel-no-technician>
                                <div class="technician-eyebrow mb-2">Selected Project</div>
                                <div class="schedule-empty-state">
                                    Please select a technician to view their schedule.
                                </div>
                            </div>

                            {{-- State 2: technician chosen, no project clicked --}}
                            <div class="d-none" data-panel-no-project>
                                <div class="technician-eyebrow mb-2">Selected Project</div>
                                <div class="schedule-empty-state">
                                    Select a scheduled project from the calendar to view its details.
                                </div>
                            </div>

                            {{-- State 3: a project is selected --}}
                            <div class="d-none" data-panel-project>
                                <div class="technician-eyebrow mb-2">Selected Project</div>

                                <a href="#" class="panel-reference" data-panel-ref target="_blank">
                                    <span data-panel-ref-text></span>
                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                </a>

                                <h5 class="panel-project-name" data-panel-name></h5>

                                <div class="panel-meta" data-panel-client-wrap>
                                    <i class="bi bi-person" aria-hidden="true"></i>
                                    <span data-panel-client></span>
                                </div>

                                <div class="panel-meta" data-panel-address-wrap>
                                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                    <span data-panel-address></span>
                                </div>

                                {{-- The project's whole schedule, never the clicked day. --}}
                                <div class="panel-meta" data-panel-schedule-wrap>
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    <span data-panel-schedule></span>
                                </div>

                                <div class="mt-2" data-panel-status></div>

                                <hr class="panel-divider">

                                <div class="panel-section-heading">
                                    <i class="bi bi-people-fill" aria-hidden="true"></i>
                                    {{-- Today's team. The ranges above may
                                         reach back over days a different crew
                                         worked, and for a technician who has
                                         been taken off this project it is not
                                         their own name in this list at all. --}}
                                    Currently Assigned Technicians
                                </div>

                                <div class="panel-lead-row">
                                    <span class="panel-role-label">Lead</span>
                                    <span class="panel-role-value" data-panel-lead></span>
                                </div>

                                <div class="panel-support-row">
                                    <span class="panel-role-label">Supporting</span>
                                    <div class="schedule-modal-team-chips" data-panel-supporting></div>
                                </div>

                                <hr class="panel-divider">

                                {{-- Only this technician's tasks on the project,
                                     not the project's whole task board. --}}
                                <div class="panel-section-heading">
                                    <i class="bi bi-list-task" aria-hidden="true"></i>
                                    Tasks
                                    <span class="schedule-count-pill ms-auto d-none" data-panel-task-count></span>
                                </div>

                                <p class="text-muted small mb-2" data-panel-tasks-note></p>

                                <div class="panel-task-list" data-panel-tasks></div>

                                <div class="schedule-empty-state d-none" data-panel-tasks-empty>
                                    No tasks assigned to this technician on this project.
                                </div>

                                <hr class="panel-divider">

                                {{-- Inline lead reassignment - no modal. --}}
                                <div class="technician-lead-panel d-none" data-panel-lead-replacement>
                                    <div class="panel-section-heading">
                                        <i class="bi bi-person-badge" aria-hidden="true"></i>
                                        Assign New Lead Technician
                                    </div>
                                    <p class="text-muted small mb-2" data-panel-lead-intro></p>
                                    <div class="technician-lead-options" data-panel-lead-options></div>
                                    <div class="schedule-empty-state d-none" data-panel-lead-empty>
                                        No lead technician is free for these dates. Free one up or change the
                                        schedule first.
                                    </div>
                                </div>

                                <div class="alert alert-danger mt-3 mb-0 d-none" role="alert"
                                    data-panel-error></div>
                                <div class="alert alert-success mt-3 mb-0 d-none" role="alert"
                                    data-panel-success></div>

                                <div class="d-grid gap-2 mt-3">
                                    <button type="button" class="btn btn-outline-danger d-none"
                                        data-panel-remove>
                                        <i class="bi bi-person-dash me-1" aria-hidden="true"></i>
                                        Remove Technician from Project
                                    </button>

                                    <button type="button" class="btn btn-danger d-none" data-panel-confirm-remove
                                        disabled>
                                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status"
                                            aria-hidden="true" data-panel-confirm-spinner></span>
                                        Reassign Lead &amp; Remove
                                    </button>

                                    <button type="button" class="btn btn-link btn-sm d-none"
                                        data-panel-cancel-remove>
                                        Cancel
                                    </button>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            {{-- Every project the selected technician is assigned to. --}}
            <div class="card shadow-sm border-0 rounded-2 mt-3">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <div class="technician-eyebrow">Assignments</div>
                            <h6 class="fw-bold mb-0">Projects this technician is assigned to</h6>
                        </div>
                        <span class="schedule-count-pill d-none" data-assignments-count></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 technician-assignments-table">
                            <thead class="table-info">
                                <tr>
                                    <th>Reference No.</th>
                                    <th>Project</th>
                                    <th>Client</th>
                                    <th>Schedule</th>
                                    <th class="text-center">Tasks</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody data-assignments-body></tbody>
                        </table>
                    </div>

                    <div class="schedule-empty-state mt-3" data-assignments-placeholder>
                        Select a technician to see their project assignments.
                    </div>

                    <div class="schedule-empty-state mt-3 d-none" data-assignments-empty>
                        This technician is not assigned to any projects.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================ TECHNICIAN DETAILS MODAL ============================ --}}
    <div class="modal fade" id="technicianDetailsModal" tabindex="-1" aria-hidden="true"
        data-technician-details-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow">Technician</span>
                        <h5 class="modal-title mb-1" data-details-name>&nbsp;</h5>
                        <span class="technician-meta" data-details-meta></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-3">
                            <div class="technician-field-label">Technician ID</div>
                            <div class="technician-field-value" data-details-id></div>
                        </div>
                        <div class="col-sm-3">
                            <div class="technician-field-label">Position</div>
                            <div class="technician-field-value" data-details-position></div>
                        </div>
                        <div class="col-sm-3">
                            <div class="technician-field-label">Account</div>
                            <div class="technician-field-value">
                                <span class="badge" data-details-status>&nbsp;</span>
                            </div>
                        </div>
                        <div class="col-sm-3">
                            <div class="technician-field-label">Email</div>
                            <div class="technician-field-value" data-details-email></div>
                        </div>
                    </div>

                    {{-- The outstanding request, decided here rather than in a
                         queue of its own: approving is easier beside the
                         specialties they already hold. --}}
                    <div class="technician-request-panel d-none" data-details-request>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <span class="technician-request-title">
                                <i class="bi bi-patch-question me-1" aria-hidden="true"></i>
                                Specialty Request
                            </span>
                            <span class="badge bg-warning text-dark">Pending Approval</span>
                        </div>

                        <div class="technician-request-changes mb-2" data-details-request-changes></div>

                        <p class="text-secondary small mb-3" data-details-request-when></p>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-success" data-details-approve>
                                <span class="spinner-border spinner-border-sm me-1 d-none" role="status"
                                    aria-hidden="true" data-details-decide-spinner></span>
                                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                                Approve
                            </button>

                            <button type="button" class="btn btn-sm btn-outline-danger" data-details-reject>
                                <i class="bi bi-x-lg me-1" aria-hidden="true"></i>
                                Reject
                            </button>
                        </div>
                    </div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-award me-1" aria-hidden="true"></i> Current Specialties</span>
                    </div>

                    <div class="technician-specialty-list" data-details-specialties></div>

                    <hr class="my-4">

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Add Specialties</span>
                    </div>

                    <div class="technician-specialty-picker" data-details-available>
                        @foreach ($skills as $skill)
                            <label class="technician-specialty-option" data-available-option="{{ $skill->skill_id }}">
                                <input type="checkbox" class="form-check-input" value="{{ $skill->skill_id }}">
                                <span>{{ $skill->skill_name }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="text-muted small mt-2 d-none" data-details-all-assigned>
                        This technician already has every available specialty.
                    </div>

                    <div class="alert alert-danger mt-3 d-none" role="alert" data-details-error></div>
                </div>

                <div class="modal-footer">
                    <span class="technician-pending-note me-auto d-none" data-details-pending></span>

                    {{-- Straight from the person to their calendar. Reading a
                         technician's details and then wanting to see what they
                         are booked on is the obvious next question, and
                         answering it used to mean closing this, switching tab
                         and typing the name back in. This closes the dialog,
                         switches to Schedules and fills the picker in for
                         them - see window.technicianSchedules.show(), the same
                         path a typed search takes. --}}
                    <button type="button" class="btn btn-outline-primary" data-details-view-schedule>
                        <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>
                        View Schedule
                    </button>

                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" data-details-save disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-details-save-spinner></span>
                        Save Changes
                    </button>
                </div>

            </div>
        </div>
    </div>

    {{-- ============================ ADD TO PROJECT MODAL ============================ --}}
    <div class="modal fade" id="addToProjectModal" tabindex="-1" aria-hidden="true" data-add-project-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow">Add Technician to Project</span>
                        <h5 class="modal-title mb-0" data-add-technician-name>&nbsp;</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div data-add-browser>
                        <div class="schedule-section-heading">
                            <span><i class="bi bi-list-check me-1" aria-hidden="true"></i> Available projects</span>
                            <span class="schedule-count-pill d-none" data-add-count></span>
                        </div>

                        <div class="text-secondary small py-3" data-add-loading>
                            <span class="spinner-border spinner-border-sm me-2" role="status"
                                aria-hidden="true"></span>
                            Checking availability&hellip;
                        </div>

                        <div class="schedule-eligible-list" data-add-list></div>

                        <div class="schedule-empty-state d-none" data-add-empty>
                            There are no projects this technician can join right now.
                        </div>

                        <div class="schedule-blocked-wrap d-none" data-add-blocked-wrap>
                            <button type="button" class="schedule-blocked-toggle" data-add-blocked-toggle>
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                <span data-add-blocked-label>Show unavailable projects</span>
                            </button>
                            <div class="schedule-blocked-list d-none" data-add-blocked-list></div>
                        </div>
                    </div>

                    <div class="alert alert-danger mt-3 d-none" role="alert" data-add-error></div>
                    <div class="alert alert-success mt-3 d-none" role="alert" data-add-success></div>

                    {{-- Shown in place of the list when the technician being
                         placed is a Lead Technician and one of the ticked
                         projects already has one. A lead is never replaced
                         without somebody saying so, and this is where they say
                         it. Inline rather than a second dialog, matching the
                         Import Team and Remove Technician confirmations. --}}
                    <div class="technician-lead-panel d-none" data-add-lead-confirm>
                        <div class="panel-section-heading">
                            <i class="bi bi-person-badge" aria-hidden="true"></i>
                            Replace Lead Technician
                        </div>
                        <p class="text-muted small mb-2" data-add-lead-intro></p>
                        <div class="technician-lead-options" data-add-lead-list></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"
                        data-add-dismiss>Cancel</button>

                    <button type="button" class="btn btn-link btn-sm d-none" data-add-lead-cancel>
                        Cancel
                    </button>

                    <button type="button" class="btn btn-success" data-add-save disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-add-save-spinner></span>
                        Assign Technician
                    </button>

                    <button type="button" class="btn btn-danger d-none" data-add-lead-confirm-save>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-add-lead-spinner></span>
                        Replace Lead Technician
                    </button>
                </div>

            </div>
        </div>
    </div>

    @push('scripts')
        @php
            // Built here rather than inside @json(): a multi-line array
            // literal in a directive's arguments is compiled as PHP that
            // stops at the wrong bracket.
            $technicianDirectory = $technicians
                ->map(
                    fn($technician) => [
                        'technician_id' => $technician->technician_id,
                        // What the picker prints and matches on.
                        'display_code' => $technician->displayCode(),
                        'name' => $technician->name,
                        // The other things somebody knows a technician by. The
                        // picker matches on all four - the same fields the
                        // Export Logs user search matches on - so a surname, a
                        // staff code, an address or a role all find them.
                        'code' => $technician->account?->user_code,
                        'email' => $technician->account?->email,
                        'role_label' => $technician->account?->roleLabel() ?? 'Technician',
                        // Whether Add Technician to Project is offered for
                        // them at all. The server refuses it either way; this
                        // is what stops the button being drawn to be refused.
                        'can_receive_work' => $technician->isAssignable(),
                        'status_label' => $technician->account?->statusLabel() ?? 'No account',
                    ],
                )
                ->values();
        @endphp

        <script>
            window.technicianDirectory = @json($technicianDirectory);
        </script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script src="/js/calendarHeader.js"></script>
        <script src="/js/super-admin/technicians.js"></script>
    @endpush
@endsection
