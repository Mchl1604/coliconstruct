/**
 * The Project Details page's own behaviour: the edit dialog's project types and
 * upload lists, the report image preview, the task list, the assigned-team
 * editor and document removal.
 *
 * The page is a workspace (see projectWorkspace.js): a save redraws its content
 * in place rather than reloading it. So everything that binds to the page's
 * markup lives in init(root), which runs once on load and again on every
 * `workspace:updated` with the freshly drawn content. What is delegated from
 * the document is bound once, here, and finds its elements when it is used.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Project types in the edit dialog
    //
    // Delegated, so it survives the dialog being redrawn; the container and
    // the hidden inputs are looked up at the moment of the click.
    // ------------------------------------------------------------------

    document.addEventListener('click', function (e) {
        const container = document.getElementById('projectTypesContainer');
        const inputs = document.getElementById('projectTypesInputs');

        if (!container || !inputs) {
            return;
        }

        // Remove
        if (e.target.classList.contains('remove-project-type')) {

            const id = e.target.dataset.typeId;

            container.querySelector(`[data-type-id="${id}"]`).remove();

            inputs.querySelector(`input[data-type-id="${id}"]`).remove();

        }

        // Add
        if (e.target.classList.contains('add-project-type')) {

            const id = e.target.dataset.typeId;
            const name = e.target.dataset.typeName;

            if (inputs.querySelector(`input[data-type-id="${id}"]`))
                return;

            container.insertAdjacentHTML(
                'beforeend',
                `
                <span class="badge bg-primary d-flex align-items-center px-3 py-2"
                      data-type-id="${id}">

                    ${name}

                    <button type="button"
                            class="btn-close btn-close-white ms-2 remove-project-type"
                            data-type-id="${id}">
                    </button>

                </span>
                `
            );

            inputs.insertAdjacentHTML(
                'beforeend',
                `
                <input type="hidden"
                       name="project_types[]"
                       value="${id}"
                       data-type-id="${id}">
                `
            );

            e.target.parentElement.remove();

        }

    });

    /**
     * The task list.
     *
     * Built the first time the Tasks tab can actually be seen, and never
     * before: a table measured while its pane is display:none has no width to
     * measure, and its columns come out wrong. Every show after that
     * re-measures instead.
     *
     * "Can be seen" rather than "the Tasks tab was clicked": the Tasks pane
     * may already be the open one inside Project Progress - kept open across a
     * save, or across a reload - and is then revealed by the OUTER tab, which
     * is the only one that fires. So any tab being shown asks again.
     *
     * This used to be built twice - once on tab-show and once again on ready -
     * so the on-ready copy won the race, sized itself against a hidden pane, and
     * left the tab-show handler taking the "already built" branch on the very
     * first click. That branch then reached for the DataTables Responsive
     * extension to recalculate. The extension is not loaded on this page - nor
     * anywhere in the app - so the call threw a TypeError every single time the
     * Tasks tab was opened, and the `responsive: true` option was dead config
     * for the same reason. Both are gone; columns.adjust() is core, and it is
     * what was actually doing the work.
     *
     * The page, the search and the sort are saved for the browser tab
     * (stateSave, in sessionStorage), so redrawing the list after a task is
     * edited puts the person back on the page of it they were working through.
     */
    function ensureTasksTable() {
        const table = document.getElementById('tasksTable');

        if (!table || !table.getClientRects().length) {
            return;
        }

        if ($.fn.dataTable.isDataTable(table)) {
            $(table).DataTable().columns.adjust();

            return;
        }

        $(table).DataTable({
            autoWidth: false,
            pageLength: 5,
            lengthMenu: [5, 10, 25, 50],
            info: false,
            stateSave: true,
            stateDuration: -1,
            columnDefs: [
                // Phase carries a `data-order` sequence, which DataTables reads as
                // numeric and then right-aligns on its own.
                { targets: 1, className: "text-start" },
                // Sorting a column of buttons means nothing, and the header
                // offering it invites a click that does nothing.
                { targets: -1, orderable: false },
            ],
            language: {
                search: "",
                searchPlaceholder: "Search tasks..."
            }
        });
    }

    $(document).on('shown.bs.tab', ensureTasksTable);

    function initReportImages(root) {
        // Only drawn where a report can be filed - a closed or paused project
        // has no Add Report dialog, and reaching for it here used to throw and
        // take the rest of this file down with it.
        const input = root.querySelector('#reportImages');
        const preview = root.querySelector('#imagePreview');

        if (!input || !preview) {
            return;
        }

        input.addEventListener('change', function () {

            preview.innerHTML = '';

            Array.from(this.files).forEach(file => {

                const reader = new FileReader();

                reader.onload = function(e){

                    preview.innerHTML += `
                        <div class="col-md-3">
                            <div class="card">
                                <img src="${e.target.result}"
                                     class="card-img-top"
                                     style="height:160px;object-fit:cover;">
                            </div>
                        </div>
                    `;
                };

                reader.readAsDataURL(file);

            });

        });
    }

    // The Add Task dialog is not on every project: a project whose phases have
    // not been finalized is offered Set Up Project Phases instead, and the
    // dialog is not rendered at all.
    function initTaskDates(root) {
        const taskStartDate = root.querySelector('#taskStartDate');

        if (!taskStartDate) {
            return;
        }

        taskStartDate.addEventListener('change', function () {

            const due = document.getElementById('taskDueDate');

            if (due) {
                due.min = this.value;
            }

        });
    }

    function initTeamForm(root) {
        const form = root.querySelector('[data-team-form]');

        if (!form) {
            return;
        }

        let teamData = Array.isArray(window.assignedTeamData) ? window.assignedTeamData : [];
        let initialState = window.assignedTeamState || { leadTechId: null, technicianIds: [] };
        let technicianLookup = new Map(teamData.map(function(technician) {
            return [String(technician.id), technician];
        }));

        const leadTechButton = form.querySelector('[data-lead-tech-button]');
        const leadTechMenu = form.querySelector('[data-lead-tech-menu]');
        const leadTechInput = form.querySelector('[data-lead-tech-input]');
        const leadTechError = form.querySelector('[data-lead-tech-error]');
        const dropdownButton = form.querySelector('[data-technician-dropdown-button]');
        const dropdownMenu = form.querySelector('[data-technician-dropdown-menu]');
        const selectedList = form.querySelector('[data-technician-selected-list]');
        const hiddenInputsContainer = form.querySelector('[data-technician-hidden-inputs]');

        function selectedTechnicianIds() {
            return Array.from(hiddenInputsContainer.querySelectorAll('input[type="hidden"]')).map(function(input) {
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

        // Whether each menu's unavailable section is expanded, kept outside the
        // render functions so re-rendering after a pick does not collapse it again.
        let blockedOpen = false;
        let blockedLeadsOpen = false;

        function escapeHtml(value) {
            const span = document.createElement('span');
            span.textContent = value == null ? '' : String(value);

            return span.innerHTML;
        }

        /**
         * One technician's card: picture, name, role, the specialties that match
         * this project, and - for a blocked one, below - why they are out.
         */
        function avatarMarkup(technician) {
            if (!technician.avatar_url) {
                return '';
            }

            return '<img class="user-avatar user-avatar-md technician-option-avatar" src="' +
                escapeHtml(technician.avatar_url) + '" alt="" loading="lazy">';
        }

        function optionMarkup(technician, isSelected, attribute) {
            const skills = (technician.skills || []).join(', ');

            return '<li><button type="button" class="dropdown-item technician-option' +
                (isSelected ? ' is-selected' : '') + '" ' +
                (attribute || 'data-technician-option') + '="' + technician.id + '" ' +
                'aria-pressed="' + (isSelected ? 'true' : 'false') + '">' +
                avatarMarkup(technician) +
                '<span class="technician-option-body">' +
                '<span class="technician-option-name">' + escapeHtml(technician.name) + '</span>' +
                '<span class="technician-option-role">' +
                escapeHtml(technician.role_label || 'Technician') + '</span>' +
                (skills ? '<span class="technician-option-skills">' + escapeHtml(skills) + '</span>' : '') +
                // Once somebody is on the team, saying "Available" of them is
                // answering a question nobody is asking any more.
                (isSelected
                    ? '<span class="technician-option-selected-note">On this project</span>'
                    : '<span class="technician-option-available">Available</span>') +
                '</span>' +
                '<i class="bi bi-check-lg technician-option-check" aria-hidden="true"></i>' +
                '</button></li>';
        }

        function groupMarkup(label, technicians, selectedIds, attribute) {
            if (!technicians.length) {
                return '';
            }

            return '<li><h6 class="dropdown-header">' + escapeHtml(label) + '</h6></li>' +
                technicians.map(function(technician) {
                    return optionMarkup(technician, selectedIds.includes(String(technician.id)), attribute);
                }).join('');
        }

        // Shown so the scheduler can see who is out and why, but rendered as plain
        // rows rather than buttons - there is nothing here to click.
        function blockedMarkup(technicians, isOpen, attribute, noun) {
            if (!technicians.length) {
                return '';
            }

            const rows = technicians.map(function(technician) {
                return '<div class="technician-option is-disabled" aria-disabled="true">' +
                    avatarMarkup(technician) +
                    '<span class="technician-option-body">' +
                    '<span class="technician-option-name">' + escapeHtml(technician.name) + '</span>' +
                    '<span class="technician-option-role">' +
                    escapeHtml(technician.role_label || 'Technician') + '</span>' +
                    '<span class="technician-option-reason">' + escapeHtml(technician.reason) + '</span>' +
                    '</span>' +
                    '</div>';
            }).join('');

            return '<li><hr class="dropdown-divider"></li>' +
                '<li class="technician-blocked-wrap">' +
                '<button type="button" class="schedule-blocked-toggle' + (isOpen ? ' is-open' : '') + '" ' +
                attribute + '>' +
                '<i class="bi bi-chevron-right" aria-hidden="true"></i>' +
                '<span>' + (isOpen ? 'Hide' : 'Show') + ' unavailable ' + noun + ' (' + technicians.length + ')</span>' +
                '</button>' +
                '<div class="schedule-blocked-list' + (isOpen ? '' : ' d-none') + '">' + rows + '</div>' +
                '</li>';
        }

        /**
         * The row at the foot of the menu: how many people are on the team so far,
         * and Done.
         *
         * With the menu open there was nothing in it that looked like a way out -
         * the only one was pressing the field again, above the list, which is not
         * where anybody looks. The count is the other half of the tick: a tick
         * answers for one row, this answers for the whole list at once.
         */
        function footerMarkup(label, isEmpty, attribute) {
            return '<li class="technician-dropdown-footer">' +
                '<span class="technician-dropdown-count' + (isEmpty ? ' is-empty' : '') + '">' +
                escapeHtml(label) + '</span>' +
                '<button type="button" class="btn btn-sm btn-primary" ' + attribute + '>Done</button>' +
                '</li>';
        }

        function closePicker(button) {
            if (!window.bootstrap || !window.bootstrap.Dropdown) {
                return;
            }

            window.bootstrap.Dropdown.getOrCreateInstance(button).hide();
        }

        /**
         * The Lead Technician menu: the Technicians menu's cards and groups,
         * holding one choice instead of many.
         *
         * Whoever is `selectable` can be picked - that is everyone free, plus
         * anyone already on the team even if a clash slipped in, so the current
         * lead never sits in a field its own menu refuses to offer.
         */
        function renderLeadDropdown() {
            const leadId = String(leadTechInput.value || '');
            const selectedIds = leadId ? [leadId] : [];

            const leads = teamData.filter(function(technician) {
                return technician.role === 'lead_technician';
            });

            const pickable = leads.filter(function(technician) {
                return technician.selectable;
            });

            const suggested = pickable.filter(function(technician) {
                return technician.suggested;
            });

            const others = pickable.filter(function(technician) {
                return !technician.suggested;
            });

            const blocked = leads.filter(function(technician) {
                return !technician.selectable;
            });

            const groups = groupMarkup('Suggested — matches this project', suggested, selectedIds, 'data-lead-option') +
                groupMarkup(suggested.length ? 'Other available' : 'Available', others, selectedIds, 'data-lead-option');

            const chosen = technicianLookup.get(leadId);

            leadTechMenu.innerHTML = (groups ||
                '<li><span class="dropdown-item-text text-secondary">No lead technicians available.</span></li>') +
                blockedMarkup(blocked, blockedLeadsOpen, 'data-lead-blocked-toggle', 'lead technicians') +
                footerMarkup(chosen ? chosen.name : 'No lead chosen', !chosen, 'data-lead-done');

            leadTechMenu.querySelectorAll('[data-lead-option]').forEach(function(button) {
                button.addEventListener('click', function() {
                    // A project always has a lead, so pressing the chosen one
                    // again keeps them rather than emptying a required field.
                    setLead(button.dataset.leadOption);

                    // One lead per project, so choosing finishes the job.
                    closePicker(leadTechButton);
                });
            });

            const done = leadTechMenu.querySelector('[data-lead-done]');

            if (done) {
                done.addEventListener('click', function() {
                    closePicker(leadTechButton);
                });
            }

            const blockedToggle = leadTechMenu.querySelector('[data-lead-blocked-toggle]');

            if (blockedToggle) {
                blockedToggle.addEventListener('click', function() {
                    blockedLeadsOpen = !blockedLeadsOpen;
                    renderLeadDropdown();
                });
            }

            leadTechButton.textContent = chosen ? chosen.name : 'Select lead technician';
        }

        function setLead(leadId) {
            leadTechInput.value = leadId ? String(leadId) : '';
            leadTechError.classList.add('d-none');

            renderLeadDropdown();
            renderDropdown();
        }

        function renderDropdown() {
            const selectedIds = selectedTechnicianIds();
            const leadId = leadTechInput.value;

            // Lead technicians are picked in their own menu, so they never appear
            // in the team list.
            const candidates = teamData.filter(function(technician) {
                return technician.role !== 'lead_technician';
            });

            // Somebody already on the team stays in this list, ticked, rather
            // than disappearing out of it. Vanishing was the only feedback a pick
            // gave, and losing a name off a list does not read as choosing it -
            // it reads as a mistake. Staying put also means the way to take
            // somebody off is where the way to put them on was.
            const pickable = candidates.filter(function(technician) {
                return technician.available
                    && String(technician.id) !== String(leadId);
            });

            const suggested = pickable.filter(function(technician) {
                return technician.suggested;
            });

            const others = pickable.filter(function(technician) {
                return !technician.suggested;
            });

            const blocked = candidates.filter(function(technician) {
                return !technician.available;
            });

            const groups = groupMarkup('Suggested — matches this project', suggested, selectedIds) +
                groupMarkup(suggested.length ? 'Other available' : 'Available', others, selectedIds);

            dropdownMenu.innerHTML = (groups ||
                '<li><span class="dropdown-item-text text-secondary">No technicians available.</span></li>') +
                blockedMarkup(blocked, blockedOpen, 'data-technician-blocked-toggle', 'technicians') +
                footerMarkup(
                    selectedIds.length ? selectedIds.length + ' selected' : 'None selected',
                    !selectedIds.length,
                    'data-technician-done'
                );

            dropdownMenu.querySelectorAll('[data-technician-option]').forEach(function(button) {
                button.addEventListener('click', function() {
                    const technicianId = button.dataset.technicianOption;

                    if (selectedTechnicianIds().includes(String(technicianId))) {
                        removeTechnician(String(technicianId));
                    } else {
                        addTechnician(technicianId);
                    }
                });
            });

            const done = dropdownMenu.querySelector('[data-technician-done]');

            if (done) {
                done.addEventListener('click', function() {
                    closePicker(dropdownButton);
                });
            }

            const blockedToggle = dropdownMenu.querySelector('[data-technician-blocked-toggle]');

            if (blockedToggle) {
                blockedToggle.addEventListener('click', function() {
                    blockedOpen = !blockedOpen;
                    renderDropdown();
                });
            }
        }

        function renderChips() {
            const selected = selectedTechnicians();
            selectedList.innerHTML = '';

            if (!selected.length) {
                const emptyState = document.createElement('div');
                emptyState.className = 'technician-empty-state';
                emptyState.textContent = 'No technicians selected yet.';
                selectedList.appendChild(emptyState);
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
                    selectedList.appendChild(chip);
                });
            }

            dropdownButton.textContent = selected.length ? selected.length + ' selected' : 'Select technicians';
        }

        function addTechnician(technicianId) {
            if (selectedTechnicianIds().includes(String(technicianId))) {
                return;
            }

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'technicians[]';
            hiddenInput.value = technicianId;
            hiddenInputsContainer.appendChild(hiddenInput);

            renderChips();
            renderDropdown();
        }

        function removeTechnician(technicianId) {
            const hiddenInputs = Array.from(hiddenInputsContainer.querySelectorAll('input[type="hidden"]'));
            const hiddenInput = hiddenInputs.find(function(input) {
                return input.value === technicianId;
            });

            if (hiddenInput) {
                hiddenInput.remove();
            }

            renderChips();
            renderDropdown();
        }

        function seedInitialTechnicians() {
            (initialState.technicianIds || []).forEach(function(technicianId) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'technicians[]';
                hiddenInput.value = technicianId;
                hiddenInputsContainer.appendChild(hiddenInput);
            });
        }

        /**
         * Whether this save is replacing the lead, rather than leaving them alone.
         */
        function replacesLead() {
            const previousLeadId = initialState.leadTechId;

            return Boolean(previousLeadId)
                && String(previousLeadId) !== String(leadTechInput.value);
        }

        let reviewing = false;
        let readyToSend = false;
        let previewError = null;

        const editStep = form.querySelector('[data-team-edit-step]');
        const reviewStep = form.querySelector('[data-team-review-step]');
        const reviewList = form.querySelector('[data-team-review-list]');
        const reviewSummary = form.querySelector('[data-team-review-summary]');
        const reviewBack = form.querySelector('[data-team-review-back]');
        const closeButton = form.querySelector('[data-team-close]');

        // ----------------------------------------------------------------
        // Asking before it is saved
        // ----------------------------------------------------------------

        /**
         * A project carries exactly one lead, so choosing a different one in the
         * menu is not an addition - it REPLACES the lead who is there, on the
         * day the change takes effect. That is the intended way to change a
         * lead, and it stays a single save; what it must not be is a surprise,
         * so the replacement is named before it happens.
         */
        function confirmLeadReplacement() {
            const outgoing = technicianLookup.get(String(initialState.leadTechId));
            const incoming = technicianLookup.get(String(leadTechInput.value));
            const outgoingName = outgoing ? outgoing.name : 'The current lead technician';

            return window.confirmDialog({
                title: 'Replace the lead technician?',
                body: outgoingName + ' will be replaced by ' + (incoming ? incoming.name : 'the selected technician') + '.',
                detail: outgoingName + ' comes off the project completely, today.',
                label: 'Replace Lead',
            });
        }

        function showPreviewError(messages) {
            if (!previewError) {
                previewError = document.createElement('div');
                previewError.className = 'alert alert-danger';
                previewError.setAttribute('role', 'alert');
                editStep.prepend(previewError);
            }

            previewError.innerHTML = '';

            const heading = document.createElement('div');
            heading.className = 'fw-semibold mb-1';
            heading.textContent = 'Your changes were not saved.';

            const list = document.createElement('ul');
            list.className = 'mb-0 ps-3';

            messages.forEach(function (message) {
                const item = document.createElement('li');
                item.textContent = message;
                list.appendChild(item);
            });

            previewError.append(heading, list);
            previewError.classList.remove('d-none');
            editStep.scrollTop = 0;
        }

        function clearPreviewError() {
            if (previewError) {
                previewError.classList.add('d-none');
            }
        }

        function showEditStep() {
            reviewing = false;
            reviewList.innerHTML = '';
            reviewStep.classList.add('d-none');
            editStep.classList.remove('d-none');
            reviewBack.classList.add('d-none');
            closeButton.classList.remove('d-none');
        }

        /**
         * One row per task the change strands, each with its own choice. The
         * choices are ordinary form fields - task_resolutions[task_id] - so the
         * save carries them to the server, which checks every one again.
         */
        function showReviewStep(preview) {
            reviewing = true;

            reviewSummary.textContent = preview.conflicts.length === 1
                ? '1 task needs a decision before this change can be saved.'
                : preview.conflicts.length + ' tasks need a decision before this change can be saved.';

            reviewList.innerHTML = preview.conflicts.map(function (conflict) {
                const options = ['<option value="" selected disabled>Choose what happens&hellip;</option>']
                    .concat(conflict.options.map(function (option) {
                        return '<option value="' + option.technician_id + '">Reassign to ' +
                            escapeHtml(option.name) + '</option>';
                    }))
                    .concat(['<option value="unassign">Leave unassigned</option>'])
                    .concat(conflict.can_keep
                        ? ['<option value="keep">Keep with ' + escapeHtml(conflict.holder || 'the technician') +
                            ' and flag it</option>']
                        : [])
                    .join('');

                return '<div class="border rounded-2 p-3">' +
                    '<div class="d-flex flex-wrap justify-content-between gap-2">' +
                    '<span class="fw-semibold">' + escapeHtml(conflict.title) + '</span>' +
                    '<span class="text-secondary small">' + escapeHtml(conflict.dates) + '</span>' +
                    '</div>' +
                    '<div class="text-secondary small mb-2">' + escapeHtml(conflict.reason) + '</div>' +
                    '<select class="form-select form-select-sm" required name="task_resolutions[' +
                    conflict.task_id + ']" aria-label="What happens to ' + escapeHtml(conflict.title) + '">' +
                    options + '</select>' +
                    (conflict.options.length
                        ? ''
                        : '<div class="form-text">Nobody on the team is assigned for all of these dates.</div>') +
                    '</div>';
            }).join('');

            editStep.classList.add('d-none');
            reviewStep.classList.remove('d-none');
            reviewBack.classList.remove('d-none');
            closeButton.classList.add('d-none');
        }

        reviewBack.addEventListener('click', showEditStep);

        /**
         * Ask the server what this change would do: the same refusals the save
         * gives, and the tasks it would strand.
         */
        function preview() {
            const data = new FormData(form);

            // The preview is its own POST route; the form's PUT spoofing and
            // any earlier task choices are not part of the question.
            data.delete('_method');
            Array.from(data.keys()).forEach(function (key) {
                if (key.indexOf('task_resolutions') === 0) {
                    data.delete(key);
                }
            });

            return fetch(form.dataset.previewUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: data,
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { errors: ['Unable to check this change. Try again.'], conflicts: [] };
                    });
                })
                .catch(function () {
                    return { errors: ['Unable to reach the server. Nothing was changed.'], conflicts: [] };
                });
        }

        /**
         * The dialogs answer later than a submit handler can wait, so the submit
         * is always stopped and re-fired once every question has been answered.
         * `readyToSend` is what lets the second pass through to the page's own
         * save - see projectWorkspace.js.
         */
        form.addEventListener('submit', function (event) {
            // A hidden input takes no part in the browser's own validation, so
            // the requirement is enforced here and the menu is put in front of
            // the person to answer it.
            if (!leadTechInput.value) {
                event.preventDefault();
                showEditStep();
                leadTechError.classList.remove('d-none');
                leadTechButton.focus();

                return;
            }

            if (readyToSend) {
                readyToSend = false;

                return;
            }

            // Every stranded task answered: this submit is the save, and it is
            // left to go on to the page's own sending. It is not stopped and
            // fired again - a form cannot be re-submitted from inside its own
            // submit event, and the browser silently drops the second one,
            // which left the button needing two presses. The selects are
            // required, so the browser has already refused a missing choice
            // before this handler runs.
            if (reviewing) {
                return;
            }

            event.preventDefault();

            clearPreviewError();

            (replacesLead() ? confirmLeadReplacement() : Promise.resolve(true)).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                return preview().then(function (result) {
                    if (result.errors && result.errors.length) {
                        showPreviewError(result.errors);

                        return;
                    }

                    if (result.conflicts && result.conflicts.length) {
                        showReviewStep(result);

                        return;
                    }

                    readyToSend = true;
                    form.requestSubmit();
                });
            });
        });

        // A refused save leaves the dialog open. Trying again is a new decision,
        // so every question is asked again.
        form.addEventListener('workspace:failed', function () {
            readyToSend = false;
        });

        /**
         * Copying a team from another project puts its people into this same
         * picker. Nothing is saved and nothing is locked: the chips can be removed,
         * more technicians added, and the lead changed, exactly as if every one of
         * them had been chosen by hand.
         */
        function initImportTeam() {
            const importModal = root.querySelector('[data-import-team-modal]');
            const teamModal = root.querySelector('#editAssignedTeamModal');
            const openButton = form.querySelector('[data-import-team-open]');

            if (!importModal || !teamModal || !openButton || !window.importTeam || !window.bootstrap) {
                return;
            }

            // One dialog at a time, and the editor is always what you come back
            // to - with whoever was imported already in the picker, ready to be
            // adjusted and saved.
            let handingOver = false;

            openButton.addEventListener('click', function () {
                handingOver = true;
                window.bootstrap.Modal.getOrCreateInstance(teamModal).hide();
            });

            teamModal.addEventListener('hidden.bs.modal', function () {
                if (!handingOver) {
                    return;
                }

                handingOver = false;
                window.bootstrap.Modal.getOrCreateInstance(importModal).show();
            });

            importModal.addEventListener('hidden.bs.modal', function () {
                window.bootstrap.Modal.getOrCreateInstance(teamModal).show();
            });

            window.importTeam.init({
                modal: importModal,
                confirmLeadChange: true,
                params: function () {
                    return { project_id: window.importTeamProjectId };
                },
                currentLeadId: function () {
                    if (!leadTechInput.value) {
                        return null;
                    }

                    const technician = technicianLookup.get(String(leadTechInput.value));

                    return {
                        id: leadTechInput.value,
                        name: technician ? technician.name : 'the current lead technician',
                    };
                },
                onImport: function (result) {
                    if (result.lead && !result.keepCurrentLead) {
                        const lead = technicianLookup.get(String(result.lead.id));

                        if (lead) {
                            // The server screened this person against this
                            // project's dates just now, so a stale "unavailable"
                            // from page load must not stand in the way.
                            lead.selectable = true;
                            leadTechInput.value = String(result.lead.id);
                        }
                    }

                    result.technicians.forEach(function (technician) {
                        addTechnician(String(technician.id));
                    });

                    leadTechError.classList.add('d-none');
                    renderChips();
                    renderLeadDropdown();
                    renderDropdown();
                },
            });
        }

        seedInitialTechnicians();
        renderChips();
        renderLeadDropdown();
        renderDropdown();
        initImportTeam();
    }

    // ------------------------------------------------------------------
    // Project documents
    //
    // Uploading adds files, so removing one is its own action. The row goes
    // as soon as the server confirms it, rather than costing a page load for
    // a change that touches one line.
    // ------------------------------------------------------------------

    /**
     * The edit dialog's upload cards: what you just picked, listed under the
     * input. A file field otherwise says "3 files" and leaves you to trust it,
     * which is thin when the files are being added to a project rather than
     * replacing what is there.
     */
    function initPickedFileLists(root) {
        root.querySelectorAll('[data-upload-input]').forEach(function (input) {
            const list = input.parentElement.querySelector('[data-picked-list]');

            if (!list) {
                return;
            }

            input.addEventListener('change', function () {
                const files = Array.from(input.files || []);

                list.innerHTML = files
                    .map(function (file) {
                        const name = document.createElement('span');
                        name.textContent = file.name;

                        return '<li><i class="bi bi-paperclip"></i>' + name.innerHTML + '</li>';
                    })
                    .join('');

                list.classList.toggle('d-none', files.length === 0);
            });
        });
    }

    function initDocumentRemoval(root) {
        const wrap = root.querySelector('[data-project-documents]');

        if (!wrap) {
            return;
        }

        const errorBox = root.querySelector('[data-document-error]');
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

        function showError(message) {
            if (!errorBox) {
                return;
            }

            errorBox.textContent = message || '';
            errorBox.classList.toggle('d-none', !message);
        }

        wrap.addEventListener('click', function (event) {
            const button = event.target.closest('[data-document-remove]');

            if (!button || button.disabled) {
                return;
            }

            const label = button.dataset.documentLabel || 'this file';

            button.disabled = true;
            showError('');

            // The dialog is handed the request as well as the question, so a
            // refusal is rendered inside it - beside the file being removed -
            // rather than in the alert at the top of a panel the reader may
            // have scrolled past.
            window.confirmDialog({
                title: 'Remove this file?',
                body: '"' + label + '" will be removed from this project.',
                detail: 'The file is deleted. This cannot be undone.',
                label: 'Remove File',
                onConfirm: function () {
                    return fetch(button.dataset.documentRemove, {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    }).then(function (response) {
                        return response
                            .json()
                            .catch(function () {
                                return {};
                            })
                            .then(function (body) {
                                return { ok: response.ok, body: body };
                            });
                    });
                },
            })
                .then(function (result) {
                    // They backed out. The row stays exactly as it was.
                    if (result === false) {
                        button.disabled = false;

                        return;
                    }

                    const row = button.closest('[data-document-row]');
                    const group = row ? row.closest('.project-document-group') : null;

                    row?.remove();

                    if (group) {
                        // The count beside the heading, and the "none uploaded"
                        // line that takes over when the last one goes.
                        const remaining = group.querySelectorAll('[data-document-row]').length;
                        const badge = group.querySelector('.badge');

                        if (badge) {
                            badge.textContent = String(remaining);
                            badge.classList.toggle('d-none', remaining === 0);
                        }

                        if (remaining === 0) {
                            const name = group.querySelector('.fw-semibold')?.textContent.trim() || 'file';
                            const empty = document.createElement('span');

                            empty.className = 'text-muted small';
                            empty.textContent = 'No ' + name.toLowerCase() + ' uploaded.';
                            group.appendChild(empty);
                        }
                    }

                    // The row is gone already; the rest of the page still
                    // counts the file - the edit dialog's "on file" badges,
                    // the history that now records its removal - so it is
                    // redrawn from the server behind the change.
                    if (window.projectWorkspace) {
                        window.projectWorkspace.refresh();
                    }
                })
                .catch(function () {
                    button.disabled = false;
                    showError('Unable to remove that file.');
                });
        });
    }

    // ------------------------------------------------------------------
    // Scheduled team changes
    // ------------------------------------------------------------------

    /**
     * Cancel a scheduled removal or start, after saying what it undoes.
     *
     * Cancelling either half of a lead handover cancels the other half as
     * well - the server does it, see ProjectTeamChange::cancelScheduled() - so
     * the dialog says so for a lead rather than let it come as a surprise.
     */
    function initTeamCancelForms(root) {
        root.querySelectorAll('[data-team-cancel-form]').forEach(function (form) {
            let confirmed = false;

            form.addEventListener('submit', function (event) {
                if (confirmed) {
                    confirmed = false;

                    return;
                }

                event.preventDefault();

                // Asked from inside a technician's schedule dialog: that dialog
                // steps aside for the question - Bootstrap does not stack two -
                // and comes back if the answer is no.
                const scheduleModal = form.closest('[data-team-schedule-modal]');

                if (scheduleModal && scheduleModal.classList.contains('show')) {
                    scheduleModal.addEventListener('hidden.bs.modal', function () {
                        ask(scheduleModal);
                    }, { once: true });

                    window.bootstrap.Modal.getOrCreateInstance(scheduleModal).hide();

                    return;
                }

                ask(null);
            });

            function ask(scheduleModal) {
                const name = form.dataset.technicianName || 'This technician';
                const label = form.dataset.label || 'Cancel';
                const isLead = form.dataset.isLead === '1';

                // What pressing it undoes, said the way the card said it.
                const body = {
                    'Cancel removal': name + ' will stay on this project.',
                    'Cancel days off': name + ' will no longer take those days off.',
                    'Cancel return': name + ' will not come back to this project.',
                    'Cancel cover': name + ' will no longer lead in their place, and the days off they were covering are cancelled.',
                    'Cancel start': name + ' will no longer join this project.',
                }[label] || name + "'s scheduled change will be cancelled.";

                window.confirmDialog({
                    title: label + '?',
                    body: body,
                    detail: isLead && label !== 'Cancel return'
                        ? 'A lead technician is involved, so whoever leads in their place is cancelled too.'
                        : '',
                    label: label.replace(/\b\w/g, function (letter) {
                        return letter.toUpperCase();
                    }),
                }).then(function (answer) {
                    if (!answer) {
                        if (scheduleModal && scheduleModal.isConnected) {
                            window.bootstrap.Modal.getOrCreateInstance(scheduleModal).show();
                        }

                        return;
                    }

                    confirmed = true;
                    form.requestSubmit();
                });
            }
        });
    }

    function init(root) {
        initReportImages(root);
        initTaskDates(root);
        initTeamForm(root);
        initTeamCancelForms(root);
        initPickedFileLists(root);
        initDocumentRemoval(root);
        ensureTasksTable();
    }

    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });

    document.addEventListener('workspace:updated', function (event) {
        init(event.detail.root);
    });
})();
