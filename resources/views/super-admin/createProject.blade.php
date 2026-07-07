@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/createProject.css" rel="stylesheet">
    <link href="/css/super-admin/createProjectProgress.css" rel="stylesheet">
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
                        <div class="col-md-4">
                            <label for="surname" class="form-label">Surname</label>
                            <input type="text" name="surname" id="surname" class="form-control"
                                placeholder="Enter surname" data-summary-input="surname" required>
                        </div>

                        <div class="col-md-4">
                            <label for="firstname" class="form-label">First Name</label>
                            <input type="text" name="firstname" id="firstname" class="form-control"
                                placeholder="Enter first name" data-summary-input="firstname" required>
                        </div>

                        <div class="col-md-4">
                            <label for="middleName" class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" id="middleName" class="form-control"
                                placeholder="Enter middle name" data-summary-input="middle_name">
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

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                                <label class="form-label mb-0">Project Type</label>
                                <small class="text-secondary">Select one or more</small>
                            </div>

                            <div class="project-type-grid" data-project-type-group>
                                <label class="project-type-option is-selected" data-project-type-option>
                                    <input type="checkbox" name="project_types[]" value="Aircon Installation"
                                        class="visually-hidden" data-project-type-checkbox
                                        data-label="Aircon Installation" checked>
                                    <span class="project-type-icon"><i class="bi bi-snow" aria-hidden="true"></i></span>
                                    <span class="project-type-name">Aircon Installation</span>
                                </label>

                                <label class="project-type-option" data-project-type-option>
                                    <input type="checkbox" name="project_types[]" value="Aircon Repair"
                                        class="visually-hidden" data-project-type-checkbox data-label="Aircon Repair">
                                    <span class="project-type-icon"><i class="bi bi-wrench"
                                            aria-hidden="true"></i></span>
                                    <span class="project-type-name">Aircon Repair</span>
                                </label>

                                <label class="project-type-option" data-project-type-option>
                                    <input type="checkbox" name="project_types[]" value="Aircon Cleaning"
                                        class="visually-hidden" data-project-type-checkbox data-label="Aircon Cleaning">
                                    <span class="project-type-icon"><i class="bi bi-stars" aria-hidden="true"></i></span>
                                    <span class="project-type-name">Aircon Cleaning</span>
                                </label>

                                <label class="project-type-option" data-project-type-option>
                                    <input type="checkbox" name="project_types[]" value="Ducting Fabrication"
                                        class="visually-hidden" data-project-type-checkbox
                                        data-label="Ducting Fabrication">
                                    <span class="project-type-icon"><i class="bi bi-scissors"
                                            aria-hidden="true"></i></span>
                                    <span class="project-type-name">Ducting Fabrication</span>
                                </label>

                                <label class="project-type-option" data-project-type-option>
                                    <input type="checkbox" name="project_types[]" value="Ducting Installation"
                                        class="visually-hidden" data-project-type-checkbox
                                        data-label="Ducting Installation">
                                    <span class="project-type-icon"><i class="bi bi-box-seam"
                                            aria-hidden="true"></i></span>
                                    <span class="project-type-name">Ducting Installation</span>
                                </label>
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

                                <div class="upload-card">
                                    <div class="upload-card-header">
                                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                        <div>
                                            <strong>Contract</strong>
                                            <p>Upload the signed contract file.</p>
                                        </div>
                                    </div>
                                    <input type="file" name="contract" class="form-control"
                                        data-summary-input="contract" required>
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
                            <select name="lead_tech" id="leadTech" class="form-select" required>
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

                                    <ul class="dropdown-menu technician-dropdown-menu w-100">
                                        @foreach ($technicians as $technician)
                                            @if ($technician->role == 'technician')
                                                <li>
                                                    <button type="button" class="dropdown-item"
                                                        data-technician-option="{{ $technician->name}}"
                                                        data-technician-name="{{ $technician->name }}">
                                                        {{ $technician->name }}
                                                    </button>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </div>

                                <div class="technician-selected-list mt-3" data-technician-selected-list></div>
                                <div class="technician-hidden-inputs" data-technician-hidden-inputs></div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="startDate" class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="startDate" class="form-control"
                                data-summary-input="start_date" required>
                        </div>

                        <div class="col-md-6">
                            <label for="endDate" class="form-label">End Date</label>
                            <input type="date" name="end_date" id="endDate" class="form-control"
                                data-summary-input="end_date" required>
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
                            <div class="review-card-value" data-summary-target="company_name">Not filled yet</div>
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
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.querySelector('[data-project-wizard]');

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
                const projectTypeCheckboxes = Array.from(form.querySelectorAll('[data-project-type-checkbox]'));
                const projectTypeError = form.querySelector('[data-project-type-error]');
                const technicianPicker = form.querySelector('[data-technician-picker]');
                const technicianDropdownButton = form.querySelector('[data-technician-dropdown-button]');
                const technicianDropdownMenu = form.querySelector('[data-technician-dropdown-menu]');
                const technicianSelectedList = form.querySelector('[data-technician-selected-list]');
                const technicianHiddenInputs = form.querySelector('[data-technician-hidden-inputs]');
                const technicianOptions = Array.from(form.querySelectorAll('[data-technician-option]'));
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

                function updateSelectedState(inputs, optionSelector) {
                    inputs.forEach(function(input) {
                        const option = input.closest(optionSelector);

                        if (option) {
                            option.classList.toggle('is-selected', input.checked || input.value.trim() !== '');
                        }
                    });
                }

                function updateClientType() {
                    const commercialSelected = form.querySelector('[data-client-type-radio][value="Commercial"]')
                        ?.checked;

                    clientTypeOptions.forEach(function(option) {
                        const input = option.querySelector('input');
                        option.classList.toggle('is-selected', Boolean(input && input.checked));
                    });

                    if (companyWrap) {
                        companyWrap.hidden = !commercialSelected;
                    }

                    if (companyInput) {
                        companyInput.required = Boolean(commercialSelected);

                        if (!commercialSelected) {
                            companyInput.value = '';
                        }
                    }
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

                function selectedTechnicians() {
                    if (!technicianHiddenInputs) {
                        return [];
                    }

                    return Array.from(technicianHiddenInputs.querySelectorAll('input[type="hidden"]')).map(function(
                        input) {
                        return input.value;
                    });
                }

                function updateTechnicianDropdownButton() {
                    if (!technicianDropdownButton) {
                        return;
                    }

                    const selected = selectedTechnicians();
                    technicianDropdownButton.textContent = selected.length ? selected.length + ' selected' :
                        'Select technicians';
                }

                function syncTechnicianMenuState() {
                    const selected = selectedTechnicians();

                    technicianOptions.forEach(function(button) {
                        const value = button.dataset.technicianOption || button.textContent.trim();
                        button.classList.toggle('active', selected.includes(value));
                        button.setAttribute('aria-pressed', String(selected.includes(value)));
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
                        selected.forEach(function(technicianName) {
                            const chip = document.createElement('span');
                            chip.className = 'technician-chip';
                            chip.textContent = technicianName;

                            const removeButton = document.createElement('button');
                            removeButton.type = 'button';
                            removeButton.className = 'technician-chip-remove';
                            removeButton.setAttribute('aria-label', 'Remove ' + technicianName);
                            removeButton.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';
                            removeButton.addEventListener('click', function() {
                                removeTechnician(technicianName);
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

                    if (selectedTechnicians().includes(technicianName)) {
                        return;
                    }

                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'technicians[]';
                    hiddenInput.value = technicianName;
                    technicianHiddenInputs.appendChild(hiddenInput);
                    renderTechnicianChips();
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

                    renderTechnicianChips();
                }

                function selectedFiles() {
                    return ['assessment_report', 'approved_quotation', 'contract'].map(function(fieldName) {
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

                    return true;
                }

                function syncSelectableCards() {
                    clientTypeRadios.forEach(function(radio) {
                        const option = radio.closest('[data-client-type-option]');

                        if (option) {
                            option.classList.toggle('is-selected', radio.checked);
                        }
                    });

                    projectTypeCheckboxes.forEach(function(checkbox) {
                        const option = checkbox.closest('[data-project-type-option]');

                        if (option) {
                            option.classList.toggle('is-selected', checkbox.checked);
                        }
                    });
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
                        updateClientType();
                        updateSummary();
                    });
                });

                projectTypeCheckboxes.forEach(function(checkbox) {
                    checkbox.addEventListener('change', function() {
                        syncSelectableCards();

                        if (projectTypeError) {
                            projectTypeError.classList.add('d-none');
                        }

                        updateSummary();
                    });
                });

                technicianOptions.forEach(function(button) {
                    button.addEventListener('click', function() {
                        const technicianName = button.dataset.technicianOption || button.textContent
                            .trim();
                        addTechnician(technicianName);
                    });
                });

                form.addEventListener('input', function() {
                    updateSummary();
                });

                form.addEventListener('change', function() {
                    updateClientType();
                    updateSummary();
                });

                syncSelectableCards();
                updateClientType();
                renderTechnicianChips();
                updateSummary();
                setStep(1);
            });
        </script>
    @endpush
@endsection
