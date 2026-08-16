document.addEventListener("DOMContentLoaded", function () {
    const directory = Array.isArray(window.technicianDirectory)
        ? window.technicianDirectory
        : [];

    function escapeHtml(value) {
        return String(value == null ? "" : value).replace(
            /[&<>"']/g,
            function (character) {
                return {
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                }[character];
            },
        );
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    // Every endpoint on this page answers with JSON, including its errors.
    function request(url, options) {
        const config = Object.assign(
            {
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
            },
            options || {},
        );

        return fetch(url, config).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (body) {
                    return { ok: response.ok, body: body };
                });
        });
    }

    // Project names often already end in "." (e.g. "Anesi Inc."), so only add
    // one when the sentence doesn't finish with punctuation already.
    function endSentence(text) {
        return /[.!?]$/.test(text) ? text : text + ".";
    }

    function setAlert(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message || "";
        element.classList.toggle("d-none", !message);
    }

    function statusBadgeClass(status, statusLabel) {
        // The server decides the label, including "Overdue", which wins over
        // the underlying status.
        if (statusLabel === "On Hold") {
            return "bg-secondary";
        }

        if (statusLabel === "Overdue") {
            return "badge-overdue";
        }

        return (
            {
                unscheduled: "bg-info text-dark",
                pending: "bg-warning",
                ongoing: "bg-primary",
                completed: "bg-success",
                cancelled: "bg-danger",
                archived: "bg-dark",
            }[status] || "bg-secondary"
        );
    }

    function technicianChips(names) {
        if (!names || !names.length) {
            return '<span class="text-muted small">No technicians assigned</span>';
        }

        return names
            .map(function (item) {
                const label =
                    typeof item === "string"
                        ? item
                        : item.name + (item.is_lead ? " (Lead)" : "");

                return (
                    '<span class="schedule-tech-chip">' +
                    escapeHtml(label) +
                    "</span>"
                );
            })
            .join("");
    }

    // ---------------------------------------------------------------
    // Tab 1 - technician table + details / specialty management
    // ---------------------------------------------------------------

    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
        window.jQuery("#techniciansTable").DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            info: false,
            // DataTables types this column as numeric, which right-aligns it
            // and reverses the header so the sort arrow lands on the left. A
            // technician ID is a label, not a quantity, so it is put back with
            // every other column. Ordering stays numeric.
            columnDefs: [{ targets: 0, className: "dt-left" }],
            language: {
                search: "",
                searchPlaceholder: "Search technicians...",
                emptyTable: "No technicians found.",
                zeroRecords: "No technicians match your search.",
            },
        });
    }

    const detailsModalEl = document.querySelector(
        "[data-technician-details-modal]",
    );

    /**
     * Specialty changes are staged locally - ticking one to add, or clicking
     * the x on one to drop - and nothing is written until Save Changes. That
     * keeps the whole edit as a single decision the user can back out of.
     */
    function initDetailsModal(modal) {
        const nameEl = modal.querySelector("[data-details-name]");
        const metaEl = modal.querySelector("[data-details-meta]");
        const idEl = modal.querySelector("[data-details-id]");
        const positionEl = modal.querySelector("[data-details-position]");
        const emailEl = modal.querySelector("[data-details-email]");
        const specialtiesEl = modal.querySelector("[data-details-specialties]");
        const availableEl = modal.querySelector("[data-details-available]");
        const allAssignedEl = modal.querySelector("[data-details-all-assigned]");
        const errorEl = modal.querySelector("[data-details-error]");
        const pendingEl = modal.querySelector("[data-details-pending]");
        const saveBtn = modal.querySelector("[data-details-save]");
        const saveSpinner = modal.querySelector("[data-details-save-spinner]");

        // The technician's outstanding specialty request, decided here rather
        // than in a queue of its own.
        const requestEl = modal.querySelector("[data-details-request]");
        const requestChangesEl = modal.querySelector("[data-details-request-changes]");
        const requestWhenEl = modal.querySelector("[data-details-request-when]");
        const approveBtn = modal.querySelector("[data-details-approve]");
        const rejectBtn = modal.querySelector("[data-details-reject]");
        const decideSpinner = modal.querySelector("[data-details-decide-spinner]");

        let pendingRequest = null;

        let technicianId = null;
        // What the server currently has.
        let savedSpecialties = [];
        // What the modal will save: starts as a copy of savedSpecialties.
        let draftIds = [];

        function skillNameById(skillId) {
            const saved = savedSpecialties.find(function (item) {
                return item.skill_id === skillId;
            });

            if (saved) {
                return saved.skill_name;
            }

            const option = availableEl.querySelector(
                '[data-available-option="' + skillId + '"] span',
            );

            return option ? option.textContent.trim() : "Specialty";
        }

        function isDirty() {
            const saved = savedSpecialties
                .map(function (item) {
                    return item.skill_id;
                })
                .sort();
            const draft = draftIds.slice().sort();

            return (
                saved.length !== draft.length ||
                saved.some(function (id, index) {
                    return id !== draft[index];
                })
            );
        }

        function renderPendingNote() {
            const savedIds = savedSpecialties.map(function (item) {
                return item.skill_id;
            });
            const added = draftIds.filter(function (id) {
                return savedIds.indexOf(id) === -1;
            }).length;
            const removed = savedIds.filter(function (id) {
                return draftIds.indexOf(id) === -1;
            }).length;

            const parts = [];

            if (added) {
                parts.push(added + " to add");
            }

            if (removed) {
                parts.push(removed + " to remove");
            }

            pendingEl.textContent = parts.length
                ? "Unsaved: " + parts.join(", ")
                : "";
            pendingEl.classList.toggle("d-none", parts.length === 0);
        }

        /**
         * Draw the draft, not the saved state: specialties queued for removal
         * disappear from the list and reappear in the picker straight away.
         */
        function renderDraft() {
            if (!draftIds.length) {
                specialtiesEl.innerHTML =
                    '<span class="text-muted small">No specialties assigned.</span>';
            } else {
                specialtiesEl.innerHTML = draftIds
                    .map(function (skillId) {
                        const name = skillNameById(skillId);

                        return (
                            '<span class="technician-specialty-pill">' +
                            escapeHtml(name) +
                            '<button type="button" class="technician-specialty-remove" ' +
                            'data-remove-specialty="' +
                            skillId +
                            '" aria-label="Remove ' +
                            escapeHtml(name) +
                            '"><i class="bi bi-x" aria-hidden="true"></i></button>' +
                            "</span>"
                        );
                    })
                    .join("");
            }

            // Anything in the draft leaves the picker, which is what makes
            // adding a duplicate impossible.
            let remaining = 0;

            availableEl
                .querySelectorAll("[data-available-option]")
                .forEach(function (option) {
                    const skillId = parseInt(option.dataset.availableOption, 10);
                    const inDraft = draftIds.indexOf(skillId) !== -1;
                    const checkbox = option.querySelector("input");

                    option.classList.toggle("is-hidden", inDraft);
                    option.classList.remove("is-checked");
                    checkbox.checked = false;
                    checkbox.disabled = inDraft;

                    if (!inDraft) {
                        remaining++;
                    }
                });

            allAssignedEl.classList.toggle("d-none", remaining > 0);
            saveBtn.disabled = !isDirty();
            renderPendingNote();
        }

        /**
         * The pending request, as a row of additions and removals. Hidden
         * entirely when the technician has not asked for anything.
         */
        function renderRequest() {
            if (!requestEl) {
                return;
            }

            requestEl.classList.toggle("d-none", !pendingRequest);

            if (!pendingRequest) {
                return;
            }

            const chips = []
                .concat(
                    (pendingRequest.additions || []).map(function (name) {
                        return (
                            '<span class="technician-request-add">' +
                            '<i class="bi bi-plus-lg" aria-hidden="true"></i>' +
                            escapeHtml(name) +
                            "</span>"
                        );
                    }),
                )
                .concat(
                    (pendingRequest.removals || []).map(function (name) {
                        return (
                            '<span class="technician-request-remove">' +
                            '<i class="bi bi-dash-lg" aria-hidden="true"></i>' +
                            escapeHtml(name) +
                            "</span>"
                        );
                    }),
                );

            requestChangesEl.innerHTML = chips.length
                ? chips.join("")
                : '<span class="text-muted small">No change requested.</span>';

            requestWhenEl.textContent =
                "Submitted " + (pendingRequest.submitted_at || "recently") + ".";
        }

        function render(technician) {
            technicianId = technician.technician_id;
            savedSpecialties = technician.specialties.slice();
            draftIds = savedSpecialties.map(function (item) {
                return item.skill_id;
            });
            pendingRequest = technician.pending_request || null;

            nameEl.textContent = technician.name;
            metaEl.textContent =
                technician.position +
                (technician.email ? " · " + technician.email : "");
            idEl.textContent = technician.display_code;
            positionEl.textContent = technician.position;
            emailEl.textContent = technician.email || "Not on file";

            renderRequest();
            renderDraft();
            syncTableRow(technician);
        }

        /**
         * Approve or reject, then redraw from what the server sends back. The
         * table row loses its highlight in the same pass, so the page never
         * disagrees with the dialog.
         */
        function decide(url) {
            if (!pendingRequest) {
                return;
            }

            setAlert(errorEl, "");
            approveBtn.disabled = true;
            rejectBtn.disabled = true;
            decideSpinner.classList.remove("d-none");

            request(url, { method: "PUT" }).then(function (result) {
                approveBtn.disabled = false;
                rejectBtn.disabled = false;
                decideSpinner.classList.add("d-none");

                if (!result.ok) {
                    setAlert(
                        errorEl,
                        result.body.error || "That request could not be decided.",
                    );

                    return;
                }

                if (result.body.technician) {
                    render(result.body.technician);
                    clearRowHighlight(result.body.technician.technician_id);
                }
            });
        }

        if (approveBtn) {
            approveBtn.addEventListener("click", function () {
                decide(pendingRequest && pendingRequest.approve_url);
            });
        }

        if (rejectBtn) {
            rejectBtn.addEventListener("click", function () {
                decide(pendingRequest && pendingRequest.reject_url);
            });
        }

        // Ticking an available specialty queues it; nothing is saved yet.
        availableEl.addEventListener("change", function (event) {
            const option = event.target.closest("[data-available-option]");

            if (!option) {
                return;
            }

            const skillId = parseInt(option.dataset.availableOption, 10);

            if (event.target.checked) {
                if (draftIds.indexOf(skillId) === -1) {
                    draftIds.push(skillId);
                }
            } else {
                draftIds = draftIds.filter(function (id) {
                    return id !== skillId;
                });
            }

            setAlert(errorEl, "");
            renderDraft();
        });

        // Removing just drops it from the draft - no prompt, no request.
        specialtiesEl.addEventListener("click", function (event) {
            const button = event.target.closest("[data-remove-specialty]");

            if (!button) {
                return;
            }

            const skillId = parseInt(button.dataset.removeSpecialty, 10);

            draftIds = draftIds.filter(function (id) {
                return id !== skillId;
            });

            setAlert(errorEl, "");
            renderDraft();
        });

        saveBtn.addEventListener("click", function () {
            if (!isDirty()) {
                return;
            }

            saveBtn.disabled = true;
            saveSpinner.classList.remove("d-none");
            setAlert(errorEl, "");

            request("/super-admin/technicians/" + technicianId + "/specialties", {
                method: "PUT",
                body: JSON.stringify({ skill_ids: draftIds }),
            }).then(function (result) {
                saveSpinner.classList.add("d-none");

                if (!result.ok) {
                    saveBtn.disabled = false;
                    setAlert(
                        errorEl,
                        result.body.error || "Could not save the specialties.",
                    );

                    return;
                }

                render(result.body.technician);

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                }
            });
        });

        modal.addEventListener("hidden.bs.modal", function () {
            setAlert(errorEl, "");
            pendingEl.classList.add("d-none");
            pendingRequest = null;
            renderRequest();
        });

        return {
            open: function (id) {
                technicianId = id;
                savedSpecialties = [];
                draftIds = [];
                pendingRequest = null;
                renderRequest();
                setAlert(errorEl, "");
                pendingEl.classList.add("d-none");
                nameEl.textContent = "Loading…";
                metaEl.textContent = "";
                specialtiesEl.innerHTML = "";
                saveBtn.disabled = true;

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }

                request("/super-admin/technicians/" + id).then(function (result) {
                    if (!result.ok) {
                        setAlert(
                            errorEl,
                            result.body.error || "Could not load this technician.",
                        );

                        return;
                    }

                    render(result.body);
                });
            },
        };
    }

    /**
     * A decided request is no longer waiting, so the row stops shouting about
     * it - without a reload, since the dialog is still open over the top.
     */
    function clearRowHighlight(technicianId) {
        const row = document.querySelector(
            '[data-technician-row="' + technicianId + '"]',
        );

        if (!row) {
            return;
        }

        row.classList.remove("technician-row-pending");

        const badge = row.querySelector(".technician-pending-badge");

        if (badge) {
            badge.remove();
        }
    }

    // Keep the Specialty column in step with the modal, no reload needed.
    function syncTableRow(technician) {
        const cell = document.querySelector(
            '[data-technician-specialties="' + technician.technician_id + '"]',
        );

        if (!cell) {
            return;
        }

        const html = technician.specialties.length
            ? technician.specialties
                  .map(function (specialty) {
                      return (
                          '<span class="technician-chip">' +
                          escapeHtml(specialty.skill_name) +
                          "</span>"
                      );
                  })
                  .join("")
            : '<span class="text-muted small">No specialties assigned.</span>';

        // Go through DataTables when it owns the cell so searching and
        // sorting see the new value too.
        if (
            window.jQuery &&
            window.jQuery.fn.dataTable &&
            window.jQuery.fn.dataTable.isDataTable("#techniciansTable")
        ) {
            window
                .jQuery("#techniciansTable")
                .DataTable()
                .cell(cell)
                .data(html)
                .draw(false);

            return;
        }

        cell.innerHTML = html;
    }

    const detailsModal = detailsModalEl
        ? initDetailsModal(detailsModalEl)
        : null;

    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-view-technician]");

        if (!button || !detailsModal) {
            return;
        }

        detailsModal.open(button.dataset.viewTechnician);
    });

    // ---------------------------------------------------------------
    // Tab 2 - technician schedules
    // ---------------------------------------------------------------

    const picker = document.querySelector("[data-technician-picker]");
    const pickerHint = document.querySelector("[data-technician-picker-hint]");
    const calendarPlaceholderEl = document.querySelector(
        "[data-calendar-placeholder]",
    );
    const calendarEl = document.getElementById("technicianCalendar");
    const calendarNameEl = document.querySelector(
        "[data-calendar-technician-name]",
    );
    const calendarCountEl = document.querySelector(
        "[data-calendar-assignment-count]",
    );
    const calendarEmptyEl = document.querySelector("[data-calendar-empty]");
    const addOpenBtn = document.querySelector("[data-add-to-project-open]");

    const assignmentsBodyEl = document.querySelector("[data-assignments-body]");
    const assignmentsCountEl = document.querySelector("[data-assignments-count]");
    const assignmentsEmptyEl = document.querySelector("[data-assignments-empty]");
    const assignmentsPlaceholderEl = document.querySelector(
        "[data-assignments-placeholder]",
    );

    const detailsPanelEl = document.querySelector("[data-details-panel]");
    const addProjectModalEl = document.querySelector("[data-add-project-modal]");

    let calendar = null;
    let selectedTechnician = null;

    /**
     * The picker is a datalist, so the value arrives as "TECH-0012 — Jane
     * Doe". Match on the leading code first, then fall back to an exact name.
     * The prefix is optional so somebody who types the bare number still
     * lands on the right technician.
     */
    function resolveTechnician(rawValue) {
        const value = String(rawValue || "").trim();

        if (!value) {
            return null;
        }

        const idMatch = value.match(/^\s*(?:TECH[\s-]*)?0*(\d+)/i);

        if (idMatch) {
            const byId = directory.find(function (item) {
                return String(item.technician_id) === idMatch[1];
            });

            if (byId) {
                return byId;
            }
        }

        const lowered = value.toLowerCase();

        return (
            directory.find(function (item) {
                return item.name.toLowerCase() === lowered;
            }) ||
            directory.find(function (item) {
                return lowered.indexOf(item.name.toLowerCase()) !== -1;
            }) ||
            null
        );
    }

    function ensureCalendar() {
        if (calendar || !calendarEl || !window.FullCalendar) {
            return calendar;
        }

        calendar = new window.FullCalendar.Calendar(calendarEl, {
            initialView: "dayGridMonth",
            headerToolbar: window.calendarHeader.toolbar(),
            height: "auto",
            dayMaxEvents: true,
            eventDisplay: "block",
            // Partial days arrive as timed events; without this the bar would
            // abbreviate 8:00 AM to "8a".
            eventTimeFormat: {
                hour: "numeric",
                minute: "2-digit",
                meridiem: "short",
            },
            events: [],
            eventDidMount: function (info) {
                const props = info.event.extendedProps;
                const parts = [info.event.title];

                if (props.projectName) {
                    parts.push(props.projectName);
                }

                if (props.client) {
                    parts.push(props.client);
                }

                if (props.rangeLabel) {
                    parts.push(props.rangeLabel);
                }

                if (props.statusLabel) {
                    parts.push(props.statusLabel);
                }

                info.el.setAttribute("title", parts.join(" · "));
            },
            eventClick: function (info) {
                if (!detailsPanel || !selectedTechnician) {
                    return;
                }

                // Fills the right-hand panel; no modal is opened.
                detailsPanel.open(info.event.extendedProps.projectId);
                highlightAssignmentRow(info.event.extendedProps.projectId);
            },
        });

        calendar.render();
        window.calendarHeader.attach(calendar, calendarEl);

        return calendar;
    }

    /**
     * A project can be booked in several blocks, so list every range on its
     * own line rather than collapsing them to one span.
     */
    function scheduleCell(project) {
        const ranges = project.ranges || [];

        if (!ranges.length) {
            return '<span class="text-muted">No schedule set</span>';
        }

        return ranges
            .map(function (range) {
                return (
                    "<div>" +
                    escapeHtml(range.short_label || range.start + " - " + range.end) +
                    "</div>"
                );
            })
            .join("");
    }

    /**
     * Table of every project the technician is on, including ones with no
     * schedule yet (those never appear on the calendar). Clicking a row
     * opens the same details panel a calendar event would.
     */
    function renderAssignments(projects) {
        const rows = projects || [];

        assignmentsPlaceholderEl.classList.add("d-none");
        assignmentsEmptyEl.classList.toggle("d-none", rows.length > 0);
        assignmentsCountEl.textContent =
            rows.length + (rows.length === 1 ? " project" : " projects");
        assignmentsCountEl.classList.toggle("d-none", rows.length === 0);

        assignmentsBodyEl.innerHTML = rows
            .map(function (project) {
                return (
                    '<tr data-assignment-row="' +
                    project.project_id +
                    '">' +
                    "<td>" +
                    '<a href="' +
                    escapeHtml(project.url) +
                    '" target="_blank" rel="noopener">' +
                    escapeHtml(project.reference_no || "—") +
                    "</a>" +
                    "</td>" +
                    '<td class="fw-semibold">' +
                    escapeHtml(project.name) +
                    "</td>" +
                    "<td>" +
                    escapeHtml(project.client || "—") +
                    "</td>" +
                    '<td class="small">' +
                    scheduleCell(project) +
                    "</td>" +
                    '<td class="text-center">' +
                    project.technician_task_count +
                    "</td>" +
                    "<td>" +
                    '<span class="badge ' +
                    statusBadgeClass(project.status, project.status_label) +
                    '">' +
                    escapeHtml(project.status_label) +
                    "</span>" +
                    "</td>" +
                    "</tr>"
                );
            })
            .join("");
    }

    if (assignmentsBodyEl) {
        assignmentsBodyEl.addEventListener("click", function (event) {
            const row = event.target.closest("[data-assignment-row]");

            // Let the reference-number link do its own thing.
            if (!row || event.target.closest("a") || !detailsPanel) {
                return;
            }

            detailsPanel.open(parseInt(row.dataset.assignmentRow, 10));
            highlightAssignmentRow(parseInt(row.dataset.assignmentRow, 10));
        });
    }

    function highlightAssignmentRow(projectId) {
        assignmentsBodyEl
            .querySelectorAll("[data-assignment-row]")
            .forEach(function (row) {
                row.classList.toggle(
                    "is-active",
                    parseInt(row.dataset.assignmentRow, 10) === projectId,
                );
            });
    }

    function loadCalendar() {
        if (!selectedTechnician) {
            return Promise.resolve();
        }

        return request(
            "/super-admin/technicians/" +
                selectedTechnician.technician_id +
                "/calendar",
        ).then(function (result) {
            if (!result.ok) {
                return;
            }

            const events = result.body.events || [];

            // The calendar column is always on screen; only its contents
            // swap between the placeholder and the grid.
            calendarPlaceholderEl.classList.add("d-none");
            calendarEl.classList.remove("d-none");
            calendarNameEl.textContent = result.body.technician.name;
            // What they are carrying now, not everything they have ever been
            // assigned: completed work is in the table below, not in this
            // figure.
            const activeCount = result.body.activeCount || 0;

            calendarCountEl.textContent =
                activeCount +
                (activeCount === 1 ? " active project" : " active projects");
            calendarCountEl.classList.remove("d-none");
            calendarEmptyEl.classList.toggle("d-none", events.length > 0);

            renderAssignments(result.body.projects);

            const instance = ensureCalendar();

            if (instance) {
                instance.removeAllEvents();
                events.forEach(function (event) {
                    instance.addEvent(event);
                });
                // The card was hidden while the calendar rendered, so its
                // measurements are stale until it becomes visible.
                instance.updateSize();
            }
        });
    }

    if (picker) {
        picker.addEventListener("change", function () {
            const match = resolveTechnician(picker.value);

            if (!match) {
                selectedTechnician = null;
                calendarEl.classList.add("d-none");
                calendarPlaceholderEl.classList.remove("d-none");
                calendarPlaceholderEl.textContent =
                    "Please select a technician to view their schedule.";
                calendarEmptyEl.classList.add("d-none");
                calendarCountEl.classList.add("d-none");
                calendarNameEl.textContent = "No technician selected";
                addOpenBtn.classList.add("d-none");
                pickerHint.textContent = picker.value
                    ? "No technician matches that. Pick one from the list."
                    : "Pick a technician to load their calendar.";

                assignmentsBodyEl.innerHTML = "";
                assignmentsEmptyEl.classList.add("d-none");
                assignmentsCountEl.classList.add("d-none");
                assignmentsPlaceholderEl.classList.remove("d-none");

                if (detailsPanel) {
                    detailsPanel.reset();
                }

                return;
            }

            selectedTechnician = match;
            picker.value = match.display_code + " — " + match.name;
            pickerHint.textContent = "Showing schedule for " + match.name + ".";
            addOpenBtn.classList.remove("d-none");

            // A new technician means any previously shown project is stale.
            if (detailsPanel) {
                detailsPanel.reset();
            }

            loadCalendar();
        });
    }

    // Switching to the Schedules tab reveals the calendar container, which
    // FullCalendar needs to re-measure.
    const schedulesTabBtn = document.getElementById("technicianSchedulesTab");

    if (schedulesTabBtn) {
        schedulesTabBtn.addEventListener("shown.bs.tab", function () {
            if (calendar) {
                calendar.updateSize();
            }
        });
    }

    // ---------------------------------------------------------------
    // Project details panel (permanent, right-hand column)
    //
    // Replaces the old modal. Clicking a calendar event fills this in; the
    // panel is never hidden, it just switches between three states.
    // ---------------------------------------------------------------

    function initDetailsPanel(panel) {
        const noTechnicianEl = panel.querySelector("[data-panel-no-technician]");
        const noProjectEl = panel.querySelector("[data-panel-no-project]");
        const projectEl = panel.querySelector("[data-panel-project]");

        const refEl = panel.querySelector("[data-panel-ref]");
        const refTextEl = panel.querySelector("[data-panel-ref-text]");
        const nameEl = panel.querySelector("[data-panel-name]");
        const clientWrap = panel.querySelector("[data-panel-client-wrap]");
        const clientEl = panel.querySelector("[data-panel-client]");
        const addressWrap = panel.querySelector("[data-panel-address-wrap]");
        const addressEl = panel.querySelector("[data-panel-address]");
        const scheduleEl = panel.querySelector("[data-panel-schedule]");
        const statusEl = panel.querySelector("[data-panel-status]");

        const leadEl = panel.querySelector("[data-panel-lead]");
        const supportingEl = panel.querySelector("[data-panel-supporting]");

        const taskListEl = panel.querySelector("[data-panel-tasks]");
        const tasksEmptyEl = panel.querySelector("[data-panel-tasks-empty]");
        const taskCountEl = panel.querySelector("[data-panel-task-count]");
        const taskNoteEl = panel.querySelector("[data-panel-tasks-note]");

        const leadPanelEl = panel.querySelector("[data-panel-lead-replacement]");
        const leadIntroEl = panel.querySelector("[data-panel-lead-intro]");
        const leadOptionsEl = panel.querySelector("[data-panel-lead-options]");
        const leadEmptyEl = panel.querySelector("[data-panel-lead-empty]");

        const errorEl = panel.querySelector("[data-panel-error]");
        const successEl = panel.querySelector("[data-panel-success]");
        const removeBtn = panel.querySelector("[data-panel-remove]");
        const confirmBtn = panel.querySelector("[data-panel-confirm-remove]");
        const confirmSpinner = panel.querySelector("[data-panel-confirm-spinner]");
        const cancelBtn = panel.querySelector("[data-panel-cancel-remove]");

        let projectId = null;
        let payload = null;
        let selectedLeadId = null;

        function showState(state) {
            noTechnicianEl.classList.toggle("d-none", state !== "no-technician");
            noProjectEl.classList.toggle("d-none", state !== "no-project");
            projectEl.classList.toggle("d-none", state !== "project");
        }

        function resetRemovalUi() {
            leadPanelEl.classList.add("d-none");
            leadOptionsEl.innerHTML = "";
            leadEmptyEl.classList.add("d-none");
            confirmBtn.classList.add("d-none");
            confirmBtn.disabled = true;
            cancelBtn.classList.add("d-none");
            selectedLeadId = null;
        }

        function taskBadgeClass(status) {
            return (
                {
                    unassigned: "bg-warning text-dark",
                    pending: "bg-secondary",
                    ongoing: "bg-primary",
                    completed: "bg-success",
                    cancelled: "bg-danger",
                }[status] || "bg-secondary"
            );
        }

        function renderTasks(tasks) {
            taskListEl.innerHTML = (tasks || [])
                .map(function (task) {
                    return (
                        '<div class="panel-task-card">' +
                        '<div class="panel-task-range">' +
                        escapeHtml(task.range_label) +
                        "</div>" +
                        '<div class="panel-task-title">' +
                        escapeHtml(task.title) +
                        "</div>" +
                        (task.description
                            ? '<div class="panel-task-description">' +
                              escapeHtml(task.description) +
                              "</div>"
                            : "") +
                        '<div class="panel-task-meta">' +
                        '<span class="badge ' +
                        taskBadgeClass(task.status) +
                        '">' +
                        escapeHtml(task.status_label) +
                        "</span>" +
                        (task.technician
                            ? '<span class="panel-task-technician">' +
                              escapeHtml(task.technician) +
                              "</span>"
                            : "") +
                        "</div>" +
                        "</div>"
                    );
                })
                .join("");

            const count = (tasks || []).length;

            tasksEmptyEl.classList.toggle("d-none", count > 0);
            taskCountEl.textContent = count + (count === 1 ? " task" : " tasks");
            taskCountEl.classList.toggle("d-none", count === 0);
            taskNoteEl.textContent =
                "Assigned to " + selectedTechnician.name + " on this project.";
            taskNoteEl.classList.toggle("d-none", count === 0);
        }

        function renderLeadOptions() {
            const candidates = payload.replacement_leads || [];

            leadPanelEl.classList.remove("d-none");
            leadIntroEl.textContent =
                selectedTechnician.name +
                " is the lead technician on this project. Choose a replacement who is free for its whole schedule.";

            if (!candidates.length) {
                leadOptionsEl.innerHTML = "";
                leadEmptyEl.classList.remove("d-none");
                confirmBtn.disabled = true;

                return;
            }

            leadEmptyEl.classList.add("d-none");
            leadOptionsEl.innerHTML = candidates
                .map(function (candidate) {
                    return (
                        '<label class="technician-lead-option" data-lead-option="' +
                        candidate.technician_id +
                        '">' +
                        '<input type="radio" name="panelReplacementLead" class="form-check-input" value="' +
                        candidate.technician_id +
                        '">' +
                        "<span>" +
                        '<span class="technician-lead-name">' +
                        escapeHtml(candidate.name) +
                        "</span>" +
                        '<span class="technician-lead-skills">' +
                        (candidate.skills && candidate.skills.length
                            ? escapeHtml(candidate.skills.join(", "))
                            : "No specialties recorded") +
                        "</span>" +
                        "</span>" +
                        "</label>"
                    );
                })
                .join("");

            leadOptionsEl
                .querySelectorAll('input[type="radio"]')
                .forEach(function (radio) {
                    radio.addEventListener("change", function () {
                        selectedLeadId = parseInt(radio.value, 10);

                        leadOptionsEl
                            .querySelectorAll("[data-lead-option]")
                            .forEach(function (option) {
                                option.classList.toggle(
                                    "is-selected",
                                    parseInt(option.dataset.leadOption, 10) ===
                                        selectedLeadId,
                                );
                            });

                        confirmBtn.disabled = false;
                        setAlert(errorEl, "");
                    });
                });
        }

        function render(data) {
            payload = data;

            const project = data.project;

            refEl.href = project.url;
            refTextEl.textContent = project.reference_no || "No reference";
            nameEl.textContent = project.name;

            clientEl.textContent = project.client || "";
            clientWrap.classList.toggle("d-none", !project.client);

            addressEl.textContent = project.address || "";
            addressWrap.classList.toggle("d-none", !project.address);

            // Always the project's full schedule, never the clicked day.
            const ranges = project.ranges || [];
            scheduleEl.textContent = ranges.length
                ? ranges
                      .map(function (range) {
                          return range.label;
                      })
                      .join("  •  ")
                : "No schedule set";

            statusEl.innerHTML =
                '<span class="badge ' +
                statusBadgeClass(project.status, project.status_label) +
                '">' +
                escapeHtml(project.status_label) +
                "</span>";

            const technicians = project.technicians || [];
            const lead = technicians.find(function (item) {
                return item.is_lead;
            });
            const supporting = technicians.filter(function (item) {
                return !item.is_lead;
            });

            leadEl.textContent = lead ? lead.name : "None assigned";
            supportingEl.innerHTML = supporting.length
                ? supporting
                      .map(function (item) {
                          return (
                              '<span class="schedule-tech-chip">' +
                              escapeHtml(item.name) +
                              "</span>"
                          );
                      })
                      .join("")
                : '<span class="text-muted small">None</span>';

            renderTasks(project.tasks);
            resetRemovalUi();
            setAlert(errorEl, "");
            setAlert(successEl, "");

            removeBtn.classList.toggle("d-none", Boolean(data.read_only));

            if (data.read_only) {
                setAlert(
                    errorEl,
                    "This project is " +
                        project.status +
                        ", so its team can no longer be changed.",
                );
            }

            showState("project");
        }

        removeBtn.addEventListener("click", function () {
            if (!payload) {
                return;
            }

            if (!payload.is_lead) {
                if (payload.remaining_after_removal < 1) {
                    setAlert(
                        errorEl,
                        "A project must keep at least one technician. Assign someone else first.",
                    );

                    return;
                }

                submitRemoval(null);

                return;
            }

            // Leads need a replacement picked first, inline in this panel.
            removeBtn.classList.add("d-none");
            confirmBtn.classList.remove("d-none");
            cancelBtn.classList.remove("d-none");
            renderLeadOptions();
        });

        cancelBtn.addEventListener("click", function () {
            resetRemovalUi();
            removeBtn.classList.remove("d-none");
            setAlert(errorEl, "");
        });

        confirmBtn.addEventListener("click", function () {
            if (!selectedLeadId) {
                setAlert(errorEl, "Choose a replacement lead technician.");

                return;
            }

            submitRemoval(selectedLeadId);
        });

        function submitRemoval(replacementLeadId) {
            const busyButton = replacementLeadId ? confirmBtn : removeBtn;

            busyButton.disabled = true;

            if (replacementLeadId) {
                confirmSpinner.classList.remove("d-none");
            }

            setAlert(errorEl, "");

            request(
                "/super-admin/technicians/" +
                    selectedTechnician.technician_id +
                    "/projects/" +
                    projectId,
                {
                    method: "DELETE",
                    body: JSON.stringify({
                        replacement_lead_id: replacementLeadId,
                    }),
                },
            ).then(function (result) {
                confirmSpinner.classList.add("d-none");
                busyButton.disabled = false;

                if (!result.ok) {
                    setAlert(
                        errorEl,
                        result.body.error || "Could not remove the technician.",
                    );

                    return;
                }

                setAlert(successEl, result.body.message);

                // The technician is off this project, so it leaves their
                // calendar and the panel drops back to its empty state.
                loadCalendar().then(function () {
                    window.setTimeout(function () {
                        clearProject();
                    }, 1200);
                });
            });
        }

        function clearProject() {
            projectId = null;
            payload = null;
            highlightAssignmentRow(null);
            resetRemovalUi();
            setAlert(errorEl, "");
            setAlert(successEl, "");
            showState(selectedTechnician ? "no-project" : "no-technician");
        }

        return {
            reset: clearProject,
            open: function (id) {
                projectId = id;
                payload = null;
                resetRemovalUi();
                setAlert(errorEl, "");
                setAlert(successEl, "");

                request(
                    "/super-admin/technicians/" +
                        selectedTechnician.technician_id +
                        "/projects/" +
                        id,
                ).then(function (result) {
                    if (!result.ok) {
                        showState("project");
                        nameEl.textContent = "Unavailable";
                        setAlert(
                            errorEl,
                            result.body.error ||
                                "Could not load this assignment.",
                        );

                        return;
                    }

                    render(result.body);
                });
            },
        };
    }

    const detailsPanel = detailsPanelEl
        ? initDetailsPanel(detailsPanelEl)
        : null;

    // ---------------------------------------------------------------
    // Add technician to project(s)
    // ---------------------------------------------------------------

    function initAddProjectModal(modal) {
        const nameEl = modal.querySelector("[data-add-technician-name]");
        const loadingEl = modal.querySelector("[data-add-loading]");
        const listEl = modal.querySelector("[data-add-list]");
        const emptyEl = modal.querySelector("[data-add-empty]");
        const countEl = modal.querySelector("[data-add-count]");
        const blockedWrap = modal.querySelector("[data-add-blocked-wrap]");
        const blockedToggle = modal.querySelector("[data-add-blocked-toggle]");
        const blockedLabel = modal.querySelector("[data-add-blocked-label]");
        const blockedList = modal.querySelector("[data-add-blocked-list]");
        const errorEl = modal.querySelector("[data-add-error]");
        const successEl = modal.querySelector("[data-add-success]");
        const saveBtn = modal.querySelector("[data-add-save]");
        const saveSpinner = modal.querySelector("[data-add-save-spinner]");

        let projects = [];
        let selectedIds = [];

        function rangesOverlap(a, b) {
            return a.start <= b.end && a.end >= b.start;
        }

        /**
         * A project is unpickable while any of its date ranges collides with
         * a range from something already ticked. Recomputed on every change,
         * so the list stays honest without another round trip.
         */
        function refreshStates() {
            const claimed = [];

            selectedIds.forEach(function (id) {
                const project = projects.find(function (item) {
                    return item.project_id === id;
                });

                if (project) {
                    (project.ranges || []).forEach(function (range) {
                        claimed.push({ range: range, name: project.name });
                    });
                }
            });

            projects.forEach(function (project) {
                const row = listEl.querySelector(
                    '[data-pick="' + project.project_id + '"]',
                );

                if (!row) {
                    return;
                }

                const checkbox = row.querySelector('input[type="checkbox"]');
                const reasonEl = row.querySelector("[data-pick-reason]");
                const isSelected = selectedIds.indexOf(project.project_id) !== -1;
                let blockedBy = null;

                if (!isSelected) {
                    (project.ranges || []).some(function (range) {
                        const hit = claimed.find(function (entry) {
                            return rangesOverlap(range, entry.range);
                        });

                        if (hit) {
                            blockedBy = hit.name;

                            return true;
                        }

                        return false;
                    });
                }

                checkbox.disabled = Boolean(blockedBy);
                row.classList.toggle("is-disabled", Boolean(blockedBy));
                row.classList.toggle("is-selected", isSelected);

                if (blockedBy) {
                    const message = endSentence(
                        "Unavailable because its schedule overlaps " + blockedBy,
                    );
                    row.setAttribute("title", message);
                    reasonEl.textContent = message;
                    reasonEl.classList.remove("d-none");
                } else {
                    row.removeAttribute("title");
                    reasonEl.textContent = "";
                    reasonEl.classList.add("d-none");
                }
            });

            saveBtn.disabled = selectedIds.length === 0;
        }

        function pickMarkup(project, selectable) {
            const meta = [];

            if (project.reference_no) {
                meta.push(escapeHtml(project.reference_no));
            }

            if (project.client) {
                meta.push(escapeHtml(project.client));
            }

            meta.push(escapeHtml(project.range_label));

            return (
                '<label class="schedule-pick' +
                (selectable ? "" : " is-disabled") +
                '" data-pick="' +
                project.project_id +
                '">' +
                (selectable
                    ? '<input type="checkbox" class="form-check-input" value="' +
                      project.project_id +
                      '">'
                    : "") +
                '<span class="schedule-pick-body">' +
                '<span class="schedule-pick-name">' +
                escapeHtml(project.name) +
                '</span><span class="schedule-pick-meta">' +
                meta.join(" &middot; ") +
                '</span><span class="schedule-pick-techs">' +
                technicianChips(project.technicians) +
                '</span><span class="schedule-pick-reason' +
                (project.reason ? "" : " d-none") +
                '" data-pick-reason>' +
                escapeHtml(project.reason || "") +
                "</span></span></label>"
            );
        }

        function render(body) {
            projects = body.projects || [];
            selectedIds = [];

            const blocked = body.blocked || [];

            listEl.innerHTML = projects
                .map(function (project) {
                    return pickMarkup(project, true);
                })
                .join("");

            emptyEl.classList.toggle("d-none", projects.length > 0);
            countEl.textContent = projects.length + " available";
            countEl.classList.toggle("d-none", projects.length === 0);

            blockedList.innerHTML = blocked
                .map(function (project) {
                    return pickMarkup(project, false);
                })
                .join("");
            blockedWrap.classList.toggle("d-none", blocked.length === 0);
            blockedLabel.textContent =
                "Show unavailable projects (" + blocked.length + ")";

            listEl
                .querySelectorAll('input[type="checkbox"]')
                .forEach(function (checkbox) {
                    checkbox.addEventListener("change", function () {
                        const id = parseInt(checkbox.value, 10);

                        if (checkbox.checked) {
                            if (selectedIds.indexOf(id) === -1) {
                                selectedIds.push(id);
                            }
                        } else {
                            selectedIds = selectedIds.filter(function (item) {
                                return item !== id;
                            });
                        }

                        setAlert(errorEl, "");
                        refreshStates();
                    });
                });

            refreshStates();
        }

        blockedToggle.addEventListener("click", function () {
            const isOpen = !blockedList.classList.contains("d-none");

            blockedList.classList.toggle("d-none", isOpen);
            blockedToggle.classList.toggle("is-open", !isOpen);
            blockedLabel.textContent =
                (isOpen ? "Show" : "Hide") +
                " unavailable projects (" +
                blockedList.children.length +
                ")";
        });

        saveBtn.addEventListener("click", function () {
            if (!selectedIds.length) {
                return;
            }

            saveBtn.disabled = true;
            saveSpinner.classList.remove("d-none");
            setAlert(errorEl, "");

            request(
                "/super-admin/technicians/" +
                    selectedTechnician.technician_id +
                    "/projects",
                {
                    method: "POST",
                    body: JSON.stringify({ project_ids: selectedIds }),
                },
            ).then(function (result) {
                saveSpinner.classList.add("d-none");

                if (!result.ok) {
                    saveBtn.disabled = false;
                    setAlert(
                        errorEl,
                        result.body.error || "Could not save the assignment.",
                    );

                    return;
                }

                setAlert(successEl, result.body.message);

                // New assignments change the calendar and invalidate whatever
                // the details panel was showing.
                loadCalendar().then(function () {
                    if (detailsPanel) {
                        detailsPanel.reset();
                    }

                    window.setTimeout(function () {
                        if (window.bootstrap) {
                            window.bootstrap.Modal.getOrCreateInstance(
                                modal,
                            ).hide();
                        }
                    }, 800);
                });
            });
        });

        modal.addEventListener("hidden.bs.modal", function () {
            setAlert(errorEl, "");
            setAlert(successEl, "");
        });

        return {
            open: function () {
                projects = [];
                selectedIds = [];
                nameEl.textContent = selectedTechnician.name;
                listEl.innerHTML = "";
                blockedList.innerHTML = "";
                blockedWrap.classList.add("d-none");
                emptyEl.classList.add("d-none");
                countEl.classList.add("d-none");
                saveBtn.disabled = true;
                setAlert(errorEl, "");
                setAlert(successEl, "");
                loadingEl.classList.remove("d-none");

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }

                request(
                    "/super-admin/technicians/" +
                        selectedTechnician.technician_id +
                        "/assignable-projects",
                ).then(function (result) {
                    loadingEl.classList.add("d-none");

                    if (!result.ok) {
                        setAlert(
                            errorEl,
                            result.body.error ||
                                "Could not load available projects.",
                        );

                        return;
                    }

                    render(result.body);
                });
            },
        };
    }

    const addProjectModal = addProjectModalEl
        ? initAddProjectModal(addProjectModalEl)
        : null;

    if (addOpenBtn) {
        addOpenBtn.addEventListener("click", function () {
            if (addProjectModal && selectedTechnician) {
                addProjectModal.open();
            }
        });
    }
});
