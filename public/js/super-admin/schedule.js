document.addEventListener("DOMContentLoaded", function () {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
        window.jQuery("#schedulesTable").DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            info: false,
            language: {
                search: "",
                searchPlaceholder: "Search schedules...",
            },
        });
    }

    const technicianSchedules =
        window.scheduleTechnicianAvailability &&
        typeof window.scheduleTechnicianAvailability === "object"
            ? window.scheduleTechnicianAvailability
            : {};
    const calendarEvents = Array.isArray(window.scheduleCalendarEvents)
        ? window.scheduleCalendarEvents
        : [];
    const technicianNames =
        window.scheduleTechnicianNames &&
        typeof window.scheduleTechnicianNames === "object"
            ? window.scheduleTechnicianNames
            : {};

    function normalizeDateString(value) {
        if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
            return null;
        }

        const year = String(value.getFullYear());
        const month = String(value.getMonth() + 1).padStart(2, "0");
        const day = String(value.getDate()).padStart(2, "0");

        return year + "-" + month + "-" + day;
    }

    // Inclusive list of 'YYYY-MM-DD' strings between two date strings.
    function eachDate(fromValue, toValue) {
        const dates = [];
        const cursor = new Date(fromValue + "T00:00:00");
        const end = new Date(toValue + "T00:00:00");

        if (Number.isNaN(cursor.getTime()) || Number.isNaN(end.getTime())) {
            return dates;
        }

        while (cursor <= end) {
            dates.push(normalizeDateString(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }

        return dates;
    }

    function formatDateList(dates) {
        const currentYear = new Date().getFullYear();
        const allCurrentYear = dates.every(function (date) {
            return Number(date.slice(0, 4)) === currentYear;
        });

        const shown = dates.slice(0, 8);
        const remaining = dates.length - shown.length;

        const labels = shown.map(function (date) {
            return new Date(date + "T00:00:00").toLocaleDateString(
                undefined,
                allCurrentYear
                    ? { month: "long", day: "numeric" }
                    : { month: "long", day: "numeric", year: "numeric" },
            );
        });

        if (remaining > 0) {
            labels.push(
                remaining + " more date" + (remaining === 1 ? "" : "s"),
            );
        }

        if (labels.length === 1) {
            return labels[0];
        }

        if (labels.length === 2) {
            return labels[0] + " and " + labels[1];
        }

        const last = labels.pop();

        return labels.join(", ") + ", and " + last;
    }

    function conflictMessage(conflicts) {
        if (!conflicts.length) {
            return "";
        }

        return (
            conflicts
                .map(function (conflict) {
                    return (
                        "Technician " +
                        conflict.name +
                        " is unavailable on " +
                        formatDateList(conflict.dates) +
                        "."
                    );
                })
                .join(" ") +
            " Please select a continuous date range where all selected technicians are available."
        );
    }

    // Mirrors TechnicianAvailabilityService on the server: every day inside
    // the range must be free for every assigned technician, so a range whose
    // endpoints are free but which spans a busy day is still rejected.
    function conflictsForRange(projectId, technicianIds, startValue, endValue) {
        if (!startValue || !endValue || startValue > endValue) {
            return [];
        }

        const selectedDays = eachDate(startValue, endValue);

        if (!selectedDays.length) {
            return [];
        }

        return technicianIds.reduce(function (conflicts, technicianId) {
            const techRanges = technicianSchedules[technicianId] || [];
            const busyDays = new Set();

            techRanges.forEach(function (range) {
                // The project's own booked dates never block itself; overlap
                // between rows of this form is checked separately.
                if (String(range.project_id) === String(projectId)) {
                    return;
                }

                eachDate(range.start, range.end).forEach(function (day) {
                    busyDays.add(day);
                });
            });

            const hits = selectedDays.filter(function (day) {
                return busyDays.has(day);
            });

            if (hits.length) {
                conflicts.push({
                    name:
                        technicianNames[technicianId] ||
                        "A selected technician",
                    dates: hits,
                });
            }

            return conflicts;
        }, []);
    }

    // Busy ranges for a project's assigned technicians, excluding the
    // project's own existing schedule (a project is always "available"
    // during its own booked dates, handled separately per row so a new
    // range can't overlap ranges already added/saved for this project).
    function busyRangesForProject(projectId, technicianIds) {
        const ranges = [];

        technicianIds.forEach(function (technicianId) {
            const techRanges = technicianSchedules[technicianId] || [];

            techRanges.forEach(function (range) {
                if (String(range.project_id) !== String(projectId)) {
                    ranges.push({ start: range.start, end: range.end });
                }
            });
        });

        return ranges;
    }

    // Ranges currently sitting in the other rows of this same form, so a
    // row's picker also blocks dates already used elsewhere in this project.
    function ownProjectRangesExcluding(container, excludeRow) {
        const ranges = [];

        container.querySelectorAll("[data-range-row]").forEach(function (otherRow) {
            if (otherRow === excludeRow) {
                return;
            }

            const startInput = otherRow.querySelector("[data-range-start]");
            const endInput = otherRow.querySelector("[data-range-end]");
            const start = startInput ? startInput.value : "";
            const end = endInput ? endInput.value : "";

            if (start && end) {
                ranges.push({ start: start, end: end });
            }
        });

        return ranges;
    }

    function destroyPicker(input) {
        if (input && input._flatpickr) {
            input._flatpickr.destroy();
        }
    }

    function initRangeRow(row, busyRanges, container) {
        const startInput = row.querySelector("[data-range-start]");
        const endInput = row.querySelector("[data-range-end]");

        if (!startInput || !endInput || !window.flatpickr) {
            return;
        }

        destroyPicker(startInput);
        destroyPicker(endInput);

        const scheduleIdInput = row.querySelector('input[name*="[schedule_id]"]');
        const isExisting = !!(scheduleIdInput && scheduleIdInput.value);
        const minDate = isExisting ? null : "today";

        const disabledFn = function (date) {
            const dateString = normalizeDateString(date);

            if (!dateString) {
                return false;
            }

            const combined = busyRanges.concat(ownProjectRangesExcluding(container, row));

            return combined.some(function (range) {
                return dateString >= range.start && dateString <= range.end;
            });
        };

        const endPicker = window.flatpickr(endInput, {
            dateFormat: "Y-m-d",
            allowInput: true,
            minDate: minDate,
            disable: [disabledFn],
        });

        const startPicker = window.flatpickr(startInput, {
            dateFormat: "Y-m-d",
            allowInput: true,
            minDate: minDate,
            disable: [disabledFn],
            onChange: function (selectedDates) {
                if (selectedDates[0]) {
                    endPicker.set("minDate", selectedDates[0]);
                } else {
                    endPicker.set("minDate", minDate);
                }
            },
        });

        if (startInput.value) {
            endPicker.set("minDate", startInput.value);
        }
    }

    function updateRemoveButtons(container) {
        const rows = container.querySelectorAll("[data-range-row]");

        rows.forEach(function (row) {
            const removeButton = row.querySelector("[data-remove-range]");

            if (removeButton) {
                removeButton.disabled = rows.length <= 1;
            }
        });
    }

    function initScheduleModal(modal) {
        const projectId = modal.dataset.projectId;
        const technicianIds = (modal.dataset.technicianIds || "")
            .split(",")
            .map(function (id) {
                return id.trim();
            })
            .filter(Boolean);

        const busyRanges = busyRangesForProject(projectId, technicianIds);
        const container = modal.querySelector("[data-ranges-container]");
        const addButton = modal.querySelector("[data-add-range]");
        const template = document.querySelector(
            "template[data-range-template]",
        );
        const errorBox = modal.querySelector("[data-range-error]");
        const form = modal.querySelector("form");

        if (!container) {
            return;
        }

        container.querySelectorAll("[data-range-row]").forEach(function (row) {
            initRangeRow(row, busyRanges, container);
        });

        updateRemoveButtons(container);

        if (addButton && template) {
            addButton.addEventListener("click", function () {
                const nextIndex = parseInt(
                    container.dataset.nextIndex || "0",
                    10,
                );
                const clone =
                    template.content.firstElementChild.cloneNode(true);
                const startInput = clone.querySelector("[data-range-start]");
                const endInput = clone.querySelector("[data-range-end]");

                if (startInput) {
                    startInput.name = "ranges[" + nextIndex + "][start_date]";
                }

                if (endInput) {
                    endInput.name = "ranges[" + nextIndex + "][end_date]";
                }

                container.appendChild(clone);
                container.dataset.nextIndex = String(nextIndex + 1);

                initRangeRow(clone, busyRanges, container);
                updateRemoveButtons(container);

                if (errorBox) {
                    errorBox.classList.add("d-none");
                    errorBox.textContent = "";
                }
            });
        }

        container.addEventListener("click", function (event) {
            const removeButton = event.target.closest("[data-remove-range]");

            if (!removeButton || removeButton.disabled) {
                return;
            }

            const row = removeButton.closest("[data-range-row]");

            if (!row) {
                return;
            }

            destroyPicker(row.querySelector("[data-range-start]"));
            destroyPicker(row.querySelector("[data-range-end]"));
            row.remove();
            updateRemoveButtons(container);
        });

        if (form) {
            form.addEventListener("submit", function (event) {
                const rows = Array.from(
                    container.querySelectorAll("[data-range-row]"),
                );
                const todayString = normalizeDateString(new Date());

                let hasOverlap = false;
                let hasPastDate = false;
                const availabilityConflicts = [];
                const parsed = rows.map(function (row) {
                    const startInput = row.querySelector("[data-range-start]");
                    const endInput = row.querySelector("[data-range-end]");
                    const scheduleIdInput = row.querySelector(
                        'input[name*="[schedule_id]"]',
                    );

                    return {
                        start: startInput ? startInput.value : "",
                        end: endInput ? endInput.value : "",
                        isNew: !scheduleIdInput || !scheduleIdInput.value,
                    };
                });

                parsed.forEach(function (range) {
                    if (!range.start || !range.end) {
                        return;
                    }

                    if (range.isNew && range.start < todayString) {
                        hasPastDate = true;
                    }

                    if (range.end < range.start) {
                        hasOverlap = true;
                        return;
                    }

                    // Every calendar day in the range must be free, not just
                    // the endpoints.
                    conflictsForRange(
                        projectId,
                        technicianIds,
                        range.start,
                        range.end,
                    ).forEach(function (conflict) {
                        availabilityConflicts.push(conflict);
                    });
                });

                for (let i = 0; i < parsed.length; i++) {
                    for (let j = i + 1; j < parsed.length; j++) {
                        const a = parsed[i];
                        const b = parsed[j];

                        if (!a.start || !a.end || !b.start || !b.end) {
                            continue;
                        }

                        if (a.start <= b.end && a.end >= b.start) {
                            hasOverlap = true;
                        }
                    }
                }

                // Collapse repeats of the same technician across rows so the
                // message lists each name once with all their busy dates.
                const mergedConflicts = [];

                availabilityConflicts.forEach(function (conflict) {
                    const existing = mergedConflicts.find(function (item) {
                        return item.name === conflict.name;
                    });

                    if (existing) {
                        conflict.dates.forEach(function (date) {
                            if (existing.dates.indexOf(date) === -1) {
                                existing.dates.push(date);
                            }
                        });
                        existing.dates.sort();

                        return;
                    }

                    mergedConflicts.push({
                        name: conflict.name,
                        dates: conflict.dates.slice(),
                    });
                });

                const isInvalid =
                    hasOverlap || hasPastDate || mergedConflicts.length > 0;

                if (isInvalid && errorBox) {
                    event.preventDefault();

                    if (hasPastDate) {
                        errorBox.textContent =
                            "New date ranges cannot start before today.";
                    } else if (mergedConflicts.length) {
                        errorBox.textContent =
                            conflictMessage(mergedConflicts);
                    } else {
                        errorBox.textContent =
                            "One or more date ranges are invalid or overlap another range in this form.";
                    }

                    errorBox.classList.remove("d-none");
                }
            });
        }
    }

    document
        .querySelectorAll("[data-schedule-edit-modal]")
        .forEach(function (modal) {
            let initialized = false;

            modal.addEventListener("shown.bs.modal", function () {
                if (!initialized) {
                    initScheduleModal(modal);
                    initialized = true;
                }
            });
        });

    // ---------------------------------------------------------------
    // Date-details modal: what's booked on a clicked day, plus the flow
    // for scheduling another project starting from that day.
    // ---------------------------------------------------------------

    const dateModalEl = document.querySelector("[data-schedule-date-modal]");

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

    function statusBadgeClass(status, statusLabel) {
        // The server decides the label, including "On Hold" and "Overdue",
        // both of which win over the underlying status.
        if (statusLabel === "On Hold") {
            return "bg-secondary";
        }

        if (statusLabel === "Overdue") {
            return "badge-overdue";
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
            .map(function (name) {
                return (
                    '<span class="schedule-tech-chip">' +
                    escapeHtml(name) +
                    "</span>"
                );
            })
            .join("");
    }

    function initDateModal(modal) {
        const titleEl = modal.querySelector("[data-date-title]");
        const loadingEl = modal.querySelector("[data-date-loading]");
        const listEl = modal.querySelector("[data-date-projects]");
        const emptyEl = modal.querySelector("[data-date-empty]");
        const countEl = modal.querySelector("[data-date-count]");

        const toggleBtn = modal.querySelector("[data-add-project-toggle]");
        const panel = modal.querySelector("[data-add-project-panel]");
        const startInput = modal.querySelector("[data-add-start]");
        const endInput = modal.querySelector("[data-add-end]");

        const eligibleWrap = modal.querySelector("[data-eligible-wrap]");
        const eligibleLoading = modal.querySelector("[data-eligible-loading]");
        const eligibleList = modal.querySelector("[data-eligible-list]");
        const eligibleEmpty = modal.querySelector("[data-eligible-empty]");
        const eligibleCount = modal.querySelector("[data-eligible-count]");

        const blockedWrap = modal.querySelector("[data-blocked-wrap]");
        const blockedToggle = modal.querySelector("[data-blocked-toggle]");
        const blockedLabel = modal.querySelector("[data-blocked-label]");
        const blockedList = modal.querySelector("[data-blocked-list]");

        const errorBox = modal.querySelector("[data-add-error]");
        const successBox = modal.querySelector("[data-add-success]");
        const saveBtn = modal.querySelector("[data-add-save]");
        const saveSpinner = modal.querySelector("[data-add-save-spinner]");

        let selectedDate = null;
        let endPicker = null;
        let eligibleProjects = [];
        let selectedProjectIds = [];
        // Bumped per fetch kind so a slow earlier response can't overwrite
        // the results of a newer one.
        let eligibleToken = 0;
        let dateToken = 0;

        function showError(message) {
            errorBox.textContent = message || "";
            errorBox.classList.toggle("d-none", !message);
        }

        function showSuccess(message) {
            successBox.textContent = message || "";
            successBox.classList.toggle("d-none", !message);
        }

        function resetAddPanel() {
            panel.classList.add("d-none");
            toggleBtn.classList.remove("d-none");
            eligibleWrap.classList.add("d-none");
            eligibleList.innerHTML = "";
            blockedList.innerHTML = "";
            blockedWrap.classList.add("d-none");
            blockedList.classList.add("d-none");
            blockedToggle.classList.remove("is-open");
            blockedLabel.textContent = "Show unavailable projects";
            eligibleCount.classList.add("d-none");
            eligibleEmpty.classList.add("d-none");
            eligibleProjects = [];
            selectedProjectIds = [];
            saveBtn.classList.add("d-none");
            saveBtn.disabled = true;
            showError("");
            showSuccess("");

            if (endPicker) {
                endPicker.destroy();
                endPicker = null;
            }

            endInput.value = "";
        }

        // Which technicians are spoken for by the projects already ticked.
        function claimedTechnicianIds() {
            const claimed = new Map();

            selectedProjectIds.forEach(function (projectId) {
                const project = eligibleProjects.find(function (item) {
                    return item.project_id === projectId;
                });

                if (!project) {
                    return;
                }

                (project.technician_ids || []).forEach(function (technicianId) {
                    if (!claimed.has(technicianId)) {
                        claimed.set(technicianId, project.name);
                    }
                });
            });

            return claimed;
        }

        // Re-evaluate every row against the current selection. Runs on every
        // tick/untick so sharing a technician disables the other project
        // immediately, with no refresh and no server round trip.
        function refreshEligibleStates() {
            const claimed = claimedTechnicianIds();

            eligibleProjects.forEach(function (project) {
                const row = eligibleList.querySelector(
                    '[data-pick="' + project.project_id + '"]',
                );

                if (!row) {
                    return;
                }

                const checkbox = row.querySelector('input[type="checkbox"]');
                const isSelected =
                    selectedProjectIds.indexOf(project.project_id) !== -1;
                const reasonEl = row.querySelector("[data-pick-reason]");

                let blockingTechnician = null;
                let blockingProject = null;

                if (!isSelected) {
                    (project.technician_ids || []).some(function (
                        technicianId,
                    ) {
                        if (claimed.has(technicianId)) {
                            blockingTechnician =
                                technicianNames[technicianId] ||
                                "A technician";
                            blockingProject = claimed.get(technicianId);

                            return true;
                        }

                        return false;
                    });
                }

                const disabled = Boolean(blockingTechnician);

                checkbox.disabled = disabled;
                row.classList.toggle("is-disabled", disabled);
                row.classList.toggle("is-selected", isSelected);

                if (disabled) {
                    const message =
                        "Unavailable because Technician " +
                        blockingTechnician +
                        " is already assigned to " +
                        blockingProject +
                        ".";
                    row.setAttribute("title", message);
                    reasonEl.textContent = message;
                    reasonEl.classList.remove("d-none");
                } else {
                    row.removeAttribute("title");
                    reasonEl.textContent = "";
                    reasonEl.classList.add("d-none");
                }
            });

            saveBtn.disabled = selectedProjectIds.length === 0;
            saveBtn.classList.toggle("d-none", eligibleProjects.length === 0);
        }

        function pickMarkup(project, selectable) {
            const meta = [];

            if (project.reference_no) {
                meta.push(escapeHtml(project.reference_no));
            }

            if (project.client) {
                meta.push(escapeHtml(project.client));
            }

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
                "</span>" +
                '<span class="schedule-pick-meta">' +
                meta.join(" &middot; ") +
                "</span>" +
                '<span class="schedule-pick-techs">' +
                technicianChips(project.technicians) +
                "</span>" +
                '<span class="schedule-pick-reason' +
                (project.reason ? "" : " d-none") +
                '" data-pick-reason>' +
                escapeHtml(project.reason || "") +
                "</span>" +
                "</span>" +
                "</label>"
            );
        }

        function renderEligible(payload) {
            eligibleProjects = payload.projects || [];
            selectedProjectIds = [];

            const blocked = payload.blocked || [];

            eligibleList.innerHTML = eligibleProjects
                .map(function (project) {
                    return pickMarkup(project, true);
                })
                .join("");

            eligibleEmpty.classList.toggle("d-none", eligibleProjects.length > 0);
            eligibleCount.textContent = eligibleProjects.length + " available";
            eligibleCount.classList.toggle("d-none", eligibleProjects.length === 0);

            blockedList.innerHTML = blocked
                .map(function (project) {
                    return pickMarkup(project, false);
                })
                .join("");
            blockedWrap.classList.toggle("d-none", blocked.length === 0);
            blockedLabel.textContent =
                "Show unavailable projects (" + blocked.length + ")";

            eligibleList
                .querySelectorAll('input[type="checkbox"]')
                .forEach(function (checkbox) {
                    checkbox.addEventListener("change", function () {
                        const projectId = parseInt(checkbox.value, 10);

                        if (checkbox.checked) {
                            if (selectedProjectIds.indexOf(projectId) === -1) {
                                selectedProjectIds.push(projectId);
                            }
                        } else {
                            selectedProjectIds = selectedProjectIds.filter(
                                function (id) {
                                    return id !== projectId;
                                },
                            );
                        }

                        showError("");
                        refreshEligibleStates();
                    });
                });

            refreshEligibleStates();
        }

        function loadEligible() {
            if (!selectedDate || !endInput.value) {
                return;
            }

            const token = ++eligibleToken;

            eligibleWrap.classList.remove("d-none");
            eligibleLoading.classList.remove("d-none");
            eligibleList.innerHTML = "";
            eligibleEmpty.classList.add("d-none");
            eligibleCount.classList.add("d-none");
            blockedWrap.classList.add("d-none");
            saveBtn.classList.add("d-none");
            saveBtn.disabled = true;
            showError("");
            showSuccess("");

            const url =
                "/super-admin/schedules/assignable?start_date=" +
                encodeURIComponent(selectedDate) +
                "&end_date=" +
                encodeURIComponent(endInput.value);

            fetch(url, { headers: { Accept: "application/json" } })
                .then(function (response) {
                    return response.json().then(function (body) {
                        return { ok: response.ok, body: body };
                    });
                })
                .then(function (result) {
                    if (token !== eligibleToken) {
                        return;
                    }

                    eligibleLoading.classList.add("d-none");

                    if (!result.ok) {
                        showError(
                            result.body.error ||
                                "Could not load available projects.",
                        );

                        return;
                    }

                    renderEligible(result.body);
                })
                .catch(function () {
                    if (token !== eligibleToken) {
                        return;
                    }

                    eligibleLoading.classList.add("d-none");
                    showError("Could not load available projects.");
                });
        }

        function renderDateProjects(payload) {
            const projects = payload.projects || [];

            listEl.innerHTML = projects
                .map(function (project) {
                    const ranges = (project.ranges || [])
                        .map(function (range) {
                            return (
                                '<span class="schedule-date-card-range">' +
                                '<i class="bi bi-calendar3"></i>' +
                                escapeHtml(range.label) +
                                "</span>"
                            );
                        })
                        .join("");

                    return (
                        '<div class="schedule-date-card">' +
                        '<div class="schedule-date-card-top">' +
                        "<div>" +
                        '<div class="schedule-date-card-name">' +
                        escapeHtml(project.name) +
                        "</div>" +
                        '<div class="schedule-date-card-meta">' +
                        '<a href="' +
                        escapeHtml(project.url) +
                        '" class="schedule-modal-ref">' +
                        '<i class="bi bi-box-arrow-up-right"></i>' +
                        escapeHtml(project.reference_no || "No reference") +
                        "</a>" +
                        (project.client
                            ? " &middot; " + escapeHtml(project.client)
                            : "") +
                        "</div>" +
                        "</div>" +
                        '<span class="badge ' +
                        statusBadgeClass(project.status, project.status_label) +
                        '">' +
                        escapeHtml(project.status_label) +
                        "</span>" +
                        "</div>" +
                        ranges +
                        '<div class="schedule-pick-techs">' +
                        technicianChips(project.technicians) +
                        "</div>" +
                        "</div>"
                    );
                })
                .join("");

            emptyEl.classList.toggle("d-none", projects.length > 0);
            countEl.textContent =
                projects.length + (projects.length === 1 ? " project" : " projects");
            countEl.classList.toggle("d-none", projects.length === 0);
        }

        function loadDateProjects() {
            const token = ++dateToken;

            loadingEl.classList.remove("d-none");
            listEl.innerHTML = "";
            emptyEl.classList.add("d-none");
            countEl.classList.add("d-none");

            fetch(
                "/super-admin/schedules/date/" + encodeURIComponent(selectedDate),
                { headers: { Accept: "application/json" } },
            )
                .then(function (response) {
                    return response.json();
                })
                .then(function (payload) {
                    if (token !== dateToken) {
                        return;
                    }

                    loadingEl.classList.add("d-none");
                    renderDateProjects(payload);
                })
                .catch(function () {
                    if (token !== dateToken) {
                        return;
                    }

                    loadingEl.classList.add("d-none");
                    emptyEl.textContent = "Could not load projects for this date.";
                    emptyEl.classList.remove("d-none");
                });
        }

        toggleBtn.addEventListener("click", function () {
            toggleBtn.classList.add("d-none");
            panel.classList.remove("d-none");
            startInput.value = selectedDate || "";

            if (window.flatpickr) {
                endPicker = window.flatpickr(endInput, {
                    dateFormat: "Y-m-d",
                    allowInput: false,
                    minDate: selectedDate,
                    onChange: function () {
                        loadEligible();
                    },
                });
            } else {
                endInput.type = "date";
                endInput.min = selectedDate;
                endInput.addEventListener("change", loadEligible);
            }

            endInput.focus();
        });

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
            if (!selectedProjectIds.length || !selectedDate || !endInput.value) {
                return;
            }

            saveBtn.disabled = true;
            saveSpinner.classList.remove("d-none");
            showError("");

            fetch("/super-admin/schedules/assign", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    start_date: selectedDate,
                    end_date: endInput.value,
                    project_ids: selectedProjectIds,
                }),
            })
                .then(function (response) {
                    return response.json().then(function (body) {
                        return { ok: response.ok, body: body };
                    });
                })
                .then(function (result) {
                    saveSpinner.classList.add("d-none");

                    if (!result.ok) {
                        saveBtn.disabled = false;
                        showError(
                            result.body.error ||
                                (result.body.errors
                                    ? Object.values(result.body.errors)
                                          .flat()
                                          .join(" ")
                                    : "Could not save the schedule."),
                        );

                        return;
                    }

                    showSuccess(
                        (result.body.message || "Schedule saved.") +
                            " Refreshing…",
                    );

                    // Reload so the calendar, table and availability data all
                    // reflect the new booking.
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 900);
                })
                .catch(function () {
                    saveSpinner.classList.add("d-none");
                    saveBtn.disabled = false;
                    showError("Could not save the schedule.");
                });
        });

        modal.addEventListener("hidden.bs.modal", function () {
            resetAddPanel();
        });

        return {
            open: function (dateString, label) {
                selectedDate = dateString;
                titleEl.textContent = label || dateString;
                resetAddPanel();
                loadDateProjects();

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            },
        };
    }

    const dateModal = dateModalEl ? initDateModal(dateModalEl) : null;

    const calendarEl = document.getElementById("schedulesCalendar");

    if (calendarEl && window.FullCalendar) {
        const calendar = new window.FullCalendar.Calendar(calendarEl, {
            initialView: "dayGridMonth",
            headerToolbar: window.calendarHeader.toolbar(),
            height: "auto",
            dayMaxEvents: true,
            events: calendarEvents,
            eventDisplay: "block",
            // Every calendar day opens the date-details modal.
            dateClick: function (info) {
                if (!dateModal) {
                    return;
                }

                dateModal.open(
                    info.dateStr,
                    info.date.toLocaleDateString(undefined, {
                        weekday: "long",
                        year: "numeric",
                        month: "long",
                        day: "numeric",
                    }),
                );
            },
            eventDidMount: function (info) {
                const projectName = info.event.extendedProps.projectName || "";
                const statusRaw = info.event.extendedProps.status || "";
                // Prefer the server's label so overdue reads as "Overdue"
                // rather than the raw "ongoing".
                const status =
                    info.event.extendedProps.statusLabel ||
                    (statusRaw
                        ? statusRaw.charAt(0).toUpperCase() + statusRaw.slice(1)
                        : "");
                const readOnly = Boolean(info.event.extendedProps.readOnly);

                const tooltipParts = [info.event.title];

                if (projectName) {
                    tooltipParts.push(projectName);
                }

                if (status) {
                    tooltipParts.push(status);
                }

                if (readOnly) {
                    // No edit modal exists for these, so say why up front.
                    tooltipParts.push("View only");
                    info.el.classList.add("fc-event-readonly");
                }

                info.el.setAttribute("title", tooltipParts.join(" · "));
            },
            eventClick: function (info) {
                if (info.event.extendedProps.readOnly) {
                    return;
                }

                const projectId = info.event.extendedProps.projectId;
                const modalEl = document.getElementById(
                    "scheduleEditModal" + projectId,
                );

                if (modalEl && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
            },
        });

        calendar.render();
        window.calendarHeader.attach(calendar, calendarEl);
    }
});