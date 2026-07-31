@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Technicians</h4>
            <p class="text-secondary small mb-0">Manage technician specialties and their project schedules.</p>
        </div>
    </div>

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
                                    <th>Full Name</th>
                                    <th>Specialty</th>
                                    <th>Position</th>
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
                                    <tr data-technician-row="{{ $technician->technician_id }}">
                                        <td>{{ $technician->technician_id }}</td>
                                        <td class="fw-semibold">{{ $technician->name }}</td>
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
                            <input list="technicianPickerOptions" id="technicianPicker" class="form-control"
                                placeholder="Search by technician ID or name&hellip;" autocomplete="off"
                                data-technician-picker>
                            <datalist id="technicianPickerOptions">
                                @foreach ($technicians as $technician)
                                    <option value="{{ $technician->technician_id }} — {{ $technician->name }}"></option>
                                @endforeach
                            </datalist>
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

            {{-- Nothing renders until a technician is chosen. --}}
            <div class="card shadow-sm border-0 rounded-2 d-none" data-technician-calendar-card>
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <div class="technician-eyebrow">Showing schedule for</div>
                            <h5 class="fw-bold mb-0" data-calendar-technician-name></h5>
                        </div>
                        <span class="schedule-count-pill" data-calendar-assignment-count></span>
                    </div>

                    <div class="schedule-legend mb-3">
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#f0ad4e"></i>
                            Pending</span>
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#0d6efd"></i>
                            Ongoing</span>
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#198754"></i>
                            Completed</span>
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#dc3545"></i>
                            Cancelled</span>
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#6c757d"></i> On
                            Hold</span>
                    </div>

                    <div id="technicianCalendar"></div>

                    <div class="schedule-empty-state mt-3 d-none" data-calendar-empty>
                        This technician has no scheduled projects yet.
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
                        <div class="col-sm-4">
                            <div class="technician-field-label">Technician ID</div>
                            <div class="technician-field-value" data-details-id></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="technician-field-label">Position</div>
                            <div class="technician-field-value" data-details-position></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="technician-field-label">Email</div>
                            <div class="technician-field-value" data-details-email></div>
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
                    <div class="alert alert-success mt-3 d-none" role="alert" data-details-success></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" data-details-save disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-details-save-spinner></span>
                        Add Selected
                    </button>
                </div>

            </div>
        </div>
    </div>

    {{-- ============================ PROJECT ASSIGNMENT MODAL ============================ --}}
    <div class="modal fade" id="assignmentDetailsModal" tabindex="-1" aria-hidden="true"
        data-assignment-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow">Project Assignment</span>
                        <h5 class="modal-title mb-1" data-assignment-name>&nbsp;</h5>
                        <a href="#" class="schedule-modal-ref" data-assignment-ref target="_blank">
                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                            <span data-assignment-ref-text></span>
                        </a>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <div class="technician-field-label">Client</div>
                            <div class="technician-field-value" data-assignment-client></div>
                        </div>
                        <div class="col-sm-3">
                            <div class="technician-field-label">Start Date</div>
                            <div class="technician-field-value" data-assignment-start></div>
                        </div>
                        <div class="col-sm-3">
                            <div class="technician-field-label">End Date</div>
                            <div class="technician-field-value" data-assignment-end></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Project Status</div>
                            <div class="technician-field-value" data-assignment-status></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Lead Technician</div>
                            <div class="technician-field-value" data-assignment-lead></div>
                        </div>
                    </div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-people-fill me-1" aria-hidden="true"></i> Assigned Technicians</span>
                    </div>
                    <div class="schedule-modal-team-chips" data-assignment-team></div>

                    {{-- Shown only when the technician being removed is the lead. --}}
                    <div class="technician-lead-panel mt-4 d-none" data-lead-replacement-panel>
                        <div class="schedule-section-heading">
                            <span><i class="bi bi-person-badge me-1" aria-hidden="true"></i> Assign New Lead
                                Technician</span>
                        </div>
                        <p class="text-muted small">
                            <span data-lead-replacement-intro></span>
                            Choose a lead technician who is free for this project's whole schedule.
                        </p>
                        <div class="technician-lead-options" data-lead-replacement-options></div>
                        <div class="schedule-empty-state d-none" data-lead-replacement-empty>
                            No lead technician is available for this project's dates. Free one up, or change the
                            project schedule, before removing the current lead.
                        </div>
                    </div>

                    <div class="alert alert-danger mt-3 d-none" role="alert" data-assignment-error></div>
                    <div class="alert alert-success mt-3 d-none" role="alert" data-assignment-success></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-danger d-none" data-remove-technician>
                        <i class="bi bi-person-dash me-1" aria-hidden="true"></i>
                        Remove Technician
                    </button>
                    <button type="button" class="btn btn-danger d-none" data-confirm-removal disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-confirm-removal-spinner></span>
                        Reassign Lead &amp; Remove
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
                    <div class="schedule-section-heading">
                        <span><i class="bi bi-list-check me-1" aria-hidden="true"></i> Available projects</span>
                        <span class="schedule-count-pill d-none" data-add-count></span>
                    </div>

                    <div class="text-secondary small py-3" data-add-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
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

                    <div class="alert alert-danger mt-3 d-none" role="alert" data-add-error></div>
                    <div class="alert alert-success mt-3 d-none" role="alert" data-add-success></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" data-add-save disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-add-save-spinner></span>
                        Assign Technician
                    </button>
                </div>

            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            window.technicianDirectory = @json(
                $technicians->map(fn($technician) => [
                    'technician_id' => $technician->technician_id,
                    'name' => $technician->name,
                ])->values());
        </script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script src="/js/super-admin/technicians.js"></script>
    @endpush
@endsection
