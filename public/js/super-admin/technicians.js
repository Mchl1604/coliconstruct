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
        if (statusLabel === "On Hold") {
            return "bg-secondary";
        }

        return (
            {
                not_yet_scheduled: "bg-info text-dark",
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
        const successEl = modal.querySelector("[data-details-success]");
        const saveBtn = modal.querySelector("[data-details-save]");
        const saveSpinner = modal.querySelector("[data-details-save-spinner]");

        let technicianId = null;

        function selectedSkillIds() {
            return Array.from(
                availableEl.querySelectorAll('input[type="checkbox"]:checked'),
            ).map(function (input) {
                return parseInt(input.value, 10);
            });
        }

        function refreshSaveState() {
            saveBtn.disabled = selectedSkillIds().length === 0;
        }

        function renderSpecialties(technician) {
            const assignedIds = technician.specialties.map(function (item) {
                return item.skill_id;
            });

            if (!technician.specialties.length) {
                specialtiesEl.innerHTML =
                    '<span class="text-muted small">No specialties assigned.</span>';
            } else {
                specialtiesEl.innerHTML = technician.specialties
                    .map(function (specialty) {
                        return (
                            '<span class="technician-specialty-pill">' +
                            escapeHtml(specialty.skill_name) +
                            '<button type="button" class="technician-specialty-remove" ' +
                            'data-remove-specialty="' +
                            specialty.skill_id +
                            '" data-specialty-name="' +
                            escapeHtml(specialty.skill_name) +
                            '" aria-label="Remove ' +
                            escapeHtml(specialty.skill_name) +
                            '"><i class="bi bi-x" aria-hidden="true"></i></button>' +
                            "</span>"
                        );
                    })
                    .join("");
            }

            // Anything already assigned leaves the picker, which is what makes
            // adding a duplicate impossible from the UI.
            let remaining = 0;

            availableEl
                .querySelectorAll("[data-available-option]")
                .forEach(function (option) {
                    const skillId = parseInt(
                        option.dataset.availableOption,
                        10,
                    );
                    const assigned = assignedIds.indexOf(skillId) !== -1;
                    const checkbox = option.querySelector("input");

                    option.classList.toggle("is-hidden", assigned);
                    option.classList.remove("is-checked");
                    checkbox.checked = false;
                    checkbox.disabled = assigned;

                    if (!assigned) {
                        remaining++;
                    }
                });

            allAssignedEl.classList.toggle("d-none", remaining > 0);
            refreshSaveState();
        }

        function render(technician) {
            technicianId = technician.technician_id;
            nameEl.textContent = technician.name;
            metaEl.textContent =
                technician.position +
                (technician.email ? " · " + technician.email : "");
            idEl.textContent = technician.technician_id;
            positionEl.textContent = technician.position;
            emailEl.textContent = technician.email || "Not on file";
            renderSpecialties(technician);
            syncTableRow(technician);
        }

        availableEl.addEventListener("change", function (event) {
            const option = event.target.closest("[data-available-option]");

            if (option) {
                option.classList.toggle("is-checked", event.target.checked);
            }

            setAlert(errorEl, "");
            refreshSaveState();
        });

        specialtiesEl.addEventListener("click", function (event) {
            const button = event.target.closest("[data-remove-specialty]");

            if (!button || button.disabled) {
                return;
            }

            const skillId = button.dataset.removeSpecialty;
            const skillName = button.dataset.specialtyName;

            if (
                !window.confirm(
                    'Remove "' + skillName + '" from this technician?',
                )
            ) {
                return;
            }

            button.disabled = true;
            setAlert(errorEl, "");
            setAlert(successEl, "");

            request(
                "/super-admin/technicians/" +
                    technicianId +
                    "/specialties/" +
                    skillId,
                { method: "DELETE" },
            ).then(function (result) {
                if (!result.ok) {
                    button.disabled = false;
                    setAlert(
                        errorEl,
                        result.body.error || "Could not remove that specialty.",
                    );

                    return;
                }

                render(result.body.technician);
                setAlert(successEl, result.body.message);
            });
        });

        saveBtn.addEventListener("click", function () {
            const skillIds = selectedSkillIds();

            if (!skillIds.length) {
                return;
            }

            saveBtn.disabled = true;
            saveSpinner.classList.remove("d-none");
            setAlert(errorEl, "");
            setAlert(successEl, "");

            request(
                "/super-admin/technicians/" + technicianId + "/specialties",
                {
                    method: "POST",
                    body: JSON.stringify({ skill_ids: skillIds }),
                },
            ).then(function (result) {
                saveSpinner.classList.add("d-none");

                if (!result.ok) {
                    saveBtn.disabled = false;
                    setAlert(
                        errorEl,
                        result.body.error || "Could not add those specialties.",
                    );

                    return;
                }

                render(result.body.technician);
                setAlert(successEl, result.body.message);
            });
        });

        modal.addEventListener("hidden.bs.modal", function () {
            setAlert(errorEl, "");
            setAlert(successEl, "");
        });

        return {
            open: function (id) {
                technicianId = id;
                setAlert(errorEl, "");
                setAlert(successEl, "");
                nameEl.textContent = "Loading…";
                metaEl.textContent = "";
                specialtiesEl.innerHTML = "";

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }

                request("/super-admin/technicians/" + id).then(
                    function (result) {
                        if (!result.ok) {
                            setAlert(
                                errorEl,
                                result.body.error ||
                                    "Could not load this technician.",
                            );

                            return;
                        }

                        render(result.body);
                    },
                );
            },
        };
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
    const calendarCard = document.querySelector(
        "[data-technician-calendar-card]",
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

    const assignmentModalEl = document.querySelector("[data-assignment-modal]");
    const addProjectModalEl = document.querySelector("[data-add-project-modal]");

    let calendar = null;
    let selectedTechnician = null;

    /**
     * The picker is a datalist, so the value arrives as "12 — Jane Doe".
     * Match on the leading id first, then fall back to an exact name.
     */
    function resolveTechnician(rawValue) {
        const value = String(rawValue || "").trim();

        if (!value) {
            return null;
        }

        const idMatch = value.match(/^\s*(\d+)\s*(?:[—-]|$)/);

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
            headerToolbar: { left: "prev,next today", center: "title", right: "" },
            height: "auto",
            dayMaxEvents: true,
            eventDisplay: "block",
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
                if (!assignmentModal || !selectedTechnician) {
                    return;
                }

                assignmentModal.open(info.event.extendedProps.projectId);
            },
        });

        calendar.render();

        return calendar;
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

            calendarCard.classList.remove("d-none");
            calendarNameEl.textContent = result.body.technician.name;
            calendarCountEl.textContent =
                result.body.assignmentCount +
                (result.body.assignmentCount === 1
                    ? " project"
                    : " projects");
            calendarEmptyEl.classList.toggle("d-none", events.length > 0);

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
                calendarCard.classList.add("d-none");
                addOpenBtn.classList.add("d-none");
                pickerHint.textContent = picker.value
                    ? "No technician matches that. Pick one from the list."
                    : "Pick a technician to load their calendar.";

                return;
            }

            selectedTechnician = match;
            picker.value = match.technician_id + " — " + match.name;
            pickerHint.textContent = "Showing schedule for " + match.name + ".";
            addOpenBtn.classList.remove("d-none");
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
    // Project assignment modal (view + remove technician)
    // ---------------------------------------------------------------

    function initAssignmentModal(modal) {
        const nameEl = modal.querySelector("[data-assignment-name]");
        const refEl = modal.querySelector("[data-assignment-ref]");
        const refTextEl = modal.querySelector("[data-assignment-ref-text]");
        const clientEl = modal.querySelector("[data-assignment-client]");
        const startEl = modal.querySelector("[data-assignment-start]");
        const endEl = modal.querySelector("[data-assignment-end]");
        const statusEl = modal.querySelector("[data-assignment-status]");
        const leadEl = modal.querySelector("[data-assignment-lead]");
        const teamEl = modal.querySelector("[data-assignment-team]");

        const leadPanel = modal.querySelector("[data-lead-replacement-panel]");
        const leadIntro = modal.querySelector("[data-lead-replacement-intro]");
        const leadOptions = modal.querySelector(
            "[data-lead-replacement-options]",
        );
        const leadEmpty = modal.querySelector("[data-lead-replacement-empty]");

        const errorEl = modal.querySelector("[data-assignment-error]");
        const successEl = modal.querySelector("[data-assignment-success]");
        const removeBtn = modal.querySelector("[data-remove-technician]");
        const confirmBtn = modal.querySelector("[data-confirm-removal]");
        const confirmSpinner = modal.querySelector(
            "[data-confirm-removal-spinner]",
        );

        let projectId = null;
        let payload = null;
        let selectedLeadId = null;

        function reset() {
            leadPanel.classList.add("d-none");
            leadOptions.innerHTML = "";
            leadEmpty.classList.add("d-none");
            removeBtn.classList.add("d-none");
            confirmBtn.classList.add("d-none");
            confirmBtn.disabled = true;
            selectedLeadId = null;
            setAlert(errorEl, "");
            setAlert(successEl, "");
        }

        function renderLeadOptions() {
            const candidates = payload.replacement_leads || [];

            leadPanel.classList.remove("d-none");
            leadIntro.textContent =
                selectedTechnician.name +
                " is the lead technician on this project.";

            if (!candidates.length) {
                leadOptions.innerHTML = "";
                leadEmpty.classList.remove("d-none");
                confirmBtn.disabled = true;

                return;
            }

            leadEmpty.classList.add("d-none");
            leadOptions.innerHTML = candidates
                .map(function (candidate) {
                    return (
                        '<label class="technician-lead-option" data-lead-option="' +
                        candidate.technician_id +
                        '">' +
                        '<input type="radio" name="replacementLead" class="form-check-input" value="' +
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

            leadOptions
                .querySelectorAll('input[type="radio"]')
                .forEach(function (radio) {
                    radio.addEventListener("change", function () {
                        selectedLeadId = parseInt(radio.value, 10);

                        leadOptions
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

            nameEl.textContent = project.name;
            refEl.href = project.url;
            refTextEl.textContent = project.reference_no || "No reference";
            clientEl.textContent = project.client || "N/A";
            startEl.textContent = project.start_date || "Not set";
            endEl.textContent = project.end_date || "Not set";
            statusEl.innerHTML =
                '<span class="badge ' +
                statusBadgeClass(project.status, project.status_label) +
                '">' +
                escapeHtml(project.status_label) +
                "</span>";
            leadEl.textContent = project.lead_technician || "None";
            teamEl.innerHTML = technicianChips(project.technicians);

            if (data.read_only) {
                setAlert(
                    errorEl,
                    "This project is " +
                        project.status +
                        ", so its team can no longer be changed.",
                );

                return;
            }

            removeBtn.classList.remove("d-none");
        }

        removeBtn.addEventListener("click", function () {
            if (!payload) {
                return;
            }

            // A non-lead can go straight away, provided someone remains.
            if (!payload.is_lead) {
                if (payload.remaining_after_removal < 1) {
                    setAlert(
                        errorEl,
                        "A project must keep at least one technician. Assign someone else first.",
                    );

                    return;
                }

                if (
                    !window.confirm(
                        "Remove " +
                            selectedTechnician.name +
                            " from " +
                            payload.project.name +
                            "?",
                    )
                ) {
                    return;
                }

                submitRemoval(null);

                return;
            }

            // Leads need a replacement chosen first.
            removeBtn.classList.add("d-none");
            confirmBtn.classList.remove("d-none");
            renderLeadOptions();
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

                // Refresh the calendar in place, then close.
                loadCalendar().then(function () {
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

        modal.addEventListener("hidden.bs.modal", reset);

        return {
            open: function (id) {
                projectId = id;
                payload = null;
                reset();
                nameEl.textContent = "Loading…";
                teamEl.innerHTML = "";

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }

                request(
                    "/super-admin/technicians/" +
                        selectedTechnician.technician_id +
                        "/projects/" +
                        id,
                ).then(function (result) {
                    if (!result.ok) {
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

    const assignmentModal = assignmentModalEl
        ? initAssignmentModal(assignmentModalEl)
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

                loadCalendar().then(function () {
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
