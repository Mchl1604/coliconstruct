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
    const scheduleError = form.querySelector('[data-schedule-error]');
    const contractUploadCard = form.querySelector('[data-contract-upload-card]');
    const contractUploadInput = form.querySelector('[data-contract-upload-input]');
    const companyReviewCard = document.querySelector('[data-company-review-card]');
    let startPicker = null;
    let endPicker = null;
    let activeBusyRanges = [];
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

    // Inclusive list of 'YYYY-MM-DD' strings between two date strings.
    function eachDate(fromValue, toValue) {
        const dates = [];
        const cursor = new Date(fromValue + 'T00:00:00');
        const end = new Date(toValue + 'T00:00:00');

        if (Number.isNaN(cursor.getTime()) || Number.isNaN(end.getTime())) {
            return dates;
        }

        while (cursor <= end) {
            const year = String(cursor.getFullYear());
            const month = String(cursor.getMonth() + 1).padStart(2, '0');
            const day = String(cursor.getDate()).padStart(2, '0');

            dates.push(year + '-' + month + '-' + day);
            cursor.setDate(cursor.getDate() + 1);
        }

        return dates;
    }

    // Mirrors TechnicianAvailabilityService on the server: a range is only
    // valid when every selected technician is free on EVERY day inside it,
    // so a range whose endpoints are free but which spans a busy day in the
    // middle is still rejected.
    function scheduleConflicts(startValue, endValue) {
        if (!startValue || !endValue || startValue > endValue) {
            return [];
        }

        const selectedDays = eachDate(startValue, endValue);

        if (!selectedDays.length) {
            return [];
        }

        return activeTechnicianIds().reduce(function(conflicts, technicianId) {
            const technician = technicianLookup.get(String(technicianId));

            if (!technician || !Array.isArray(technician.schedules)) {
                return conflicts;
            }

            const busyDays = new Set();

            technician.schedules.forEach(function(range) {
                eachDate(range.start, range.end).forEach(function(day) {
                    busyDays.add(day);
                });
            });

            const hits = selectedDays.filter(function(day) {
                return busyDays.has(day);
            });

            if (hits.length) {
                conflicts.push({ name: technician.name, dates: hits });
            }

            return conflicts;
        }, []);
    }

    function formatDateList(dates) {
        const currentYear = new Date().getFullYear();
        const allCurrentYear = dates.every(function(date) {
            return Number(date.slice(0, 4)) === currentYear;
        });

        const shown = dates.slice(0, 8);
        const remaining = dates.length - shown.length;

        const labels = shown.map(function(date) {
            const parsed = new Date(date + 'T00:00:00');

            return parsed.toLocaleDateString(undefined, allCurrentYear
                ? { month: 'long', day: 'numeric' }
                : { month: 'long', day: 'numeric', year: 'numeric' });
        });

        if (remaining > 0) {
            labels.push(remaining + ' more date' + (remaining === 1 ? '' : 's'));
        }

        if (labels.length === 1) {
            return labels[0];
        }

        if (labels.length === 2) {
            return labels[0] + ' and ' + labels[1];
        }

        const last = labels.pop();

        return labels.join(', ') + ', and ' + last;
    }

    function conflictMessage(conflicts) {
        if (!conflicts.length) {
            return '';
        }

        return conflicts.map(function(conflict) {
            return 'Technician ' + conflict.name + ' is unavailable on ' + formatDateList(conflict.dates) + '.';
        }).join(' ') + ' Please select a continuous date range where all selected technicians are available.';
    }

    function recomputeActiveBusyRanges() {
        activeBusyRanges = busyRangesForTechnicians(activeTechnicianIds());
    }

    function disableRangesForFlatpickr() {
        return activeBusyRanges.map(function(range) {
            return { from: range.start, to: range.end };
        });
    }

    function refreshDatePickers() {
        const enabled = scheduleInputsReady();

        if (startDateInput) {
            startDateInput.disabled = !enabled;
        }

        if (endDateInput) {
            endDateInput.disabled = !enabled;
        }

        recomputeActiveBusyRanges();

        const disableRanges = disableRangesForFlatpickr();

        if (startPicker) {
            startPicker.set('disable', disableRanges);
        }

        if (endPicker) {
            endPicker.set('disable', disableRanges);

            if (startPicker && startPicker.selectedDates[0]) {
                endPicker.set('minDate', startPicker.selectedDates[0]);
            } else {
                endPicker.set('minDate', 'today');
            }
        }

        if (!enabled) {
            resetScheduleDates();
        } else if (!isScheduleRangeAvailable(startDateInput && startDateInput.value, endDateInput && endDateInput.value)) {
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
            minDate: 'today',
            onChange: function(selectedDates, dateStr, instance) {
                if (endPicker) {
                    endPicker.clear(false);
                }

                refreshDatePickers();
                validateScheduleInputs();
            },
        });

        endPicker = window.flatpickr(endDateInput, {
            dateFormat: 'Y-m-d',
            allowInput: true,
            minDate: 'today',
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

        return scheduleConflicts(startValue, endValue).length === 0;
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
            companyReviewCard.closest('.review-item')?.classList.toggle('d-none', !commercialSelected);
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

        const suggestedIds = new Set(suggested.map(function(technician) {
            return technician.id;
        }));

        const other = wizardData
            .filter(function(technician) {
                return technician.role === 'technician' && !suggestedIds.has(technician.id);
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

                if (selectedTechnicianIds().includes(String(technicianId))) {
                    removeTechnician(technicianId);
                } else {
                    addTechnician(technicianId);
                }
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

        renderTechnicianChips();
        updateScheduleFieldState();
    }

    function resetScheduleDates() {
        if (startPicker) {
            startPicker.clear(false);
        }

        if (endPicker) {
            endPicker.clear(false);
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
        refreshDatePickers();
    }

    function showScheduleError(message) {
        if (!scheduleError) {
            return;
        }

        scheduleError.textContent = message;
        scheduleError.classList.toggle('d-none', !message);
    }

    function validateScheduleInputs() {
        if (!startDateInput || !endDateInput || startDateInput.disabled || endDateInput.disabled) {
            showScheduleError('');
            return true;
        }

        startDateInput.setCustomValidity('');
        endDateInput.setCustomValidity('');

        if (!startDateInput.value || !endDateInput.value) {
            showScheduleError('');
            return true;
        }

        const conflicts = scheduleConflicts(startDateInput.value, endDateInput.value);

        if (conflicts.length) {
            const message = conflictMessage(conflicts);
            startDateInput.setCustomValidity(message);
            endDateInput.setCustomValidity(message);
            showScheduleError(message);
            return false;
        }

        showScheduleError('');

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

        const leadTechnician = selectedLeadTechnician();
        const technicianNames = selectedTechnicians().map(function(technician) {
            return technician.name;
        });

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
            lead_tech: leadTechnician ? leadTechnician.name : 'Not filled yet',
            technicians: technicianNames.length ? technicianNames.join(', ') : 'Not filled yet',
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
            companyReviewCard.closest('.review-item')?.classList.toggle('d-none', !commercialSelected);
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
            updateScheduleFieldState();
            validateScheduleInputs();
            updateSummary();
        });
    }

    // Last line of defence: the review step can be reached before a technician
    // change invalidates the chosen dates, so re-check on submit too. The
    // server enforces the same rule regardless.
    form.addEventListener('submit', function(event) {
        if (!validateScheduleInputs()) {
            event.preventDefault();
            setStep(3);

            if (startDateInput) {
                startDateInput.reportValidity();
            }
        }
    });

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