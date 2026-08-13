@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/createProject.css" rel="stylesheet">
    <link href="/css/super-admin/createProjectProgress.css" rel="stylesheet">
    {{-- The Import Team dialog, shared with the assigned-team editor. --}}
    <link rel="stylesheet" href="/css/importTeam.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
@endpush

@section('content')
    <div class="create-project-header">
        <div>
            <h4 class="fw-bold mb-1">Create New Project</h4>
            <p class="text-secondary small mb-0">Use the wizard to capture client, project, and schedule details in order.
            </p>
        </div>
        <div class="create-project-step-counter text-secondary small">
            Step <span data-step-counter>1</span> of 4
        </div>
    </div>

    <div class="client-progress-wrap">
        <div class="client-progress" aria-label="Project progress tracker">
            <div class="client-progress-step active" data-progress-step="1">
                <div class="client-progress-circle">1</div>
                <div class="client-progress-label">Client Info</div>
            </div>

            <div class="client-progress-line" aria-hidden="true"></div>

            <div class="client-progress-step" data-progress-step="2">
                <div class="client-progress-circle">2</div>
                <div class="client-progress-label">Project Details</div>
            </div>

            <div class="client-progress-line" aria-hidden="true"></div>

            <div class="client-progress-step" data-progress-step="3">
                <div class="client-progress-circle">3</div>
                <div class="client-progress-label">Schedule</div>
            </div>

            <div class="client-progress-line" aria-hidden="true"></div>

            <div class="client-progress-step" data-progress-step="4">
                <div class="client-progress-circle">4</div>
                <div class="client-progress-label">Review</div>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" data-server-errors>
            <ul class="mb-0 ps-3">
                @foreach (collect($errors->all())->unique() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm border-0 rounded-4 create-project-card">
        <div class="card-body p-4 p-lg-5">
            @php
                $technicianWizardData = $technicians
                    ->map(function ($technician) use ($technicianSchedules) {
                        return [
                            'id' => $technician->technician_id,
                            'name' => $technician->name,
                            'role' => $technician->role,
                            'skills' => $technician->skill_names,
                            'schedules' => $technicianSchedules[$technician->technician_id] ?? [],
                        ];
                    })
                    ->values();
            @endphp

            <form method="POST" action="{{ route('super-admin.projects.create.store') }}" enctype="multipart/form-data"
                data-project-wizard>
                @csrf

                <section class="wizard-step active" data-wizard-step="1">
                    <div class="wizard-step-header">
                        <h5 class="mb-1">Client Information</h5>
                        <p class="text-secondary small mb-0"></p>
                    </div>

                    <div class="client-type-grid mb-4">
                        <label class="client-type-option is-selected" data-client-type-option>
                            <input type="radio" name="client_type" value="Residential" class="visually-hidden"
                                data-client-type-radio checked>
                            <span class="client-type-icon"><i class="bi bi-house-door" aria-hidden="true"></i></span>
                            <span>
                                <strong>Residential</strong>
                                <small>Homeowner or private property</small>
                            </span>
                        </label>

                        <label class="client-type-option" data-client-type-option>
                            <input type="radio" name="client_type" value="Commercial" class="visually-hidden"
                                data-client-type-radio>
                            <span class="client-type-icon"><i class="bi bi-building" aria-hidden="true"></i></span>
                            <span>
                                <strong>Commercial</strong>
                                <small>Company or business client</small>
                            </span>
                        </label>
                    </div>

                    <div class="row g-3 mb-3" data-company-name-wrap hidden>
                        <div class="col-12">
                            <label for="companyName" class="form-label">Company Name</label>
                            <input type="text" name="company_name" id="companyName" class="form-control"
                                placeholder="Enter company name" data-summary-input="company_name">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label for="surname" class="form-label">Surname</label>
                            <input type="text" name="surname" id="surname" class="form-control"
                                placeholder="Enter surname" data-summary-input="surname" required>
                        </div>

                        <div class="col-md-5">
                            <label for="firstname" class="form-label">First Name</label>
                            <input type="text" name="firstname" id="firstname" class="form-control"
                                placeholder="Enter first name" data-summary-input="firstname" required>
                        </div>

                        <div class="col-md-2">
                            <label for="middleName" class="form-label">Middle Initial</label>
                            <input type="text" name="middle_name" id="middleName" class="form-control"
                                placeholder="Enter M.I" data-summary-input="middle_name">
                        </div>

                        <div class="col-md-6">
                            <label for="clientEmail" class="form-label">Email Address</label>
                            <input type="email" name="client_email" id="clientEmail" class="form-control"
                                placeholder="Enter email address" data-summary-input="client_email" required>
                        </div>

                        <div class="col-md-6">
                            <label for="clientPhone" class="form-label">Contact Number</label>
                            <input type="tel" name="client_phone" id="clientPhone" class="form-control"
                                placeholder="09XXXXXXXXX" maxlength="11" pattern="^09\d{9}$"
                                oninput="this.value=this.value.replace(/[^0-9]/g,'')" data-summary-input="client_phone"
                                required>
                        </div>
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step="2" hidden>
                    <div class="wizard-step-header">
                        <h5 class="mb-1">Project Details</h5>
                        <p class="text-secondary small mb-0">Choose the project scope, attach the files, and add the
                            address.</p>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="projectAddress" class="form-label">Project Address</label>
                            <textarea name="project_address" id="projectAddress" rows="3" class="form-control"
                                placeholder="Enter project address" data-summary-input="project_address" required></textarea>
                        </div>

                        <div class="col-md-6">
                            <label for="quotationAmount" class="form-label">Quotation Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" name="quotation_amount" id="quotationAmount" class="form-control"
                                    min="0" step="0.01" inputmode="decimal"
                                    placeholder="Enter quotation amount" data-summary-input="quotation_amount" required>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                                <label class="form-label mb-0">Project Type</label>
                                <small class="text-secondary">Select one or more</small>
                            </div>


                            <div class="project-type-grid" data-project-type-group>
                                @foreach ($projectTypes as $projectType)
                                    <label class="project-type-option" data-project-type-option>
                                        <input type="checkbox" name="project_types[]"
                                            value="{{ $projectType->type_name }}" class="visually-hidden"
                                            data-project-type-checkbox data-label="{{ $projectType->type_name }}">
                                        <span class="project-type-icon"><i class="bi bi-{{ $projectType->icon_class }}"
                                                aria-hidden="true"></i></span>
                                        <span class="project-type-name">{{ $projectType->type_name }}</span>
                                    </label>
                                @endforeach

                            </div>

                            <div class="form-text text-danger mt-2 d-none" data-project-type-error>Please select at least
                                one project type.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Upload Documents</label>
                            <div class="upload-grid">
                                <div class="upload-card">
                                    <div class="upload-card-header">
                                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                                        <div>
                                            <strong>Assessment Report</strong>
                                            <p>Upload the assessment report file.</p>
                                        </div>
                                    </div>
                                    <input type="file" name="assessment_report" class="form-control"
                                        data-summary-input="assessment_report" required>
                                </div>

                                <div class="upload-card">
                                    <div class="upload-card-header">
                                        <i class="bi bi-file-earmark-check" aria-hidden="true"></i>
                                        <div>
                                            <strong>Approved Quotation</strong>
                                            <p>Upload the approved quotation copy.</p>
                                        </div>
                                    </div>
                                    <input type="file" name="approved_quotation" class="form-control"
                                        data-summary-input="approved_quotation" required>
                                </div>

                                <div class="upload-card" data-contract-upload-card hidden>
                                    <div class="upload-card-header">
                                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                        <div>
                                            <strong>Contract</strong>
                                            <p>Upload the signed contract file.</p>
                                        </div>
                                    </div>
                                    <input type="file" name="contract" class="form-control"
                                        data-summary-input="contract" data-contract-upload-input>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="projectDescription" class="form-label">Project Description</label>
                            <textarea name="project_description" id="projectDescription" rows="4" class="form-control"
                                placeholder="Describe the scope of work" data-summary-input="project_description" required></textarea>
                        </div>
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step="3" hidden>
                    <div class="wizard-step-header">
                        <h5 class="mb-1">Schedule</h5>
                        <p class="text-secondary small mb-0">Assign the lead tech, choose the technicians, then set the
                            dates.</p>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="leadTech" class="form-label">Lead Technician</label>
                            <select name="lead_tech" id="leadTech" class="form-select" required data-lead-tech-select>
                                <option value="" selected disabled>Select Lead Technician</option>

                                @foreach ($technicians as $technician)
                                    @if ($technician->role == 'lead_technician')
                                        <option value="{{ $technician->technician_id }}">
                                            {{ $technician->name }}
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-12">
                            <div class="d-flex align-items-center justify-content-between">
                                <label for="technicians" class="form-label mb-0">Technicians</label>

                                {{-- Offered from the start. The schedule below
                                     cannot be filled in until a team exists,
                                     so waiting for dates would mean picking by
                                     hand the very team this is meant to save
                                     you picking. --}}
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                    data-bs-target="#importTeamModal" data-import-team-button>
                                    <i class="bi bi-people me-1" aria-hidden="true"></i>
                                    Import Team
                                </button>
                            </div>
                            <div class="form-text mb-2" data-import-team-hint>
                                Copy a team from another project. Once you set the schedule below, anyone who is
                                already booked over those dates is flagged here.
                            </div>

                            <div class="technician-picker" data-technician-picker>
                                <div class="dropdown w-100">
                                    {{-- Closing only on an outside click keeps the menu up while
                                         the unavailable section is expanded. --}}
                                    <button type="button" class="form-select technician-dropdown-toggle text-start"
                                        id="techniciansDropdown" data-bs-toggle="dropdown"
                                        data-bs-auto-close="outside" aria-expanded="false"
                                        data-technician-dropdown-button>
                                        Select technicians
                                    </button>

                                    <ul class="dropdown-menu technician-dropdown-menu w-100" data-technician-dropdown-menu>
                                        <li class="dropdown-header text-uppercase small text-secondary">Suggested
                                            Technicians</li>

                                        @forelse ($suggestedTechnicians->where('role', 'technician') as $technician)
                                            <li>
                                                <button type="button" class="dropdown-item"
                                                    data-technician-option="{{ $technician->technician_id }}"
                                                    data-technician-name="{{ $technician->name }}">
                                                    {{ $technician->name }}
                                                </button>
                                            </li>
                                        @empty
                                            <li><span class="dropdown-item-text text-secondary">No suggested
                                                    technicians yet.</span></li>
                                        @endforelse

                                        <li class="dropdown-divider"></li>
                                        <li class="dropdown-header text-uppercase small text-secondary">Other
                                            Technicians</li>

                                        @forelse ($otherTechnicians->where('role', 'technician') as $technician)
                                            <li>
                                                <button type="button" class="dropdown-item"
                                                    data-technician-option="{{ $technician->technician_id }}"
                                                    data-technician-name="{{ $technician->name }}">
                                                    {{ $technician->name }}
                                                </button>
                                            </li>
                                        @empty
                                            <li><span class="dropdown-item-text text-secondary">No other
                                                    technicians available.</span></li>
                                        @endforelse
                                    </ul>
                                </div>

                                <div class="technician-selected-list mt-3" data-technician-selected-list></div>
                                <div class="technician-hidden-inputs" data-technician-hidden-inputs></div>
                            </div>
                        </div>

                        {{-- Partial days are a Residential offering, so the
                             choice is only put in front of the person when it
                             is theirs to make. --}}
                        <div class="col-md-12" data-scheduling-mode-wrap>
                            <label class="form-label">Scheduling Mode</label>

                            <div class="schedule-mode-grid">
                                <label class="schedule-mode-option is-selected" data-scheduling-mode-option>
                                    <input type="radio" name="scheduling_mode"
                                        value="{{ \App\Models\Schedule::MODE_DATE_BASED }}" class="visually-hidden"
                                        data-scheduling-mode-radio checked>
                                    <span class="schedule-mode-icon"><i class="bi bi-calendar-range"
                                            aria-hidden="true"></i></span>
                                    <span>
                                        <strong>Date-Based</strong>
                                        <small>Books the whole of every day in the range</small>
                                    </span>
                                </label>

                                <label class="schedule-mode-option" data-scheduling-mode-option>
                                    <input type="radio" name="scheduling_mode"
                                        value="{{ \App\Models\Schedule::MODE_PARTIAL_DAY }}" class="visually-hidden"
                                        data-scheduling-mode-radio>
                                    <span class="schedule-mode-icon"><i class="bi bi-clock-history"
                                            aria-hidden="true"></i></span>
                                    <span>
                                        <strong>Partial Day</strong>
                                        <small>Books set hours on a single date</small>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6" data-date-based-field>
                            <label for="startDate" class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="startDate" class="form-control"
                                data-summary-input="start_date" data-schedule-date-input disabled required>
                        </div>

                        <div class="col-md-6" data-date-based-field>
                            <label for="endDate" class="form-label">End Date</label>
                            <input type="date" name="end_date" id="endDate" class="form-control"
                                data-summary-input="end_date" data-schedule-date-input disabled required>
                        </div>

                        <div class="col-md-4" data-partial-day-field hidden>
                            <label for="projectDate" class="form-label">Project Date</label>
                            <input type="date" name="project_date" id="projectDate" class="form-control"
                                data-summary-input="project_date" data-schedule-date-input disabled required>
                            <div class="form-text">Only dates where everyone has a free slot can be picked.</div>
                        </div>

                        <div class="col-md-4" data-partial-day-field hidden>
                            <label for="startTime" class="form-label">Start Time</label>
                            <select name="start_time" id="startTime" class="form-select"
                                data-summary-input="start_time" data-schedule-time-input disabled required>
                                <option value="" selected>Select start time</option>
                                @foreach ($workingHours as $hour)
                                    <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4" data-partial-day-field hidden>
                            <label for="endTime" class="form-label">End Time</label>
                            <select name="end_time" id="endTime" class="form-select"
                                data-summary-input="end_time" data-schedule-time-input disabled required>
                                <option value="" selected>Select end time</option>
                                @foreach ($workingHours as $hour)
                                    <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-12">
                            <div class="form-text text-danger mt-0 d-none" data-schedule-error></div>
                        </div>
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step="4" hidden>
                    <div class="wizard-step-header">
                        <h5 class="mb-1">Review</h5>
                        <p class="text-secondary small mb-0">Check the captured details before saving the project.</p>
                    </div>

                    <div class="review-groups">
                        <div class="review-group">
                            <div class="review-group-header">
                                <span class="review-group-icon"><i class="bi bi-person-vcard"
                                        aria-hidden="true"></i></span>
                                <h6 class="mb-0">Client Information</h6>
                            </div>
                            <div class="review-group-body">
                                <div class="review-item">
                                    <span class="review-item-label">Client Type</span>
                                    <span class="review-item-value" data-summary-target="client_type">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item">
                                    <span class="review-item-label">Company Name</span>
                                    <span class="review-item-value" data-summary-target="company_name"
                                        data-company-review-card>Not filled yet</span>
                                </div>
                                <div class="review-item">
                                    <span class="review-item-label">Client Name</span>
                                    <span class="review-item-value" data-summary-target="client_name">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item">
                                    <span class="review-item-label">Email</span>
                                    <span class="review-item-value" data-summary-target="client_email">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item review-item-full">
                                    <span class="review-item-label">Contact Number</span>
                                    <span class="review-item-value" data-summary-target="client_phone">Not filled
                                        yet</span>
                                </div>
                            </div>
                        </div>

                        <div class="review-group">
                            <div class="review-group-header">
                                <span class="review-group-icon"><i class="bi bi-clipboard2-data"
                                        aria-hidden="true"></i></span>
                                <h6 class="mb-0">Project Details</h6>
                            </div>
                            <div class="review-group-body">
                                <div class="review-item review-item-full">
                                    <span class="review-item-label">Project Address</span>
                                    <span class="review-item-value" data-summary-target="project_address">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item">
                                    <span class="review-item-label">Quotation Amount</span>
                                    <span class="review-item-value" data-summary-target="quotation_amount">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item">
                                    <span class="review-item-label">Project Types</span>
                                    <span class="review-item-value" data-summary-target="project_types">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item review-item-full">
                                    <span class="review-item-label">Documents</span>
                                    <span class="review-item-value" data-summary-target="project_documents">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item review-item-full">
                                    <span class="review-item-label">Project Description</span>
                                    <span class="review-item-value" data-summary-target="project_description">Not filled
                                        yet</span>
                                </div>
                            </div>
                        </div>

                        <div class="review-group">
                            <div class="review-group-header">
                                <span class="review-group-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                                <h6 class="mb-0">Schedule &amp; Team</h6>
                            </div>
                            <div class="review-group-body">
                                <div class="review-item">
                                    <span class="review-item-label">Lead Tech</span>
                                    <span class="review-item-value" data-summary-target="lead_tech">Not filled yet</span>
                                </div>
                                <div class="review-item">
                                    <span class="review-item-label">Scheduling Mode</span>
                                    <span class="review-item-value" data-summary-target="scheduling_mode">Date-Based</span>
                                </div>
                                <div class="review-item">
                                    <span class="review-item-label">Schedule</span>
                                    <span class="review-item-value" data-summary-target="schedule_range">Not filled
                                        yet</span>
                                </div>
                                <div class="review-item review-item-full">
                                    <span class="review-item-label">Technicians</span>
                                    <span class="review-item-value" data-summary-target="technicians">Not filled
                                        yet</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="wizard-footer">
                    <button type="button" class="btn btn-outline-secondary px-4" data-wizard-back disabled>
                        Back
                    </button>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary px-4" data-wizard-next>
                            Next
                        </button>

                        <button type="submit" class="btn btn-success px-4 d-none" data-wizard-submit>
                            Create Project
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <x-import-team-modal />

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script>
            window.projectWizardData = @json($technicianWizardData);
        </script>
        <script src="/js/importTeam.js"></script>
        <script src="/js/super-admin/createProject.js"></script>
    @endpush
@endsection
