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

    function normalizeDateString(value) {
        if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
            return null;
        }

        const year = String(value.getFullYear());
        const month = String(value.getMonth() + 1).padStart(2, "0");
        const day = String(value.getDate()).padStart(2, "0");

        return year + "-" + month + "-" + day;
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

                    const overlapsBusy = busyRanges.some(function (busy) {
                        return (
                            range.start <= busy.end && range.end >= busy.start
                        );
                    });

                    if (overlapsBusy || range.end < range.start) {
                        hasOverlap = true;
                    }
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

                if ((hasOverlap || hasPastDate) && errorBox) {
                    event.preventDefault();
                    errorBox.textContent = hasPastDate
                        ? "New date ranges cannot start before today."
                        : "One or more date ranges are invalid or conflict with a technician's existing schedule on another project.";
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

    function initMonthYearJump(calendar, titleEl, anchorEl) {
        const monthNames = [
            "January",
            "February",
            "March",
            "April",
            "May",
            "June",
            "July",
            "August",
            "September",
            "October",
            "November",
            "December",
        ];

        const panel = document.createElement("div");
        panel.className = "schedule-calendar-jump";
        panel.innerHTML =
            "<select data-jump-month></select><select data-jump-year></select>";
        anchorEl.appendChild(panel);

        const monthSelect = panel.querySelector("[data-jump-month]");
        const yearSelect = panel.querySelector("[data-jump-year]");

        monthNames.forEach(function (name, index) {
            const option = document.createElement("option");
            option.value = String(index);
            option.textContent = name;
            monthSelect.appendChild(option);
        });

        function populateYears(centerYear) {
            yearSelect.innerHTML = "";

            for (let year = centerYear - 6; year <= centerYear + 6; year++) {
                const option = document.createElement("option");
                option.value = String(year);
                option.textContent = String(year);
                yearSelect.appendChild(option);
            }
        }

        function syncToDate(date) {
            monthSelect.value = String(date.getMonth());

            if (
                !yearSelect.querySelector(
                    'option[value="' + date.getFullYear() + '"]',
                )
            ) {
                populateYears(date.getFullYear());
            }

            yearSelect.value = String(date.getFullYear());
        }

        function closePanel() {
            panel.classList.remove("is-open");
        }

        function openPanel() {
            syncToDate(calendar.getDate());
            panel.classList.add("is-open");
        }

        titleEl.addEventListener("click", function (event) {
            event.stopPropagation();

            if (panel.classList.contains("is-open")) {
                closePanel();
            } else {
                openPanel();
            }
        });

        panel.addEventListener("click", function (event) {
            event.stopPropagation();
        });

        document.addEventListener("click", closePanel);

        function jumpToSelection() {
            const year = parseInt(yearSelect.value, 10);
            const month = parseInt(monthSelect.value, 10);

            calendar.gotoDate(new Date(year, month, 1));
            closePanel();
        }

        monthSelect.addEventListener("change", jumpToSelection);
        yearSelect.addEventListener("change", jumpToSelection);

        calendar.on("datesSet", function () {
            syncToDate(calendar.getDate());
        });

        populateYears(calendar.getDate().getFullYear());
        syncToDate(calendar.getDate());
    }

    const calendarEl = document.getElementById("schedulesCalendar");

    if (calendarEl && window.FullCalendar) {
        const calendar = new window.FullCalendar.Calendar(calendarEl, {
            initialView: "dayGridMonth",
            headerToolbar: {
                left: "prev",
                center: "title",
                right: "next",
            },
            height: "auto",
            dayMaxEvents: true,
            events: calendarEvents,
            eventDisplay: "block",
            eventDidMount: function (info) {
                const projectName = info.event.extendedProps.projectName || "";
                const statusRaw = info.event.extendedProps.status || "";
                const status = statusRaw
                    ? statusRaw.charAt(0).toUpperCase() + statusRaw.slice(1)
                    : "";
                const holdSuffix = info.event.extendedProps.onHold
                    ? " (On Hold)"
                    : "";

                const tooltipParts = [info.event.title];

                if (projectName) {
                    tooltipParts.push(projectName);
                }

                if (status) {
                    tooltipParts.push(status + holdSuffix);
                }

                info.el.setAttribute("title", tooltipParts.join(" · "));
            },
            eventClick: function (info) {
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

        const titleEl = calendarEl.querySelector(".fc-toolbar-title");
        const titleChunk = titleEl
            ? titleEl.closest(".fc-toolbar-chunk")
            : null;

        if (titleEl && titleChunk) {
            titleEl.classList.add("schedule-calendar-title");
            titleChunk.classList.add("schedule-calendar-jump-anchor");
            initMonthYearJump(calendar, titleEl, titleChunk);
        }
    }
});