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

    // Kept so the specialty deep link below can narrow it - see
    // showPendingSpecialtyRequests().
    let techniciansTable = null;

    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
        techniciansTable = window.jQuery("#techniciansTable").DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            info: false,
            // DataTables types this column as numeric, which right-aligns it
            // and reverses the header so the sort arrow lands on the left. A
            // technician ID is a label, not a quantity, so it is put back with
            // every other column. Ordering stays numeric.
            columnDefs: [
                { targets: 0, className: "dt-left" },
                // A column of buttons has no order, and a sortable header
                // invites a click that does nothing.
                { targets: -1, orderable: false },
            ],
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
        const statusEl = modal.querySelector("[data-details-status]");
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

            // Colour and wording both come from the server, which reads them
            // off the account - so this dialog cannot describe a state
            // differently from the row that opened it.
            statusEl.textContent = technician.status_label || "";
            statusEl.className =
                "badge " + (technician.status_badge_class || "bg-secondary");

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
                        result.body.error || "Unable to decide that request.",
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
                        result.body.error || "Unable to save specialties.",
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

        // Straight from this dialog to the Schedules tab, landing on the
        // technician it is showing. The tab switch and the picker are both
        // done through the controls that already exist - the tab's own
        // trigger, and window.technicianSchedules.show() - so this is a
        // shortcut through the page rather than a second way of loading a
        // calendar.
        const viewScheduleBtn = modal.querySelector("[data-details-view-schedule]");

        if (viewScheduleBtn) {
            viewScheduleBtn.addEventListener("click", function () {
                if (technicianId === null) {
                    return;
                }

                const wanted = technicianId;

                // The calendar is measured as the tab is revealed, so the
                // technician is chosen after the switch rather than before it.
                const schedulesTab = document.getElementById(
                    "technicianSchedulesTab",
                );

                const openSchedule = function () {
                    if (window.bootstrap && schedulesTab) {
                        window.bootstrap.Tab.getOrCreateInstance(schedulesTab).show();
                    }

                    if (window.technicianSchedules) {
                        window.technicianSchedules.show(wanted);
                    }
                };

                if (window.bootstrap) {
                    const instance = window.bootstrap.Modal.getInstance(modal);

                    if (instance) {
                        // Waiting for the dialog to finish closing keeps the
                        // backdrop from being left over the tab underneath.
                        modal.addEventListener("hidden.bs.modal", openSchedule, {
                            once: true,
                        });
                        instance.hide();

                        return;
                    }
                }

                openSchedule();
            });
        }

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
                            result.body.error || "Unable to load technician.",
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

    /**
     * Arriving from the dashboard's "N Specialty Change Requests".
     *
     * The figure promised the requests, so the table is narrowed to the people
     * waiting on one rather than left for the reviewer to pick out of every
     * technician on the books. One request opens straight into its dialog,
     * where it is approved or rejected; several do not, because choosing which
     * of them to open is the reviewer's call and not this file's.
     *
     * The banner above the table grows a way back out, so a table narrowed by
     * a link is never a table somebody has to reload to escape.
     */
    function showPendingSpecialtyRequests() {
        const asked =
            new URLSearchParams(window.location.search).get("specialty") ===
            "pending";

        const waiting = document.querySelectorAll(
            "[data-technician-row].technician-row-pending",
        );

        if (!asked || !waiting.length) {
            return;
        }

        const clear = document.querySelector("[data-specialty-filter-clear]");

        if (techniciansTable && window.jQuery) {
            const $ = window.jQuery;

            const filterFn = function (settings, data, dataIndex) {
                if (settings.nTable.id !== "techniciansTable") {
                    return true;
                }

                const node = techniciansTable.row(dataIndex).node();

                return node
                    ? node.classList.contains("technician-row-pending")
                    : true;
            };

            filterFn._specialtyPending = true;
            $.fn.dataTable.ext.search.push(filterFn);
            techniciansTable.draw();

            if (clear) {
                clear.classList.remove("d-none");

                clear.addEventListener("click", function () {
                    $.fn.dataTable.ext.search =
                        $.fn.dataTable.ext.search.filter(function (fn) {
                            return !fn._specialtyPending;
                        });

                    techniciansTable.draw();
                    clear.classList.add("d-none");
                });
            }
        }

        if (waiting.length === 1 && detailsModal) {
            detailsModal.open(waiting[0].getAttribute("data-technician-row"));
        }
    }

    showPendingSpecialtyRequests();

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

    // ---------------------------------------------------------------
    // The technician picker
    //
    // The same searchable list the Export Logs dialog uses - type any part of
    // a name, code, address or role, and the matches drop down beneath. It
    // replaced a native <datalist>, which matched only the start of the value
    // in some browsers, so a surname found nobody.
    // ---------------------------------------------------------------

    const pickerResults = document.querySelector(
        "[data-technician-picker-results]",
    );
    const pickerClear = document.querySelector("[data-technician-picker-clear]");

    const PICKER_RESULT_LIMIT = 8;

    function pickerLabel(technician) {
        return technician.display_code + " — " + technician.name;
    }

    function closePickerResults() {
        if (!pickerResults) {
            return;
        }

        pickerResults.classList.add("d-none");
        pickerResults.innerHTML = "";

        if (picker) {
            picker.setAttribute("aria-expanded", "false");
        }
    }

    function renderPickerResults(term) {
        if (!pickerResults) {
            return;
        }

        const needle = String(term || "").trim().toLowerCase();

        if (!needle) {
            closePickerResults();

            return;
        }

        const contains = function (field) {
            return Boolean(
                field && String(field).toLowerCase().indexOf(needle) !== -1,
            );
        };

        // A match on who they are outranks a match on what they are: every
        // technician's role reads "Technician", so that word alone would
        // otherwise fill the list with people whose ROLE matched while the one
        // whose NAME matched was cut off the end.
        const byIdentity = directory.filter(function (technician) {
            return (
                contains(technician.name) ||
                contains(technician.display_code) ||
                contains(technician.code) ||
                contains(technician.email)
            );
        });

        const byRole = directory.filter(function (technician) {
            return (
                byIdentity.indexOf(technician) === -1 &&
                contains(technician.role_label)
            );
        });

        const ranked = byIdentity.concat(byRole);
        const matches = ranked.slice(0, PICKER_RESULT_LIMIT);
        const hidden = ranked.length - matches.length;

        if (!matches.length) {
            pickerResults.innerHTML =
                '<li class="config-actor-empty">No technician by that name.</li>';
            pickerResults.classList.remove("d-none");
            picker.setAttribute("aria-expanded", "true");

            return;
        }

        pickerResults.innerHTML = matches
            .map(function (technician) {
                // An account that cannot take new work still gets a row - their
                // calendar is the point of this tab - but says so, because it
                // is why the Add button will not appear once they are chosen.
                const inactive =
                    technician.can_receive_work === false
                        ? " · " +
                          escapeHtml(technician.status_label || "inactive")
                        : "";

                return (
                    '<li><button type="button" class="config-actor-option" data-picker-id="' +
                    escapeHtml(String(technician.technician_id)) +
                    '" role="option">' +
                    '<span class="config-actor-name">' +
                    escapeHtml(technician.name) +
                    "</span>" +
                    '<span class="config-actor-meta">' +
                    escapeHtml(technician.display_code) +
                    " · " +
                    escapeHtml(technician.role_label || "Technician") +
                    inactive +
                    "</span>" +
                    "</button></li>"
                );
            })
            .join("");

        if (hidden > 0) {
            pickerResults.innerHTML +=
                '<li class="config-actor-empty">' +
                hidden +
                " more match" +
                (hidden === 1 ? "" : "es") +
                ". Keep typing to narrow the list.</li>";
        }

        pickerResults.classList.remove("d-none");
        picker.setAttribute("aria-expanded", "true");
    }

    /**
     * Nobody chosen: the calendar goes back to its resting state.
     *
     * `message` is what the hint says - which differs between "you have not
     * picked anybody yet" and "what you typed matches nobody".
     */
    function clearSelectedTechnician(message) {
        selectedTechnician = null;

        calendarEl.classList.add("d-none");
        calendarPlaceholderEl.classList.remove("d-none");
        calendarPlaceholderEl.textContent =
            "Select a technician to view their schedule.";
        calendarEmptyEl.classList.add("d-none");
        calendarCountEl.classList.add("d-none");
        calendarNameEl.textContent = "No technician selected";
        addOpenBtn.classList.add("d-none");
        pickerHint.textContent =
            message || "Pick a technician to load their calendar.";

        assignmentsBodyEl.innerHTML = "";
        assignmentsEmptyEl.classList.add("d-none");
        assignmentsCountEl.classList.add("d-none");
        assignmentsPlaceholderEl.classList.remove("d-none");

        if (pickerClear) {
            pickerClear.classList.add("d-none");
        }

        if (detailsPanel) {
            detailsPanel.reset();
        }
    }

    /**
     * Load a technician's calendar, from the picker or from anywhere else that
     * wants to land on somebody - see the View Schedule button on the details
     * dialog, which calls this through window.technicianSchedules.
     */
    function selectTechnician(technician) {
        if (!technician) {
            return;
        }

        selectedTechnician = technician;

        if (picker) {
            picker.value = pickerLabel(technician);
        }

        if (pickerClear) {
            pickerClear.classList.remove("d-none");
        }

        closePickerResults();

        // A switched-off account keeps every date it was booked on, so the
        // calendar is still worth reading - it is only NEW work they cannot be
        // given. So the schedule loads either way and just the Add button goes,
        // with the hint saying why rather than leaving a control to vanish
        // unexplained.
        const employable = technician.can_receive_work !== false;

        pickerHint.textContent = employable
            ? "Showing schedule for " + technician.name + "."
            : "Showing " +
              technician.name +
              "'s schedule. Their account is " +
              (technician.status_label || "inactive").toLowerCase() +
              " and cannot take new projects.";

        addOpenBtn.classList.toggle("d-none", !employable);

        // A new technician means any previously shown project is stale.
        if (detailsPanel) {
            detailsPanel.reset();
        }

        loadCalendar();
    }

    if (picker) {
        picker.addEventListener("input", function () {
            // Editing after picking somebody drops the pick: the box must never
            // show one name and load another's calendar.
            if (selectedTechnician) {
                clearSelectedTechnician("Pick a technician to load their calendar.");
            }

            renderPickerResults(picker.value);
        });

        picker.addEventListener("focus", function () {
            if (picker.value && !selectedTechnician) {
                renderPickerResults(picker.value);
            }
        });

        picker.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                closePickerResults();
            }

            // Enter picks the first match rather than leaving the box holding
            // a name nothing was done with.
            if (event.key === "Enter" && pickerResults) {
                event.preventDefault();

                const first = pickerResults.querySelector("[data-picker-id]");

                if (first) {
                    first.click();
                }
            }
        });
    }

    if (pickerResults) {
        pickerResults.addEventListener("click", function (event) {
            const option = event.target.closest("[data-picker-id]");

            if (!option) {
                return;
            }

            selectTechnician(
                directory.find(function (technician) {
                    return (
                        String(technician.technician_id) === option.dataset.pickerId
                    );
                }),
            );
        });
    }

    if (pickerClear) {
        pickerClear.addEventListener("click", function () {
            picker.value = "";
            clearSelectedTechnician("Pick a technician to load their calendar.");
            closePickerResults();
            picker.focus();
        });
    }

    // Clicking away closes the list without choosing anything.
    document.addEventListener("click", function (event) {
        const search = document.querySelector("[data-technician-search]");

        if (search && !search.contains(event.target)) {
            closePickerResults();
        }
    });

    /**
     * Land on a technician from elsewhere on the page.
     *
     * The details dialog's View Schedule button uses this: it switches to the
     * Schedules tab and hands over an id, and the picker fills itself in as
     * though it had been typed. Exposed rather than duplicated so there is one
     * path into a technician's calendar.
     */
    window.technicianSchedules = {
        show: function (technicianId) {
            const technician = directory.find(function (item) {
                return String(item.technician_id) === String(technicianId);
            });

            if (technician) {
                selectTechnician(technician);
            }

            return Boolean(technician);
        },
    };

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

        /**
         * The badge a task is drawn in.
         *
         * The server sends it, already derived from the due date and the
         * completion instant - see TaskStatus. This used to map the raw status
         * column here instead, which had no way of knowing about Overdue or
         * Finished Late and so drew a late task in Pending's grey. The
         * fallback covers a payload from a cached older page.
         */
        function taskBadgeClass(task) {
            return task.status_badge_class || "bg-secondary";
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
                        taskBadgeClass(task) +
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
                " leads this project. Choose a replacement who is free for its whole schedule.";

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

            // Two different reasons the removal controls are withheld, and
            // they must not borrow each other's wording. A former assignment
            // is read-only because there is nothing left to remove - the
            // project itself may be perfectly live, so saying "this project is
            // ongoing, its team cannot be changed" would be false twice over.
            if (data.is_former) {
                setAlert(
                    errorEl,
                    data.removed_on
                        ? "This technician was removed from this project on " +
                              data.removed_on +
                              ". These dates are kept as a record of when they were booked."
                        : "This technician is no longer assigned to this project. These dates are kept as a record of when they were booked.",
                );
            } else if (data.read_only) {
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
                        result.body.error || "Unable to remove technician.",
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
                                "Unable to load assignment.",
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

        const browserEl = modal.querySelector("[data-add-browser]");
        const dismissBtn = modal.querySelector("[data-add-dismiss]");
        const leadConfirmEl = modal.querySelector("[data-add-lead-confirm]");
        const leadIntroEl = modal.querySelector("[data-add-lead-intro]");
        const leadListEl = modal.querySelector("[data-add-lead-list]");
        const leadCancelBtn = modal.querySelector("[data-add-lead-cancel]");
        const leadConfirmBtn = modal.querySelector("[data-add-lead-confirm-save]");
        const leadSpinner = modal.querySelector("[data-add-lead-spinner]");

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
                        "Unavailable - overlaps " + blockedBy,
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
                "</span>" +
                // A project this technician can only join by taking the lead
                // role off somebody says so here, before it is ticked - so the
                // confirmation that follows is never the first anybody hears
                // of it.
                (project.lead_replacement
                    ? '<span class="schedule-pick-reason" data-pick-lead-note>' +
                      "Already led by " +
                      escapeHtml(project.lead_replacement.name) +
                      ". Assigning will replace them." +
                      "</span>"
                    : "") +
                '<span class="schedule-pick-reason' +
                (project.reason ? "" : " d-none") +
                '" data-pick-reason>' +
                escapeHtml(project.reason || "") +
                "</span></span></label>"
            );
        }

        /**
         * The ticked projects whose lead would be replaced, in the order they
         * are listed.
         */
        function pendingLeadReplacements() {
            return selectedIds
                .map(function (id) {
                    return projects.find(function (item) {
                        return item.project_id === id;
                    });
                })
                .filter(function (project) {
                    return project && project.lead_replacement;
                });
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

        /**
         * Back to the project list from the replacement confirmation, leaving
         * every tick exactly as it was. Cancelling changes nothing - the
         * current lead keeps the project.
         */
        function closeLeadConfirm() {
            leadConfirmEl.classList.add("d-none");
            leadConfirmBtn.classList.add("d-none");
            leadCancelBtn.classList.add("d-none");
            leadSpinner.classList.add("d-none");
            leadConfirmBtn.disabled = false;
            browserEl.classList.remove("d-none");
            saveBtn.classList.remove("d-none");
            dismissBtn.classList.remove("d-none");
            refreshStates();
        }

        /**
         * Name every lead about to be replaced and ask, once, before anything
         * is sent. A lead is never replaced automatically.
         */
        function openLeadConfirm(replacing) {
            setAlert(errorEl, "");

            leadIntroEl.textContent =
                replacing.length === 1
                    ? "Replace " +
                      replacing[0].lead_replacement.name +
                      " with " +
                      selectedTechnician.name +
                      " as lead technician?"
                    : "Replace the lead technician on " +
                      replacing.length +
                      " projects with " +
                      selectedTechnician.name +
                      "?";

            leadListEl.innerHTML = replacing
                .map(function (project) {
                    return (
                        '<div class="technician-lead-option is-selected is-static">' +
                        "<span>" +
                        '<span class="technician-lead-name">' +
                        escapeHtml(project.name) +
                        "</span>" +
                        '<span class="technician-lead-skills">' +
                        "Currently led by " +
                        escapeHtml(project.lead_replacement.name) +
                        "</span>" +
                        "</span>" +
                        "</div>"
                    );
                })
                .join("");

            browserEl.classList.add("d-none");
            saveBtn.classList.add("d-none");
            dismissBtn.classList.add("d-none");
            leadConfirmEl.classList.remove("d-none");
            leadConfirmBtn.classList.remove("d-none");
            leadCancelBtn.classList.remove("d-none");
        }

        /**
         * Send the assignment. `replacing` carries, per project, the lead the
         * person was looking at when they confirmed - the server checks the
         * same lead is still there before taking anybody off anything.
         */
        function submit(replacing) {
            const busyBtn = replacing.length ? leadConfirmBtn : saveBtn;
            const busySpinner = replacing.length ? leadSpinner : saveSpinner;

            busyBtn.disabled = true;
            busySpinner.classList.remove("d-none");
            setAlert(errorEl, "");

            request(
                "/super-admin/technicians/" +
                    selectedTechnician.technician_id +
                    "/projects",
                {
                    method: "POST",
                    body: JSON.stringify({
                        project_ids: selectedIds,
                        lead_replacements: replacing.map(function (project) {
                            return {
                                project_id: project.project_id,
                                replacing_technician_id:
                                    project.lead_replacement.technician_id,
                            };
                        }),
                    }),
                },
            ).then(function (result) {
                busySpinner.classList.add("d-none");

                if (!result.ok) {
                    busyBtn.disabled = false;

                    // Back to the list, so a refused replacement is read
                    // beside the projects it is about.
                    if (replacing.length) {
                        closeLeadConfirm();
                    }

                    setAlert(
                        errorEl,
                        result.body.error || "Unable to save assignment.",
                    );

                    return;
                }

                // Back to the list to show what happened. The save button
                // stays disabled - the selection has just been spent, and the
                // dialog closes on its own a moment from now.
                leadConfirmEl.classList.add("d-none");
                leadConfirmBtn.classList.add("d-none");
                leadCancelBtn.classList.add("d-none");
                browserEl.classList.remove("d-none");
                saveBtn.classList.remove("d-none");
                saveBtn.disabled = true;
                dismissBtn.classList.remove("d-none");

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
        }

        saveBtn.addEventListener("click", function () {
            if (!selectedIds.length) {
                return;
            }

            const replacing = pendingLeadReplacements();

            // A sitting lead is never replaced automatically. Anything that
            // would take the role off somebody is asked about first; anything
            // that would not is saved straight away, exactly as before.
            if (replacing.length) {
                openLeadConfirm(replacing);

                return;
            }

            submit([]);
        });

        leadConfirmBtn.addEventListener("click", function () {
            submit(pendingLeadReplacements());
        });

        leadCancelBtn.addEventListener("click", closeLeadConfirm);

        modal.addEventListener("hidden.bs.modal", function () {
            setAlert(errorEl, "");
            setAlert(successEl, "");
            closeLeadConfirm();
        });

        return {
            open: function () {
                projects = [];
                selectedIds = [];
                closeLeadConfirm();
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
                                "Unable to load available projects.",
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
            if (!addProjectModal || !selectedTechnician) {
                return;
            }

            // The button is already hidden for them, so this only catches a
            // click that should not have been possible. The endpoint refuses
            // it too - see TechnicianController::assignableProjects.
            if (selectedTechnician.can_receive_work === false) {
                return;
            }

            addProjectModal.open();
        });
    }
});
