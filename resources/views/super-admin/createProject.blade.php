@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/createProject.css" rel="stylesheet">
    <link href="/css/super-admin/createProjectProgress.css" rel="stylesheet">
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

    <div class="card shadow-sm border-0 rounded-4 create-project-card">
        <div class="card-body p-4 p-lg-5">
            @php
                $technicianWizardData = $technicians->map(function ($technician) use ($technicianSchedules) {
                    return [
                        'id' => $technician->technician_id,
                        'name' => $technician->name,
                        'role' => $technician->role,
                        'skills' => $technician->skill_names,
                        'schedules' => $technicianSchedules[$technician->technician_id] ?? [],
                    ];
                })->values();
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
                                <input type="number" name="quotation_amount" id="quotationAmount"
                                    class="form-control" min="0" step="0.01" inputmode="decimal"
                                    placeholder="Enter quotation amount" data-summary-input="quotation_amount"
                                    required>
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
                                        <span class="project-type-icon"><i
                                                class="bi bi-{{ $projectType->icon_class }}" aria-hidden="true"></i></span>
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
                            <label for="technicians" class="form-label">Technicians</label>
                            <div class="technician-picker" data-technician-picker>
                                <div class="dropdown w-100">
                                    <button type="button" class="form-select technician-dropdown-toggle text-start"
                                        id="techniciansDropdown" data-bs-toggle="dropdown" aria-expanded="false"
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

                        <div class="col-md-6">
                            <label for="startDate" class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="startDate" class="form-control"
                                data-summary-input="start_date" data-schedule-date-input disabled required>
                        </div>

                        <div class="col-md-6">
                            <label for="endDate" class="form-label">End Date</label>
                            <input type="date" name="end_date" id="endDate" class="form-control"
                                data-summary-input="end_date" data-schedule-date-input disabled required>
                        </div>
                    </div>
                </section>

                <section class="wizard-step" data-wizard-step="4" hidden>
                    <div class="wizard-step-header">
                        <h5 class="mb-1">Review</h5>
                        <p class="text-secondary small mb-0">Check the captured details before saving the project.</p>
                    </div>

                    <div class="review-grid">
                        <div class="review-card">
                            <div class="review-card-label">Client Type</div>
                            <div class="review-card-value" data-summary-target="client_type">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Company Name</div>
                            <div class="review-card-value" data-summary-target="company_name"
                                data-company-review-card>Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Client Name</div>
                            <div class="review-card-value" data-summary-target="client_name">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Email</div>
                            <div class="review-card-value" data-summary-target="client_email">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Contact Number</div>
                            <div class="review-card-value" data-summary-target="client_phone">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Project Address</div>
                            <div class="review-card-value" data-summary-target="project_address">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Quotation Amount</div>
                            <div class="review-card-value" data-summary-target="quotation_amount">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Project Types</div>
                            <div class="review-card-value" data-summary-target="project_types">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Documents</div>
                            <div class="review-card-value" data-summary-target="project_documents">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Lead Tech</div>
                            <div class="review-card-value" data-summary-target="lead_tech">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Technicians</div>
                            <div class="review-card-value" data-summary-target="technicians">Not filled yet</div>
                        </div>
                        <div class="review-card">
                            <div class="review-card-label">Schedule</div>
                            <div class="review-card-value" data-summary-target="schedule_range">Not filled yet</div>
                        </div>
                        <div class="review-card review-card-full">
                            <div class="review-card-label">Project Description</div>
                            <div class="review-card-value" data-summary-target="project_description">Not filled yet</div>
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

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script>
            window.projectWizardData = @json($technicianWizardData);

            document.addEventListener('DOMContentLoaded', function() {
                const form = document.querySelector('[data-project-wizard]');
                const wizardData = Array.isArray(window.projectWizardData) ? window.projectWizardData : [];
                const technicianLookup = new Map(wizardData.map(function(technician) {
                    return [String(technician.id), technician];
                }));

                if (!form) {
                    return;
                }

                const steps = Array.from(form.querySelectorAll('[data-wizard-step]'));
                const progressSteps = Array.from(document.querySelectorAll('[data-progress-step]'));
                const stepCounter = document.querySelector('[data-step-counter]');
                const backButton = form.querySelector('[data-wizard-back]');
                const nextButton = form.querySelector('[data-wizard-next]');
                const submitButton = form.querySelector('[data-wizard-submit]');
                const clientTypeOptions = Array.from(form.querySelectorAll('[data-client-type-option]'));
                const clientTypeRadios = Array.from(form.querySelectorAll('[data-client-type-radio]'));
                const companyWrap = form.querySelector('[data-company-name-wrap]');
                const companyInput = form.querySelector('[data-summary-input="company_name"]');
                const quotationAmountInput = form.querySelector('[data-summary-input="quotation_amount"]');
                const projectTypeCheckboxes = Array.from(form.querySelectorAll('[data-project-type-checkbox]'));
                const projectTypeError = form.querySelector('[data-project-type-error]');
                const technicianPicker = form.querySelector('[data-technician-picker]');
                const technicianDropdownButton = form.querySelector('[data-technician-dropdown-button]');
                const technicianDropdownMenu = form.querySelector('[data-technician-dropdown-menu]');
                const technicianSelectedList = form.querySelector('[data-technician-selected-list]');
                const technicianHiddenInputs = form.querySelector('[data-technician-hidden-inputs]');
                const leadTechSelect = form.querySelector('[data-lead-tech-select]');
                const startDateInput = form.querySelector('[data-summary-input="start_date"]');
                const endDateInput = form.querySelector('[data-summary-input="end_date"]');
                const contractUploadCard = form.querySelector('[data-contract-upload-card]');
                const contractUploadInput = form.querySelector('[data-contract-upload-input]');
                const companyReviewCard = document.querySelector('[data-company-review-card]');
                let startPicker = null;
                let endPicker = null;
                const currentStep = {
                    value: 1
                };

                function getStepElement(stepNumber) {
                    return steps.find(function(step) {
                        return Number(step.dataset.wizardStep) === stepNumber;
                    });
                }

                function formatFieldValue(field) {
                    if (!field) {
                        return 'Not filled yet';
                    }

                    if (field.disabled) {
                        return 'Not filled yet';
                    }

                    if (field.type === 'file') {
                        return field.files && field.files[0] ? field.files[0].name : 'Not uploaded yet';
                    }

                    const value = field.value.trim();

                    if (!value) {
                        return 'Not filled yet';
                    }

                    if (field.type === 'date') {
                        return new Date(`${value}T00:00:00`).toLocaleDateString();
                    }

                    return value;
                }

                function selectedTechnicianIdsFromInputs() {
                    if (!technicianHiddenInputs) {
                        return [];
                    }

                    return Array.from(technicianHiddenInputs.querySelectorAll('input[type="hidden"]')).map(function(input) {
                        return input.value;
                    });
                }

                function activeTechnicianIds() {
                    const leadTechnicianId = leadTechSelect && leadTechSelect.value ? String(leadTechSelect.value) : null;

                    return Array.from(new Set([
                        ...(leadTechnicianId ? [leadTechnicianId] : []),
                        ...selectedTechnicianIdsFromInputs(),
                    ]));
                }

                function normalizeDateString(value) {
                    if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
                        return null;
                    }

                    const year = String(value.getFullYear());
                    const month = String(value.getMonth() + 1).padStart(2, '0');
                    const day = String(value.getDate()).padStart(2, '0');

                    return year + '-' + month + '-' + day;
                }

                function busyRangesForTechnicians(technicianIds) {
                    return technicianIds.flatMap(function(technicianId) {
                        const technician = technicianLookup.get(String(technicianId));

                        if (!technician || !Array.isArray(technician.schedules)) {
                            return [];
                        }

                        return technician.schedules.map(function(range) {
                            return {
                                start: range.start,
                                end: range.end,
                            };
                        });
                    });
                }

                function overlapsBusyRanges(startValue, endValue, busyRanges) {
                    return busyRanges.some(function(range) {
                        return startValue <= range.end && endValue >= range.start;
                    });
                }

                function startDateDisabled(date) {
                    const dateString = normalizeDateString(date);
                    const busyRanges = busyRangesForTechnicians(activeTechnicianIds());

                    return busyRanges.some(function(range) {
                        return dateString >= range.start && dateString <= range.end;
                    });
                }

                function endDateDisabled(date) {
                    const dateString = normalizeDateString(date);
                    const startValue = startPicker && startPicker.selectedDates[0]
                        ? normalizeDateString(startPicker.selectedDates[0])
                        : null;
                    const busyRanges = busyRangesForTechnicians(activeTechnicianIds());

                    if (!startValue) {
                        return busyRanges.some(function(range) {
                            return dateString >= range.start && dateString <= range.end;
                        });
                    }

                    return overlapsBusyRanges(startValue, dateString, busyRanges);
                }

                function refreshDatePickers() {
                    const enabled = scheduleInputsReady();

                    if (startDateInput) {
                        startDateInput.disabled = !enabled;
                    }

                    if (endDateInput) {
                        endDateInput.disabled = !enabled;
                    }

                    if (startPicker) {
                        startPicker.set('disable', [startDateDisabled]);
                    }

                    if (endPicker) {
                        endPicker.set('disable', [endDateDisabled]);

                        if (startPicker && startPicker.selectedDates[0]) {
                            endPicker.set('minDate', startPicker.selectedDates[0]);
                        } else {
                            endPicker.set('minDate', null);
                        }
                    }

                    if (!enabled) {
                        resetScheduleDates();
                    }
                }

                function initializeDatePickers() {
                    if (!window.flatpickr || !startDateInput || !endDateInput) {
                        return;
                    }

                    startPicker = window.flatpickr(startDateInput, {
                        dateFormat: 'Y-m-d',
                        allowInput: true,
                        disable: [startDateDisabled],
                        onChange: function(selectedDates, dateStr, instance) {
                            if (endPicker) {
                                endPicker.clear();
                            }

                            refreshDatePickers();
                            validateScheduleInputs();
                        },
                    });

                    endPicker = window.flatpickr(endDateInput, {
                        dateFormat: 'Y-m-d',
                        allowInput: true,
                        disable: [endDateDisabled],
                        onChange: function() {
                            validateScheduleInputs();
                        },
                    });

                    refreshDatePickers();
                }

                function isScheduleRangeAvailable(startValue, endValue) {
                    if (!startValue || !endValue) {
                        return true;
                    }

                    const busyRanges = busyRangesForTechnicians(activeTechnicianIds());

                    return !overlapsBusyRanges(startValue, endValue, busyRanges);
                }

                function selectedProjectTypes() {
                    return projectTypeCheckboxes
                        .filter(function(checkbox) {
                            return checkbox.checked;
                        })
                        .map(function(checkbox) {
                            return checkbox.dataset.label || checkbox.value;
                        });
                }

                function selectedTechnicianIds() {
                    if (!technicianHiddenInputs) {
                        return [];
                    }

                    return Array.from(technicianHiddenInputs.querySelectorAll('input[type="hidden"]')).map(function(input) {
                        return input.value;
                    });
                }

                function selectedTechnicians() {
                    return selectedTechnicianIds()
                        .map(function(technicianId) {
                            return technicianLookup.get(String(technicianId));
                        })
                        .filter(Boolean);
                }

                function selectedLeadTechnician() {
                    if (!leadTechSelect || !leadTechSelect.value) {
                        return null;
                    }

                    return technicianLookup.get(String(leadTechSelect.value)) || null;
                }

                function updateSelectedState() {
                    clientTypeOptions.forEach(function(option) {
                        const input = option.querySelector('input');
                        option.classList.toggle('is-selected', Boolean(input && input.checked));
                    });

                    projectTypeCheckboxes.forEach(function(checkbox) {
                        const option = checkbox.closest('[data-project-type-option]');

                        if (option) {
                            option.classList.toggle('is-selected', checkbox.checked);
                        }
                    });
                }

                function updateClientType() {
                    const commercialSelected = form.querySelector('[data-client-type-radio][value="Commercial"]')?.checked;

                    if (companyWrap) {
                        companyWrap.hidden = !commercialSelected;
                    }

                    if (companyInput) {
                        companyInput.required = Boolean(commercialSelected);
                        companyInput.disabled = !commercialSelected;

                        if (!commercialSelected) {
                            companyInput.value = '';
                        }
                    }

                    if (contractUploadCard) {
                        contractUploadCard.hidden = !commercialSelected;
                    }

                    if (contractUploadInput) {
                        contractUploadInput.required = Boolean(commercialSelected);
                        contractUploadInput.disabled = !commercialSelected;

                        if (!commercialSelected) {
                            contractUploadInput.value = '';
                        }
                    }

                    if (companyReviewCard) {
                        companyReviewCard.closest('.review-card')?.classList.toggle('d-none', !commercialSelected);
                    }
                }

                function technicianMatchesProjectTypes(technician, projectTypes) {
                    if (!technician || !Array.isArray(technician.skills)) {
                        return false;
                    }

                    return projectTypes.some(function(projectType) {
                        return technician.skills.includes(projectType);
                    });
                }

                function getTechnicianSections() {
                    const projectTypes = selectedProjectTypes();
                    const selectedIds = selectedTechnicianIds();

                    const suggested = wizardData
                        .filter(function(technician) {
                            return technician.role === 'technician' && technicianMatchesProjectTypes(technician, projectTypes);
                        })
                        .sort(function(left, right) {
                            const leftMatches = left.skills.filter(function(skill) {
                                return projectTypes.includes(skill);
                            }).length;

                            const rightMatches = right.skills.filter(function(skill) {
                                return projectTypes.includes(skill);
                            }).length;

                            return rightMatches - leftMatches || left.name.localeCompare(right.name);
                        });

                    const other = wizardData
                        .filter(function(technician) {
                            return technician.role === 'technician'
                                && !suggested.some(function(item) {
                                    return item.id === technician.id;
                                });
                        })
                        .sort(function(left, right) {
                            return left.name.localeCompare(right.name);
                        });

                    return {
                        suggested: suggested,
                        other: other,
                        selectedIds: selectedIds,
                    };
                }

                function updateTechnicianDropdownButton() {
                    if (!technicianDropdownButton) {
                        return;
                    }

                    const selected = selectedTechnicians();
                    technicianDropdownButton.textContent = selected.length ? selected.length + ' selected' :
                        'Select technicians';
                }

                function renderTechnicianDropdown() {
                    if (!technicianDropdownMenu) {
                        return;
                    }

                    const sections = getTechnicianSections();
                    const selectedIds = sections.selectedIds.map(String);

                    const renderButtons = function(technicians) {
                        return technicians.map(function(technician) {
                            const activeClass = selectedIds.includes(String(technician.id)) ? ' active' : '';
                            const pressed = selectedIds.includes(String(technician.id)) ? 'true' : 'false';

                            return '<li><button type="button" class="dropdown-item' + activeClass + '" ' +
                                'data-technician-option="' + technician.id + '" ' +
                                'data-technician-name="' + technician.name + '" aria-pressed="' + pressed + '">' +
                                technician.name + '</button></li>';
                        }).join('');
                    };

                    const suggestedHtml = sections.suggested.length
                        ? renderButtons(sections.suggested)
                        : '<li><span class="dropdown-item-text text-secondary">No suggested technicians yet.</span></li>';

                    const otherHtml = sections.other.length
                        ? renderButtons(sections.other)
                        : '<li><span class="dropdown-item-text text-secondary">No other technicians available.</span></li>';

                    technicianDropdownMenu.innerHTML = [
                        '<li class="dropdown-header text-uppercase small text-secondary">Suggested Technicians</li>',
                        suggestedHtml,
                        '<li><hr class="dropdown-divider"></li>',
                        '<li class="dropdown-header text-uppercase small text-secondary">Other Technicians</li>',
                        otherHtml,
                    ].join('');

                    technicianDropdownMenu.querySelectorAll('[data-technician-option]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            const technicianId = button.dataset.technicianOption || '';
                            addTechnician(technicianId);
                        });
                    });
                }

                function syncTechnicianMenuState() {
                    const selected = selectedTechnicianIds();

                    if (!technicianDropdownMenu) {
                        return;
                    }

                    technicianDropdownMenu.querySelectorAll('[data-technician-option]').forEach(function(button) {
                        const value = button.dataset.technicianOption || button.textContent.trim();
                        const isSelected = selected.includes(value);

                        button.classList.toggle('active', isSelected);
                        button.setAttribute('aria-pressed', String(isSelected));
                    });
                }

                function renderTechnicianChips() {
                    if (!technicianSelectedList || !technicianHiddenInputs) {
                        return;
                    }

                    const selected = selectedTechnicians();
                    technicianSelectedList.innerHTML = '';

                    if (!selected.length) {
                        const emptyState = document.createElement('div');
                        emptyState.className = 'technician-empty-state';
                        emptyState.textContent = 'No technicians selected yet.';
                        technicianSelectedList.appendChild(emptyState);
                    } else {
                        selected.forEach(function(technician) {
                            const chip = document.createElement('span');
                            chip.className = 'technician-chip';
                            chip.textContent = technician.name;

                            const removeButton = document.createElement('button');
                            removeButton.type = 'button';
                            removeButton.className = 'technician-chip-remove';
                            removeButton.setAttribute('aria-label', 'Remove ' + technician.name);
                            removeButton.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';
                            removeButton.addEventListener('click', function() {
                                removeTechnician(String(technician.id));
                            });

                            chip.appendChild(removeButton);
                            technicianSelectedList.appendChild(chip);
                        });
                    }

                    updateTechnicianDropdownButton();
                    syncTechnicianMenuState();
                }

                function addTechnician(technicianName) {
                    if (!technicianHiddenInputs) {
                        return;
                    }

                    if (selectedTechnicianIds().includes(String(technicianName))) {
                        return;
                    }

                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'technicians[]';
                    hiddenInput.value = technicianName;
                    technicianHiddenInputs.appendChild(hiddenInput);
                    resetScheduleDates();
                    refreshDatePickers();
                    renderTechnicianChips();
                    updateScheduleFieldState();
                }

                function removeTechnician(technicianName) {
                    if (!technicianHiddenInputs) {
                        return;
                    }

                    const hiddenInputs = Array.from(technicianHiddenInputs.querySelectorAll('input[type="hidden"]'));
                    const hiddenInput = hiddenInputs.find(function(input) {
                        return input.value === technicianName;
                    });

                    if (hiddenInput) {
                        hiddenInput.remove();
                    }

                    resetScheduleDates();
                    refreshDatePickers();
                    renderTechnicianChips();
                    updateScheduleFieldState();
                }

                function resetScheduleDates() {
                    if (startPicker) {
                        startPicker.clear();
                    }

                    if (endPicker) {
                        endPicker.clear();
                    }

                    if (startDateInput) {
                        startDateInput.value = '';
                        startDateInput.setCustomValidity('');
                    }

                    if (endDateInput) {
                        endDateInput.value = '';
                        endDateInput.setCustomValidity('');
                    }
                }

                function scheduleInputsReady() {
                    return Boolean(selectedLeadTechnician()) && selectedTechnicianIds().length > 0;
                }

                function updateScheduleFieldState() {
                    const enabled = scheduleInputsReady();

                    if (startDateInput) {
                        startDateInput.disabled = !enabled;
                    }

                    if (endDateInput) {
                        endDateInput.disabled = !enabled;
                    }

                    refreshDatePickers();

                    if (!enabled) {
                        resetScheduleDates();
                    }
                }

                function dateRangeOverlaps(leftStart, leftEnd, rightStart, rightEnd) {
                    return leftStart <= rightEnd && leftEnd >= rightStart;
                }

                function selectedBusyRanges() {
                    return activeTechnicianIds().flatMap(function(technicianId) {
                        const technician = technicianLookup.get(String(technicianId));

                        if (!technician || !Array.isArray(technician.schedules)) {
                            return [];
                        }

                        return technician.schedules.map(function(range) {
                            return {
                                start: range.start,
                                end: range.end,
                            };
                        });
                    });
                }

                function scheduleRangeIsAvailable(startValue, endValue) {
                    return isScheduleRangeAvailable(startValue, endValue);
                }

                function validateScheduleInputs() {
                    if (!startDateInput || !endDateInput || startDateInput.disabled || endDateInput.disabled) {
                        return true;
                    }

                    startDateInput.setCustomValidity('');
                    endDateInput.setCustomValidity('');

                    if (!startDateInput.value || !endDateInput.value) {
                        return true;
                    }

                    if (!scheduleRangeIsAvailable(startDateInput.value, endDateInput.value)) {
                        const message = 'Selected dates overlap an existing schedule for one or more technicians.';
                        startDateInput.setCustomValidity(message);
                        endDateInput.setCustomValidity(message);
                        return false;
                    }

                    return true;
                }

                function selectedFiles() {
                    const documentFields = ['assessment_report', 'approved_quotation'];

                    if (contractUploadInput && !contractUploadInput.disabled) {
                        documentFields.push('contract');
                    }

                    return documentFields.map(function(fieldName) {
                        const input = form.querySelector('[data-summary-input="' + fieldName + '"]');

                        if (!input || !input.files || !input.files[0]) {
                            return 'Not uploaded yet';
                        }

                        return input.files[0].name;
                    });
                }

                function updateSummary() {
                    const clientFirstName = form.querySelector('[data-summary-input="firstname"]');
                    const clientMiddleName = form.querySelector('[data-summary-input="middle_name"]');
                    const clientSurname = form.querySelector('[data-summary-input="surname"]');
                    const startDate = form.querySelector('[data-summary-input="start_date"]');
                    const endDate = form.querySelector('[data-summary-input="end_date"]');

                    const summaryMap = {
                        client_type: formatFieldValue(form.querySelector('[data-client-type-radio]:checked')),
                        company_name: formatFieldValue(form.querySelector('[data-summary-input="company_name"]')),
                        client_name: [clientFirstName, clientMiddleName, clientSurname]
                            .map(formatFieldValue)
                            .filter(function(value) {
                                return value !== 'Not filled yet';
                            })
                            .join(' '),
                        client_email: formatFieldValue(form.querySelector('[data-summary-input="client_email"]')),
                        client_phone: formatFieldValue(form.querySelector('[data-summary-input="client_phone"]')),
                        project_address: formatFieldValue(form.querySelector(
                            '[data-summary-input="project_address"]')),
                        quotation_amount: formatFieldValue(quotationAmountInput),
                        project_types: selectedProjectTypes().join(', '),
                        project_documents: selectedFiles().join(', '),
                        project_description: formatFieldValue(form.querySelector(
                            '[data-summary-input="project_description"]')),
                        lead_tech: formatFieldValue(form.querySelector('[data-summary-input="lead_tech"]')),
                        technicians: selectedTechnicians().join(', '),
                        schedule_range: [startDate, endDate]
                            .map(formatFieldValue)
                            .filter(function(value) {
                                return value !== 'Not filled yet';
                            })
                            .join(' to '),
                    };

                    Object.keys(summaryMap).forEach(function(key) {
                        document.querySelectorAll('[data-summary-target="' + key + '"]').forEach(function(
                            target) {
                            target.textContent = summaryMap[key] || 'Not filled yet';
                        });
                    });

                    if (companyReviewCard) {
                        const commercialSelected = form.querySelector('[data-client-type-radio][value="Commercial"]')?.checked;
                        companyReviewCard.closest('.review-card')?.classList.toggle('d-none', !commercialSelected);
                    }
                }

                function setStep(stepNumber) {
                    currentStep.value = stepNumber;

                    steps.forEach(function(step) {
                        const isActive = Number(step.dataset.wizardStep) === stepNumber;
                        step.hidden = !isActive;
                        step.classList.toggle('active', isActive);
                    });

                    progressSteps.forEach(function(step) {
                        const progressStepNumber = Number(step.dataset.progressStep);
                        step.classList.toggle('active', progressStepNumber === stepNumber);
                        step.classList.toggle('completed', progressStepNumber < stepNumber);
                    });

                    stepCounter.textContent = String(stepNumber);
                    backButton.disabled = stepNumber === 1;
                    nextButton.classList.toggle('d-none', stepNumber === steps.length);
                    submitButton.classList.toggle('d-none', stepNumber !== steps.length);
                    nextButton.textContent = stepNumber === steps.length - 1 ? 'Review' : 'Next';
                    updateSummary();
                    validateScheduleInputs();
                }

                function validateActiveStep() {
                    const activeStep = getStepElement(currentStep.value);

                    if (!activeStep) {
                        return false;
                    }

                    const fields = Array.from(activeStep.querySelectorAll('input, select, textarea'));
                    const invalidField = fields.find(function(field) {
                        return !field.checkValidity();
                    });

                    if (invalidField) {
                        invalidField.reportValidity();
                        return false;
                    }

                    if (currentStep.value === 2) {
                        const selectedTypes = selectedProjectTypes();

                        if (selectedTypes.length === 0) {
                            if (projectTypeError) {
                                projectTypeError.classList.remove('d-none');
                            }

                            return false;
                        }

                        if (projectTypeError) {
                            projectTypeError.classList.add('d-none');
                        }
                    }

                    if (currentStep.value === 3 && selectedTechnicians().length === 0) {
                        if (technicianDropdownButton) {
                            technicianDropdownButton.focus();
                        }

                        return false;
                    }

                    if (currentStep.value === 3 && !validateScheduleInputs()) {
                        return false;
                    }

                    return true;
                }

                function syncSelectableCards() {
                    updateSelectedState();
                }

                backButton.addEventListener('click', function() {
                    if (currentStep.value > 1) {
                        setStep(currentStep.value - 1);
                    }
                });

                nextButton.addEventListener('click', function() {
                    if (!validateActiveStep()) {
                        return;
                    }

                    if (currentStep.value < steps.length) {
                        setStep(currentStep.value + 1);
                    }
                });

                clientTypeRadios.forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        syncSelectableCards();
                        updateClientType();
                        updateSummary();
                    });
                });

                projectTypeCheckboxes.forEach(function(checkbox) {
                    checkbox.addEventListener('change', function() {
                        syncSelectableCards();
                        renderTechnicianDropdown();

                        if (projectTypeError) {
                            projectTypeError.classList.add('d-none');
                        }

                        updateSummary();
                    });
                });

                if (leadTechSelect) {
                    leadTechSelect.addEventListener('change', function() {
                        resetScheduleDates();
                        refreshDatePickers();
                        updateScheduleFieldState();
                        validateScheduleInputs();
                        updateSummary();
                    });
                }

                form.addEventListener('input', function() {
                    validateScheduleInputs();
                    updateSummary();
                });

                form.addEventListener('change', function() {
                    updateClientType();
                    updateSummary();
                });

                if (startDateInput) {
                    startDateInput.addEventListener('change', function() {
                        validateScheduleInputs();
                    });
                }

                if (endDateInput) {
                    endDateInput.addEventListener('change', function() {
                        validateScheduleInputs();
                    });
                }

                syncSelectableCards();
                updateClientType();
                renderTechnicianDropdown();
                renderTechnicianChips();
                initializeDatePickers();
                updateScheduleFieldState();
                updateSummary();
                setStep(1);
            });
        </script>
    @endpush
@endsection
