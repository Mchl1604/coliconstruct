document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('[data-project-wizard]');
    const wizardData = Array.isArray(window.projectWizardData) ? window.projectWizardData : [];
    const technicianLookup = new Map(wizardData.map(function(technician) {
        return [String(technician.id), technician];
    }));

    if (!form) {
        return;
    }

    const MODE_DATE_BASED = 'date_based';
    const MODE_PARTIAL_DAY = 'partial_day';
    const MINUTES_PER_DAY = 1440;
    // The working day, mirroring Schedule::WORKING_HOUR_START / _END.
    const WORKING_HOUR_START = 8;
    const WORKING_HOUR_END = 17;

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
    const projectDateInput = form.querySelector('[data-summary-input="project_date"]');
    const startTimeSelect = form.querySelector('[data-summary-input="start_time"]');
    const endTimeSelect = form.querySelector('[data-summary-input="end_time"]');
    const schedulingModeWrap = form.querySelector('[data-scheduling-mode-wrap]');
    const schedulingModeRadios = Array.from(form.querySelectorAll('[data-scheduling-mode-radio]'));
    const schedulingModeOptions = Array.from(form.querySelectorAll('[data-scheduling-mode-option]'));
    const dateBasedFields = Array.from(form.querySelectorAll('[data-date-based-field]'));
    const partialDayFields = Array.from(form.querySelectorAll('[data-partial-day-field]'));
    const scheduleError = form.querySelector('[data-schedule-error]');
    const contractUploadCard = form.querySelector('[data-contract-upload-card]');
    const contractUploadInput = form.querySelector('[data-contract-upload-input]');
    const companyReviewCard = document.querySelector('[data-company-review-card]');
    let startPicker = null;
    let endPicker = null;
    let projectDatePicker = null;
    // Whether the unavailable section of the picker is expanded, kept out here
    // so re-rendering after a pick does not collapse it again.
    let blockedTechniciansOpen = false;
    // Set once the Import Team dialog is wired up; the schedule fields call it
    // as they change, and it is absent until then.
    let refreshImportTeamButton = null;
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

    // -----------------------------------------------------------------
    // Scheduling mode
    // -----------------------------------------------------------------

    function isResidential() {
        const residential = form.querySelector('[data-client-type-radio][value="Residential"]');

        return Boolean(residential && residential.checked);
    }

    function schedulingMode() {
        // Partial days are a Residential offering, so a Commercial project is
        // always read as whole-day whatever the radio happens to hold.
        if (!isResidential()) {
            return MODE_DATE_BASED;
        }

        const checked = schedulingModeRadios.find(function(radio) {
            return radio.checked;
        });

        return checked && checked.value === MODE_PARTIAL_DAY ? MODE_PARTIAL_DAY : MODE_DATE_BASED;
    }

    function isPartialDay() {
        return schedulingMode() === MODE_PARTIAL_DAY;
    }

    // Fields belonging to the mode that is not in use are disabled as well as
    // hidden, so the browser neither validates them nor submits them.
    function setFieldGroupActive(wrappers, inputs, active) {
        wrappers.forEach(function(wrapper) {
            wrapper.hidden = !active;
        });

        inputs.forEach(function(input) {
            if (!input) {
                return;
            }

            input.required = active;

            if (!active) {
                input.disabled = true;
                input.value = '';
            }
        });
    }

    function applySchedulingMode() {
        const partialDay = isPartialDay();

        if (schedulingModeWrap) {
            schedulingModeWrap.hidden = !isResidential();
        }

        schedulingModeOptions.forEach(function(option) {
            const radio = option.querySelector('input');
            option.classList.toggle('is-selected', Boolean(radio && radio.checked));
        });

        setFieldGroupActive(dateBasedFields, [startDateInput, endDateInput], !partialDay);
        setFieldGroupActive(partialDayFields, [projectDateInput, startTimeSelect, endTimeSelect], partialDay);

        refreshDatePickers();
    }

    // -----------------------------------------------------------------
    // Time helpers
    // -----------------------------------------------------------------

    function minutesFromTime(value) {
        if (typeof value !== 'string' || !/^\d{2}:\d{2}$/.test(value)) {
            return null;
        }

        return (Number(value.slice(0, 2)) * 60) + Number(value.slice(3, 5));
    }

    function formatMinutes(minutes) {
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        const suffix = hours >= 12 ? 'PM' : 'AM';
        const display = hours % 12 === 0 ? 12 : hours % 12;

        return display + ':' + String(rest).padStart(2, '0') + ' ' + suffix;
    }

    // Half-open, matching TechnicianAvailabilityService: a booking ending at
    // 10:00 AM and one starting at 10:00 AM do not clash.
    function intervalsOverlap(fromA, toA, fromB, toB) {
        return fromA < toB && fromB < toA;
    }

    function describeInterval(interval) {
        if (interval.from <= 0 && interval.to >= MINUTES_PER_DAY) {
            return 'the whole day';
        }

        return formatMinutes(interval.from) + ' - ' + formatMinutes(interval.to);
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

    /**
     * The minutes a technician is already spoken for on one date.
     *
     * A whole-day booking takes the entire day; a partial day takes only its
     * hours, leaving the rest of that date free for other work.
     */
    function busyIntervalsOn(technician, date) {
        if (!technician || !Array.isArray(technician.schedules) || !date) {
            return [];
        }

        const intervals = [];

        technician.schedules.forEach(function(range) {
            if (range.mode === MODE_PARTIAL_DAY) {
                if (range.start !== date) {
                    return;
                }

                const from = minutesFromTime(range.start_time);
                const to = minutesFromTime(range.end_time);

                if (from !== null && to !== null) {
                    intervals.push({ from: from, to: to });
                }

                return;
            }

            if (date >= range.start && date <= range.end) {
                intervals.push({ from: 0, to: MINUTES_PER_DAY });
            }
        });

        return intervals;
    }

    function isFreeBetween(intervals, from, to) {
        return !intervals.some(function(interval) {
            return intervalsOverlap(from, to, interval.from, interval.to);
        });
    }

    /**
     * Whether at least one bookable hour is still open on this date - which is
     * what decides if the date can be offered at all.
     */
    function hasFreeHourOn(technician, date) {
        const intervals = busyIntervalsOn(technician, date);

        for (let hour = WORKING_HOUR_START; hour < WORKING_HOUR_END; hour++) {
            if (isFreeBetween(intervals, hour * 60, (hour + 1) * 60)) {
                return true;
            }
        }

        return false;
    }

    function activeTechnicians() {
        return activeTechnicianIds()
            .map(function(technicianId) {
                return technicianLookup.get(String(technicianId));
            })
            .filter(Boolean);
    }

    /**
     * Everybody who cannot work the schedule as it currently stands.
     *
     * Whole-day mode reports the dates that clash; partial-day mode reports
     * the hours, so the message can name them.
     */
    function scheduleConflicts() {
        if (isPartialDay()) {
            const date = projectDateInput ? projectDateInput.value : '';
            const from = minutesFromTime(startTimeSelect ? startTimeSelect.value : '');
            const to = minutesFromTime(endTimeSelect ? endTimeSelect.value : '');

            if (!date || from === null || to === null || from >= to) {
                return [];
            }

            return activeTechnicians().reduce(function(conflicts, technician) {
                const clashes = busyIntervalsOn(technician, date).filter(function(interval) {
                    return intervalsOverlap(from, to, interval.from, interval.to);
                });

                if (clashes.length) {
                    conflicts.push({
                        name: technician.name,
                        date: date,
                        // A whole-day booking says everything on its own.
                        labels: clashes.some(function(interval) {
                            return interval.from <= 0 && interval.to >= MINUTES_PER_DAY;
                        })
                            ? ['the whole day']
                            : clashes.map(describeInterval),
                    });
                }

                return conflicts;
            }, []);
        }

        const startValue = startDateInput ? startDateInput.value : '';
        const endValue = endDateInput ? endDateInput.value : '';

        if (!startValue || !endValue || startValue > endValue) {
            return [];
        }

        const selectedDays = eachDate(startValue, endValue);

        if (!selectedDays.length) {
            return [];
        }

        return activeTechnicians().reduce(function(conflicts, technician) {
            const hits = selectedDays.filter(function(day) {
                return busyIntervalsOn(technician, day).length > 0;
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

        if (isPartialDay()) {
            return conflicts.map(function(conflict) {
                return 'Technician ' + conflict.name + ' is already booked ' +
                    conflict.labels.join(' and ') + ' on ' + formatDateList([conflict.date]) + '.';
            }).join(' ') + ' Please choose a time when every selected technician is free.';
        }

        return conflicts.map(function(conflict) {
            return 'Technician ' + conflict.name + ' is unavailable on ' + formatDateList(conflict.dates) + '.';
        }).join(' ') + ' Please select a continuous date range where all selected technicians are available.';
    }

    // -----------------------------------------------------------------
    // Date and time pickers
    // -----------------------------------------------------------------

    function normalizeDateString(value) {
        if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
            return null;
        }

        const year = String(value.getFullYear());
        const month = String(value.getMonth() + 1).padStart(2, '0');
        const day = String(value.getDate()).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    // A whole-day booking needs the day untouched; a partial day only needs
    // one hour of it still open.
    function isDateBlocked(date) {
        const dateString = normalizeDateString(date);

        if (!dateString) {
            return false;
        }

        const technicians = activeTechnicians();

        if (isPartialDay()) {
            return technicians.some(function(technician) {
                return !hasFreeHourOn(technician, dateString);
            });
        }

        return technicians.some(function(technician) {
            return busyIntervalsOn(technician, dateString).length > 0;
        });
    }

    /**
     * Grey out the hours the team cannot actually work.
     *
     * A start time needs the hour beginning there to be free. An end time
     * needs every hour from the chosen start up to it to be free, so a
     * selection can never span somebody else's booking.
     */
    function refreshTimeOptions() {
        if (!startTimeSelect || !endTimeSelect) {
            return;
        }

        const date = projectDateInput ? projectDateInput.value : '';
        const technicians = activeTechnicians();
        const intervalsByTechnician = technicians.map(function(technician) {
            return busyIntervalsOn(technician, date);
        });

        const freeBetween = function(from, to) {
            return intervalsByTechnician.every(function(intervals) {
                return isFreeBetween(intervals, from, to);
            });
        };

        Array.from(startTimeSelect.options).forEach(function(option) {
            if (!option.value) {
                return;
            }

            const from = minutesFromTime(option.value);
            // 5 PM can only ever end a booking.
            const bookable = from !== null && from < WORKING_HOUR_END * 60;

            option.disabled = !date ? false : !(bookable && freeBetween(from, from + 60));
        });

        const startMinutes = minutesFromTime(startTimeSelect.value);

        Array.from(endTimeSelect.options).forEach(function(option) {
            if (!option.value) {
                return;
            }

            const to = minutesFromTime(option.value);

            if (to === null || startMinutes === null) {
                option.disabled = false;

                return;
            }

            option.disabled = to <= startMinutes || (date ? !freeBetween(startMinutes, to) : false);
        });

        // A choice that has just become impossible is dropped rather than
        // left sitting in the field looking valid.
        [startTimeSelect, endTimeSelect].forEach(function(select) {
            const selected = select.options[select.selectedIndex];

            if (selected && selected.disabled) {
                select.value = '';
            }
        });
    }

    function refreshDatePickers() {
        const enabled = scheduleInputsReady();
        const partialDay = isPartialDay();

        [startDateInput, endDateInput].forEach(function(input) {
            if (input) {
                input.disabled = partialDay || !enabled;
            }
        });

        [projectDateInput, startTimeSelect, endTimeSelect].forEach(function(input) {
            if (input) {
                input.disabled = !partialDay || !enabled;
            }
        });

        if (endPicker) {
            if (startPicker && startPicker.selectedDates[0]) {
                endPicker.set('minDate', startPicker.selectedDates[0]);
            } else {
                endPicker.set('minDate', 'today');
            }
        }

        // Every picker re-reads the disable rule, which depends on who is
        // selected and on the mode in force.
        [startPicker, endPicker, projectDatePicker].forEach(function(picker) {
            if (picker) {
                picker.set('disable', [isDateBlocked]);
            }
        });

        if (partialDay) {
            refreshTimeOptions();
        }

        if (!enabled) {
            resetScheduleDates();
        } else if (scheduleConflicts().length) {
            resetScheduleDates();
        }
    }

    function initializeDatePickers() {
        if (!window.flatpickr) {
            return;
        }

        const afterChange = function() {
            refreshDatePickers();
            validateScheduleInputs();
            renderTechnicianDropdown();
            renderLeadTechnicianOptions();
            updateSummary();
        };

        if (startDateInput && endDateInput) {
            startPicker = window.flatpickr(startDateInput, {
                dateFormat: 'Y-m-d',
                allowInput: true,
                minDate: 'today',
                onChange: function() {
                    if (endPicker) {
                        endPicker.clear(false);
                    }

                    afterChange();
                },
            });

            endPicker = window.flatpickr(endDateInput, {
                dateFormat: 'Y-m-d',
                allowInput: true,
                minDate: 'today',
                onChange: afterChange,
            });
        }

        if (projectDateInput) {
            projectDatePicker = window.flatpickr(projectDateInput, {
                dateFormat: 'Y-m-d',
                allowInput: true,
                minDate: 'today',
                onChange: function() {
                    // The hours on offer belong to the date just chosen.
                    if (startTimeSelect) {
                        startTimeSelect.value = '';
                    }

                    if (endTimeSelect) {
                        endTimeSelect.value = '';
                    }

                    afterChange();
                },
            });
        }

        refreshDatePickers();
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

        // Switching to Commercial takes partial days off the table, so the
        // schedule section is put back to whole days.
        if (commercialSelected) {
            const dateBasedRadio = schedulingModeRadios.find(function(radio) {
                return radio.value === MODE_DATE_BASED;
            });

            if (dateBasedRadio) {
                dateBasedRadio.checked = true;
            }
        }

        applySchedulingMode();
    }

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function matchedSkills(technician, projectTypes) {
        if (!technician || !Array.isArray(technician.skills)) {
            return [];
        }

        return technician.skills.filter(function(skill) {
            return projectTypes.includes(skill);
        });
    }

    /**
     * Why this technician cannot take the schedule as it stands, or '' when
     * they can. Empty until enough of the schedule is chosen to judge - there
     * is nothing to be unavailable for before that.
     */
    function unavailableReasonFor(technician) {
        if (!technician || !Array.isArray(technician.schedules)) {
            return '';
        }

        if (isPartialDay()) {
            const date = projectDateInput ? projectDateInput.value : '';
            const from = minutesFromTime(startTimeSelect ? startTimeSelect.value : '');
            const to = minutesFromTime(endTimeSelect ? endTimeSelect.value : '');

            if (!date) {
                return '';
            }

            // With a date but no hours yet, the only question that can be
            // answered is whether any of that day is left at all.
            if (from === null || to === null || from >= to) {
                return hasFreeHourOn(technician, date) ? '' : 'Fully booked on ' + formatDateList([date]);
            }

            const clashes = busyIntervalsOn(technician, date).filter(function(interval) {
                return intervalsOverlap(from, to, interval.from, interval.to);
            });

            return clashes.length
                ? 'Booked ' + clashes.map(describeInterval).join(' and ') + ' on ' + formatDateList([date])
                : '';
        }

        const startValue = startDateInput ? startDateInput.value : '';
        const endValue = endDateInput ? endDateInput.value : '';

        if (!startValue || !endValue || startValue > endValue) {
            return '';
        }

        const hits = eachDate(startValue, endValue).filter(function(day) {
            return busyIntervalsOn(technician, day).length > 0;
        });

        return hits.length ? 'Booked on ' + formatDateList(hits) : '';
    }

    function getTechnicianSections() {
        const projectTypes = selectedProjectTypes();
        const selectedIds = selectedTechnicianIds();

        const candidates = wizardData
            .filter(function(technician) {
                return technician.role === 'technician';
            })
            .map(function(technician) {
                const reason = unavailableReasonFor(technician);

                return {
                    id: technician.id,
                    name: technician.name,
                    matched: matchedSkills(technician, projectTypes),
                    available: reason === '',
                    reason: reason,
                };
            });

        const byMatchThenName = function(left, right) {
            return right.matched.length - left.matched.length || left.name.localeCompare(right.name);
        };

        const free = candidates.filter(function(technician) {
            return technician.available;
        });

        return {
            suggested: free.filter(function(technician) {
                return technician.matched.length > 0;
            }).sort(byMatchThenName),
            other: free.filter(function(technician) {
                return technician.matched.length === 0;
            }).sort(byMatchThenName),
            // Shown for context only - a booked technician cannot be picked.
            blocked: candidates.filter(function(technician) {
                return !technician.available;
            }).sort(byMatchThenName),
            selectedIds: selectedIds,
        };
    }

    /**
     * Lead technicians get the same three bands as the team picker, expressed
     * as optgroups so an unavailable lead can be shown but not chosen.
     */
    function renderLeadTechnicianOptions() {
        if (!leadTechSelect) {
            return;
        }

        const projectTypes = selectedProjectTypes();
        const currentValue = leadTechSelect.value;

        const leads = wizardData
            .filter(function(technician) {
                return technician.role === 'lead_technician';
            })
            .map(function(technician) {
                const reason = unavailableReasonFor(technician);

                return {
                    id: technician.id,
                    name: technician.name,
                    matched: matchedSkills(technician, projectTypes),
                    available: reason === '',
                    reason: reason,
                };
            })
            .sort(function(left, right) {
                return right.matched.length - left.matched.length || left.name.localeCompare(right.name);
            });

        const optionMarkup = function(lead) {
            const label = escapeHtml(lead.name) +
                (lead.matched.length ? ' — ' + escapeHtml(lead.matched.join(', ')) : '') +
                (lead.available ? '' : ' (' + escapeHtml(lead.reason) + ')');
            // Never disable the option already chosen, or the select would lose
            // its value; the schedule conflict message still calls it out.
            const disabled = !lead.available && String(lead.id) !== String(currentValue) ? ' disabled' : '';
            const selected = String(lead.id) === String(currentValue) ? ' selected' : '';

            return '<option value="' + lead.id + '"' + disabled + selected + '>' + label + '</option>';
        };

        const group = function(label, items) {
            if (!items.length) {
                return '';
            }

            return '<optgroup label="' + escapeHtml(label) + '">' +
                items.map(optionMarkup).join('') +
                '</optgroup>';
        };

        const free = leads.filter(function(lead) {
            return lead.available;
        });

        const suggested = free.filter(function(lead) {
            return lead.matched.length > 0;
        });

        const others = free.filter(function(lead) {
            return lead.matched.length === 0;
        });

        const blocked = leads.filter(function(lead) {
            return !lead.available;
        });

        leadTechSelect.innerHTML =
            '<option value=""' + (currentValue ? '' : ' selected') + ' disabled>Select Lead Technician</option>' +
            group('Suggested — matches this project', suggested) +
            group(suggested.length ? 'Other available' : 'Available', others) +
            group(isPartialDay() ? 'Unavailable at this time' : 'Unavailable for these dates', blocked);
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
                const isSelected = selectedIds.includes(String(technician.id));
                const skills = technician.matched.join(', ');

                return '<li><button type="button" class="dropdown-item technician-option' +
                    (isSelected ? ' active' : '') + '" ' +
                    'data-technician-option="' + technician.id + '" ' +
                    'data-technician-name="' + escapeHtml(technician.name) + '" ' +
                    'aria-pressed="' + (isSelected ? 'true' : 'false') + '">' +
                    '<span class="technician-option-name">' + escapeHtml(technician.name) + '</span>' +
                    (skills ? '<span class="technician-option-skills">' + escapeHtml(skills) + '</span>' : '') +
                    '</button></li>';
            }).join('');
        };

        // Plain rows, not buttons: there is nothing here to click.
        const renderBlocked = function(technicians) {
            if (!technicians.length) {
                return '';
            }

            const rows = technicians.map(function(technician) {
                return '<div class="technician-option is-disabled" aria-disabled="true">' +
                    '<span class="technician-option-name">' + escapeHtml(technician.name) + '</span>' +
                    '<span class="technician-option-reason">' + escapeHtml(technician.reason) + '</span>' +
                    '</div>';
            }).join('');

            return '<li><hr class="dropdown-divider"></li>' +
                '<li class="technician-blocked-wrap">' +
                '<button type="button" class="schedule-blocked-toggle' +
                (blockedTechniciansOpen ? ' is-open' : '') + '" data-technician-blocked-toggle>' +
                '<i class="bi bi-chevron-right" aria-hidden="true"></i>' +
                '<span>' + (blockedTechniciansOpen ? 'Hide' : 'Show') + ' unavailable technicians (' +
                technicians.length + ')</span>' +
                '</button>' +
                '<div class="schedule-blocked-list' + (blockedTechniciansOpen ? '' : ' d-none') + '">' +
                rows + '</div></li>';
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
            renderBlocked(sections.blocked),
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

        const blockedToggle = technicianDropdownMenu.querySelector('[data-technician-blocked-toggle]');

        if (blockedToggle) {
            blockedToggle.addEventListener('click', function() {
                blockedTechniciansOpen = !blockedTechniciansOpen;
                renderTechnicianDropdown();
            });
        }
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
        [startPicker, endPicker, projectDatePicker].forEach(function(picker) {
            if (picker) {
                picker.clear(false);
            }
        });

        [startDateInput, endDateInput, projectDateInput, startTimeSelect, endTimeSelect].forEach(function(field) {
            if (field) {
                field.value = '';
                field.setCustomValidity('');
            }
        });
    }

    function scheduleInputsReady() {
        return Boolean(selectedLeadTechnician()) && selectedTechnicianIds().length > 0;
    }

    function updateScheduleFieldState() {
        refreshDatePickers();

        // refreshDatePickers() can clear the dates it just invalidated, which
        // changes who counts as available - so the picker is restated here
        // rather than at each of the call sites.
        renderTechnicianDropdown();
        renderLeadTechnicianOptions();
    }

    function showScheduleError(message) {
        if (!scheduleError) {
            return;
        }

        scheduleError.textContent = message;
        scheduleError.classList.toggle('d-none', !message);
    }

    function scheduleFields() {
        return isPartialDay()
            ? [projectDateInput, startTimeSelect, endTimeSelect]
            : [startDateInput, endDateInput];
    }

    function validateScheduleInputs() {
        // Every schedule field funnels through here, which makes it the one
        // place that knows the dates have moved.
        if (refreshImportTeamButton) {
            refreshImportTeamButton();
        }

        const fields = scheduleFields().filter(Boolean);

        if (!fields.length || fields.some(function(field) {
            return field.disabled;
        })) {
            showScheduleError('');

            return true;
        }

        fields.forEach(function(field) {
            field.setCustomValidity('');
        });

        // Nothing to say until the whole schedule has been filled in.
        if (fields.some(function(field) {
            return !field.value;
        })) {
            showScheduleError('');

            return true;
        }

        if (isPartialDay()) {
            const from = minutesFromTime(startTimeSelect.value);
            const to = minutesFromTime(endTimeSelect.value);

            if (from !== null && to !== null && from >= to) {
                const message = 'The end time must be later than the start time.';
                endTimeSelect.setCustomValidity(message);
                showScheduleError(message);

                return false;
            }

            if (to !== null && to > WORKING_HOUR_END * 60) {
                const message = 'The end time cannot be later than 5:00 PM.';
                endTimeSelect.setCustomValidity(message);
                showScheduleError(message);

                return false;
            }
        }

        const conflicts = scheduleConflicts();

        if (conflicts.length) {
            const message = conflictMessage(conflicts);

            fields.forEach(function(field) {
                field.setCustomValidity(message);
            });

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

    function selectedTimeLabel(select) {
        if (!select || !select.value) {
            return '';
        }

        const option = select.options[select.selectedIndex];

        return option ? option.textContent.trim() : '';
    }

    /**
     * The schedule as one line for the review step: a date range for whole
     * days, and the date with its hours for a partial day.
     */
    function scheduleSummary() {
        if (isPartialDay()) {
            const date = formatFieldValue(projectDateInput);
            const from = selectedTimeLabel(startTimeSelect);
            const to = selectedTimeLabel(endTimeSelect);

            if (date === 'Not filled yet' || !from || !to) {
                return '';
            }

            return date + ' · ' + from + ' - ' + to;
        }

        return [startDateInput, endDateInput]
            .map(formatFieldValue)
            .filter(function(value) {
                return value !== 'Not filled yet';
            })
            .join(' to ');
    }

    function updateSummary() {
        const clientFirstName = form.querySelector('[data-summary-input="firstname"]');
        const clientMiddleName = form.querySelector('[data-summary-input="middle_name"]');
        const clientSurname = form.querySelector('[data-summary-input="surname"]');

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
            scheduling_mode: isPartialDay() ? 'Partial Day' : 'Date-Based',
            schedule_range: scheduleSummary(),
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
            updateScheduleFieldState();
            updateSummary();
        });
    });

    // Switching mode swaps which fields are in play, without a reload.
    schedulingModeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            applySchedulingMode();
            validateScheduleInputs();
            renderTechnicianDropdown();
            renderLeadTechnicianOptions();
            updateSummary();
        });
    });

    [startTimeSelect, endTimeSelect].forEach(function(select) {
        if (!select) {
            return;
        }

        select.addEventListener('change', function() {
            refreshTimeOptions();
            validateScheduleInputs();
            renderTechnicianDropdown();
            renderLeadTechnicianOptions();
            updateSummary();
        });
    });

    projectTypeCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            syncSelectableCards();
            renderTechnicianDropdown();

            renderLeadTechnicianOptions();

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
    // change invalidates the chosen schedule, so re-check on submit too. The
    // server enforces the same rules regardless.
    form.addEventListener('submit', function(event) {
        if (!validateScheduleInputs()) {
            event.preventDefault();
            setStep(3);

            const firstField = scheduleFields().find(Boolean);

            if (firstField) {
                firstField.reportValidity();
            }
        }
    });

    form.addEventListener('input', function() {
        validateScheduleInputs();
        updateSummary();
    });

    form.addEventListener('change', function() {
        updateSummary();
    });

    // Once the schedule exists, who is free is knowable - so the picker
    // restates itself with the booked technicians moved out of reach.
    const refreshTechnicianAvailability = function() {
        validateScheduleInputs();
        renderTechnicianDropdown();
        renderLeadTechnicianOptions();
    };

    [startDateInput, endDateInput, projectDateInput].forEach(function(input) {
        if (input) {
            input.addEventListener('change', refreshTechnicianAvailability);
        }
    });

    /**
     * Copying a team from another project fills this same picker in. Nothing
     * is saved by it: the chips can be removed, more technicians added, and
     * the lead changed, exactly as if each had been chosen by hand.
     *
     * The button waits for the schedule, because the whole point of the dialog
     * is to say who is free for these dates - and before they are chosen there
     * are no dates to be free for.
     */
    function initImportTeam() {
        const importModal = document.querySelector('[data-import-team-modal]');
        const importButton = form.querySelector('[data-import-team-button]');
        const importHint = form.querySelector('[data-import-team-hint]');

        if (!importModal || !importButton || !window.importTeam) {
            return null;
        }

        window.importTeam.init({
            modal: importModal,
            // A new project has no lead yet, so the imported one simply
            // becomes it - there is nothing to overrule.
            confirmLeadChange: false,
            params: importTeamParams,
            onImport: function (result) {
                if (result.lead) {
                    const option = leadTechSelect.querySelector(
                        'option[value="' + result.lead.id + '"]'
                    );

                    if (option) {
                        option.disabled = false;
                    }

                    leadTechSelect.value = String(result.lead.id);
                }

                result.technicians.forEach(function (technician) {
                    addTechnician(String(technician.id));
                });

                renderTechnicianChips();
                updateScheduleFieldState();
                updateSummary();
            },
        });

        return function () {
            if (!importHint) {
                return;
            }

            importHint.textContent = scheduleChosen()
                ? 'Copy a team from another project. Anyone already booked over these dates is flagged.'
                : 'Copy a team from another project. Once you set the schedule below, anyone who is '
                    + 'already booked over those dates is flagged here.';
        };
    }

    function scheduleChosen() {
        const fields = scheduleFields().filter(Boolean);

        return fields.length > 0 && fields.every(function (field) {
            return field.value;
        });
    }

    /**
     * The schedule the wizard is about to save, as the import endpoint reads
     * it.
     *
     * Empty until the dates are chosen, which is the ordinary case: the
     * schedule fields stay disabled until a team exists, so waiting for them
     * would mean picking by hand the very team this is meant to save picking.
     * With no dates the endpoint screens against nothing and offers every
     * team; the wizard then flags anyone who clashes as the dates go in, and
     * the server refuses a team that does not fit them.
     */
    function importTeamParams() {
        if (!scheduleChosen()) {
            return {};
        }

        return isPartialDay()
            ? {
                scheduling_mode: MODE_PARTIAL_DAY,
                project_date: projectDateInput.value,
                start_time: startTimeSelect.value,
                end_time: endTimeSelect.value,
            }
            : {
                scheduling_mode: MODE_DATE_BASED,
                start_date: startDateInput.value,
                end_date: endDateInput.value,
            };
    }

    syncSelectableCards();
    updateClientType();
    renderTechnicianDropdown();
    renderLeadTechnicianOptions();
    renderTechnicianChips();
    initializeDatePickers();
    refreshImportTeamButton = initImportTeam();
    updateScheduleFieldState();
    updateSummary();
    setStep(1);
});
