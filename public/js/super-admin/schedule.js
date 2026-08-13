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

    const MODE_DATE_BASED = "date_based";
    const MODE_PARTIAL_DAY = "partial_day";
    const MINUTES_PER_DAY = 1440;
    // The working day, mirroring Schedule::WORKING_HOUR_START / _END.
    const WORKING_HOUR_START = 8;
    const WORKING_HOUR_END = 17;

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

    function minutesFromTime(value) {
        if (typeof value !== "string" || !/^\d{2}:\d{2}$/.test(value)) {
            return null;
        }

        return Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
    }

    function formatMinutes(minutes) {
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        const suffix = hours >= 12 ? "PM" : "AM";
        const display = hours % 12 === 0 ? 12 : hours % 12;

        return display + ":" + String(rest).padStart(2, "0") + " " + suffix;
    }

    // Half-open, matching TechnicianAvailabilityService: a booking ending at
    // 10:00 AM and one starting at 10:00 AM do not clash.
    function intervalsOverlap(fromA, toA, fromB, toB) {
        return fromA < toB && fromB < toA;
    }

    function describeInterval(interval) {
        if (interval.from <= 0 && interval.to >= MINUTES_PER_DAY) {
            return "the whole day";
        }

        return formatMinutes(interval.from) + " - " + formatMinutes(interval.to);
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

        // Hours-only clashes name the hours; anything touching whole days
        // keeps the wording the page has always used.
        if (
            conflicts.every(function (conflict) {
                return conflict.labels && conflict.labels.length;
            })
        ) {
            return (
                conflicts
                    .map(function (conflict) {
                        return (
                            "Technician " +
                            conflict.name +
                            " is already booked " +
                            conflict.labels.join(" and ") +
                            " on " +
                            formatDateList([conflict.date]) +
                            "."
                        );
                    })
                    .join(" ") +
                " Please choose a time when every selected technician is free."
            );
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

    /**
     * The minutes a technician is already spoken for on one date, ignoring
     * whatever the given project itself booked.
     *
     * A whole-day booking takes the entire day; a partial day takes only its
     * hours, leaving the rest of that date free for other work.
     */
    function busyIntervalsOn(technicianId, date, excludeProjectId) {
        const ranges = technicianSchedules[technicianId] || [];
        const intervals = [];

        ranges.forEach(function (range) {
            if (String(range.project_id) === String(excludeProjectId)) {
                return;
            }

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
        return !intervals.some(function (interval) {
            return intervalsOverlap(from, to, interval.from, interval.to);
        });
    }

    function hasFreeHourOn(technicianId, date, excludeProjectId) {
        const intervals = busyIntervalsOn(technicianId, date, excludeProjectId);

        for (let hour = WORKING_HOUR_START; hour < WORKING_HOUR_END; hour++) {
            if (isFreeBetween(intervals, hour * 60, (hour + 1) * 60)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everyone who cannot work the given schedule.
     *
     * `entry` is {mode, start, end} for whole days, or {mode, date, from, to}
     * in minutes for an hours-only booking.
     */
    function conflictsForEntry(entry, technicianIds, excludeProjectId) {
        if (entry.mode === MODE_PARTIAL_DAY) {
            if (!entry.date || entry.from === null || entry.to === null || entry.from >= entry.to) {
                return [];
            }

            return technicianIds.reduce(function (conflicts, technicianId) {
                const clashes = busyIntervalsOn(
                    technicianId,
                    entry.date,
                    excludeProjectId,
                ).filter(function (interval) {
                    return intervalsOverlap(
                        entry.from,
                        entry.to,
                        interval.from,
                        interval.to,
                    );
                });

                if (clashes.length) {
                    conflicts.push({
                        name:
                            technicianNames[technicianId] ||
                            "A selected technician",
                        date: entry.date,
                        labels: clashes.some(function (interval) {
                            return (
                                interval.from <= 0 &&
                                interval.to >= MINUTES_PER_DAY
                            );
                        })
                            ? ["the whole day"]
                            : clashes.map(describeInterval),
                    });
                }

                return conflicts;
            }, []);
        }

        if (!entry.start || !entry.end || entry.start > entry.end) {
            return [];
        }

        const selectedDays = eachDate(entry.start, entry.end);

        return technicianIds.reduce(function (conflicts, technicianId) {
            const hits = selectedDays.filter(function (day) {
                return (
                    busyIntervalsOn(technicianId, day, excludeProjectId).length >
                    0
                );
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

    // Two entries claim the same time. Whole days are compared as whole days;
    // hours on one date are compared half-open.
    function entriesOverlap(a, b) {
        if (!entryIsComplete(a) || !entryIsComplete(b)) {
            return false;
        }

        const spanA = entrySpan(a);
        const spanB = entrySpan(b);

        return spanA.start < spanB.end && spanB.start < spanA.end;
    }

    // A comparable stretch of time, in minutes since an epoch date.
    function entrySpan(entry) {
        const dayNumber = function (date) {
            return Math.floor(new Date(date + "T00:00:00").getTime() / 60000);
        };

        if (entry.mode === MODE_PARTIAL_DAY) {
            return {
                start: dayNumber(entry.date) + entry.from,
                end: dayNumber(entry.date) + entry.to,
            };
        }

        return {
            start: dayNumber(entry.start),
            end: dayNumber(entry.end) + MINUTES_PER_DAY,
        };
    }

    function entryIsComplete(entry) {
        if (entry.mode === MODE_PARTIAL_DAY) {
            return Boolean(
                entry.date &&
                    entry.from !== null &&
                    entry.to !== null &&
                    entry.from < entry.to,
            );
        }

        return Boolean(entry.start && entry.end && entry.start <= entry.end);
    }

    function destroyPicker(input) {
        if (input && input._flatpickr) {
            input._flatpickr.destroy();
        }
    }

    // -----------------------------------------------------------------
    // Per-project schedule edit modal
    // -----------------------------------------------------------------

    function rowMode(row) {
        const modeSelect = row.querySelector("[data-range-mode]");

        return modeSelect && modeSelect.value === MODE_PARTIAL_DAY
            ? MODE_PARTIAL_DAY
            : MODE_DATE_BASED;
    }

    function readRow(row) {
        const value = function (selector) {
            const field = row.querySelector(selector);

            return field ? field.value : "";
        };

        const scheduleIdInput = row.querySelector('input[name*="[schedule_id]"]');

        if (rowMode(row) === MODE_PARTIAL_DAY) {
            return {
                mode: MODE_PARTIAL_DAY,
                date: value("[data-range-project-date]"),
                from: minutesFromTime(value("[data-range-start-time]")),
                to: minutesFromTime(value("[data-range-end-time]")),
                isNew: !scheduleIdInput || !scheduleIdInput.value,
            };
        }

        return {
            mode: MODE_DATE_BASED,
            start: value("[data-range-start]"),
            end: value("[data-range-end]"),
            isNew: !scheduleIdInput || !scheduleIdInput.value,
        };
    }

    // The group not in use is hidden AND disabled, so the browser neither
    // validates it nor submits it.
    function applyRowMode(row) {
        const partialDay = rowMode(row) === MODE_PARTIAL_DAY;

        row.querySelectorAll("[data-range-date-based]").forEach(function (wrap) {
            wrap.hidden = partialDay;
        });

        row.querySelectorAll("[data-range-partial-day]").forEach(function (wrap) {
            wrap.hidden = !partialDay;
        });

        [
            ["[data-range-start]", !partialDay],
            ["[data-range-end]", !partialDay],
            ["[data-range-project-date]", partialDay],
            ["[data-range-start-time]", partialDay],
            ["[data-range-end-time]", partialDay],
        ].forEach(function (pair) {
            const field = row.querySelector(pair[0]);

            if (!field) {
                return;
            }

            field.disabled = !pair[1];
            field.required = pair[1];
        });
    }

    /**
     * Grey out the hours this project's team cannot actually work on the
     * chosen date.
     */
    function refreshRowTimeOptions(row, technicianIds, projectId) {
        const startTime = row.querySelector("[data-range-start-time]");
        const endTime = row.querySelector("[data-range-end-time]");
        const dateInput = row.querySelector("[data-range-project-date]");

        if (!startTime || !endTime) {
            return;
        }

        const date = dateInput ? dateInput.value : "";

        const freeBetween = function (from, to) {
            return technicianIds.every(function (technicianId) {
                return isFreeBetween(
                    busyIntervalsOn(technicianId, date, projectId),
                    from,
                    to,
                );
            });
        };

        Array.from(startTime.options).forEach(function (option) {
            if (!option.value) {
                return;
            }

            const from = minutesFromTime(option.value);
            // 5 PM can only ever end a booking.
            const bookable = from !== null && from < WORKING_HOUR_END * 60;

            option.disabled = !date
                ? false
                : !(bookable && freeBetween(from, from + 60));
        });

        const startMinutes = minutesFromTime(startTime.value);

        Array.from(endTime.options).forEach(function (option) {
            if (!option.value) {
                return;
            }

            const to = minutesFromTime(option.value);

            if (to === null || startMinutes === null) {
                option.disabled = false;

                return;
            }

            option.disabled =
                to <= startMinutes || (date ? !freeBetween(startMinutes, to) : false);
        });

        [startTime, endTime].forEach(function (select) {
            const selected = select.options[select.selectedIndex];

            if (selected && selected.disabled) {
                select.value = "";
            }
        });
    }

    function initRangeRow(row, technicianIds, projectId, container) {
        const startInput = row.querySelector("[data-range-start]");
        const endInput = row.querySelector("[data-range-end]");
        const projectDateInput = row.querySelector("[data-range-project-date]");
        const modeSelect = row.querySelector("[data-range-mode]");

        if (!window.flatpickr) {
            return;
        }

        [startInput, endInput, projectDateInput].forEach(destroyPicker);

        const scheduleIdInput = row.querySelector('input[name*="[schedule_id]"]');
        const isExisting = !!(scheduleIdInput && scheduleIdInput.value);
        const minDate = isExisting ? null : "today";

        // Time already claimed by the other rows of this same form, so a row's
        // picker also blocks what is spoken for elsewhere in this project.
        const otherRowEntries = function () {
            return Array.from(container.querySelectorAll("[data-range-row]"))
                .filter(function (otherRow) {
                    return otherRow !== row;
                })
                .map(readRow)
                .filter(entryIsComplete);
        };

        const wholeDayBlocked = function (date) {
            const dateString = normalizeDateString(date);

            if (!dateString) {
                return false;
            }

            const busyElsewhere = technicianIds.some(function (technicianId) {
                return (
                    busyIntervalsOn(technicianId, dateString, projectId).length >
                    0
                );
            });

            if (busyElsewhere) {
                return true;
            }

            return otherRowEntries().some(function (entry) {
                return entriesOverlap(entry, {
                    mode: MODE_DATE_BASED,
                    start: dateString,
                    end: dateString,
                });
            });
        };

        // A partial day only needs one hour of the date still open.
        const partialDayBlocked = function (date) {
            const dateString = normalizeDateString(date);

            if (!dateString) {
                return false;
            }

            return technicianIds.some(function (technicianId) {
                return !hasFreeHourOn(technicianId, dateString, projectId);
            });
        };

        if (startInput && endInput) {
            const endPicker = window.flatpickr(endInput, {
                dateFormat: "Y-m-d",
                allowInput: true,
                minDate: minDate,
                disable: [wholeDayBlocked],
            });

            const startPicker = window.flatpickr(startInput, {
                dateFormat: "Y-m-d",
                allowInput: true,
                minDate: minDate,
                disable: [wholeDayBlocked],
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

            // Referenced so the linter sees the picker is used; flatpickr
            // keeps its own handle on the element either way.
            void startPicker;
        }

        if (projectDateInput) {
            window.flatpickr(projectDateInput, {
                dateFormat: "Y-m-d",
                allowInput: true,
                minDate: minDate,
                disable: [partialDayBlocked],
                onChange: function () {
                    // The hours on offer belong to the date just chosen.
                    const startTime = row.querySelector("[data-range-start-time]");
                    const endTime = row.querySelector("[data-range-end-time]");

                    if (startTime) {
                        startTime.value = "";
                    }

                    if (endTime) {
                        endTime.value = "";
                    }

                    refreshRowTimeOptions(row, technicianIds, projectId);
                },
            });
        }

        row.querySelectorAll(
            "[data-range-start-time], [data-range-end-time]",
        ).forEach(function (select) {
            select.addEventListener("change", function () {
                refreshRowTimeOptions(row, technicianIds, projectId);
            });
        });

        if (modeSelect) {
            modeSelect.addEventListener("change", function () {
                applyRowMode(row);
                refreshRowTimeOptions(row, technicianIds, projectId);
            });
        }

        applyRowMode(row);
        refreshRowTimeOptions(row, technicianIds, projectId);
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
        const partialDayAllowed = modal.dataset.partialDayAllowed === "1";
        const technicianIds = (modal.dataset.technicianIds || "")
            .split(",")
            .map(function (id) {
                return id.trim();
            })
            .filter(Boolean);

        const container = modal.querySelector("[data-ranges-container]");
        const addButton = modal.querySelector("[data-add-range]");
        const template = document.querySelector(
            partialDayAllowed
                ? "template[data-range-template-residential]"
                : "template[data-range-template]",
        );
        const errorBox = modal.querySelector("[data-range-error]");
        const form = modal.querySelector("form");

        if (!container) {
            return;
        }

        container.querySelectorAll("[data-range-row]").forEach(function (row) {
            initRangeRow(row, technicianIds, projectId, container);
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

                // The template carries no names; each field is claimed for
                // this row as it is added.
                [
                    ["[data-range-mode]", "scheduling_mode"],
                    ["[data-range-start]", "start_date"],
                    ["[data-range-end]", "end_date"],
                    ["[data-range-project-date]", "project_date"],
                    ["[data-range-start-time]", "start_time"],
                    ["[data-range-end-time]", "end_time"],
                ].forEach(function (pair) {
                    const field = clone.querySelector(pair[0]);

                    if (field) {
                        field.name = "ranges[" + nextIndex + "][" + pair[1] + "]";
                    }
                });

                container.appendChild(clone);
                container.dataset.nextIndex = String(nextIndex + 1);

                initRangeRow(clone, technicianIds, projectId, container);
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

            [
                "[data-range-start]",
                "[data-range-end]",
                "[data-range-project-date]",
            ].forEach(function (selector) {
                destroyPicker(row.querySelector(selector));
            });

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
                let hasBadTimes = false;
                const availabilityConflicts = [];
                const entries = rows.map(readRow);

                entries.forEach(function (entry) {
                    if (entry.mode === MODE_PARTIAL_DAY) {
                        if (!entry.date || entry.from === null || entry.to === null) {
                            return;
                        }

                        if (entry.from >= entry.to) {
                            hasBadTimes = true;

                            return;
                        }

                        if (entry.isNew && entry.date < todayString) {
                            hasPastDate = true;
                        }
                    } else {
                        if (!entry.start || !entry.end) {
                            return;
                        }

                        if (entry.isNew && entry.start < todayString) {
                            hasPastDate = true;
                        }

                        if (entry.end < entry.start) {
                            hasOverlap = true;

                            return;
                        }
                    }

                    conflictsForEntry(entry, technicianIds, projectId).forEach(
                        function (conflict) {
                            availabilityConflicts.push(conflict);
                        },
                    );
                });

                for (let i = 0; i < entries.length; i++) {
                    for (let j = i + 1; j < entries.length; j++) {
                        if (entriesOverlap(entries[i], entries[j])) {
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

                    if (!existing) {
                        mergedConflicts.push({
                            name: conflict.name,
                            date: conflict.date,
                            dates: conflict.dates ? conflict.dates.slice() : undefined,
                            labels: conflict.labels ? conflict.labels.slice() : undefined,
                        });

                        return;
                    }

                    if (conflict.dates && existing.dates) {
                        conflict.dates.forEach(function (date) {
                            if (existing.dates.indexOf(date) === -1) {
                                existing.dates.push(date);
                            }
                        });
                        existing.dates.sort();
                    }
                });

                const isInvalid =
                    hasOverlap ||
                    hasPastDate ||
                    hasBadTimes ||
                    mergedConflicts.length > 0;

                if (isInvalid && errorBox) {
                    event.preventDefault();

                    if (hasPastDate) {
                        errorBox.textContent =
                            "New schedules cannot start before today.";
                    } else if (hasBadTimes) {
                        errorBox.textContent =
                            "The end time must be later than the start time.";
                    } else if (mergedConflicts.length) {
                        errorBox.textContent = conflictMessage(mergedConflicts);
                    } else {
                        errorBox.textContent =
                            "One or more schedules are invalid or overlap another schedule in this form.";
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

        const modeSelect = modal.querySelector("[data-add-mode]");
        const modeHint = modal.querySelector("[data-add-mode-hint]");
        const dateBasedFields = Array.from(
            modal.querySelectorAll("[data-add-date-based]"),
        );
        const partialDayFields = Array.from(
            modal.querySelectorAll("[data-add-partial-day]"),
        );
        const dateInput = modal.querySelector("[data-add-date]");
        const startTimeSelect = modal.querySelector("[data-add-start-time]");
        const endTimeSelect = modal.querySelector("[data-add-end-time]");

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

        function isPartialDay() {
            return modeSelect && modeSelect.value === MODE_PARTIAL_DAY;
        }

        function showError(message) {
            errorBox.textContent = message || "";
            errorBox.classList.toggle("d-none", !message);
        }

        function showSuccess(message) {
            successBox.textContent = message || "";
            successBox.classList.toggle("d-none", !message);
        }

        function clearResults() {
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
        }

        function resetAddPanel() {
            panel.classList.add("d-none");
            toggleBtn.classList.remove("d-none");
            clearResults();
            showError("");
            showSuccess("");

            if (endPicker) {
                endPicker.destroy();
                endPicker = null;
            }

            if (modeSelect) {
                modeSelect.value = MODE_DATE_BASED;
            }

            endInput.value = "";

            [startTimeSelect, endTimeSelect].forEach(function (select) {
                if (select) {
                    select.value = "";
                }
            });

            applyMode();
        }

        // Switching mode swaps which fields are in play, with no reload, and
        // throws away results that were found for the other kind of slot.
        function applyMode() {
            const partialDay = isPartialDay();

            dateBasedFields.forEach(function (field) {
                field.hidden = partialDay;
            });

            partialDayFields.forEach(function (field) {
                field.hidden = !partialDay;
            });

            if (modeHint) {
                modeHint.textContent = partialDay
                    ? "Books set hours on the clicked date. Residential projects only."
                    : "Books the whole of every day in the range.";
            }

            if (dateInput) {
                dateInput.value = selectedDate || "";
            }
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

        // The slot being asked about, as query/body parameters.
        function scheduleParams() {
            if (isPartialDay()) {
                if (!selectedDate || !startTimeSelect.value || !endTimeSelect.value) {
                    return null;
                }

                return {
                    scheduling_mode: MODE_PARTIAL_DAY,
                    project_date: selectedDate,
                    start_time: startTimeSelect.value,
                    end_time: endTimeSelect.value,
                };
            }

            if (!selectedDate || !endInput.value) {
                return null;
            }

            return {
                scheduling_mode: MODE_DATE_BASED,
                start_date: selectedDate,
                end_date: endInput.value,
            };
        }

        function loadEligible() {
            const params = scheduleParams();

            if (!params) {
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
                "/super-admin/schedules/assignable?" +
                new URLSearchParams(params).toString();

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
            applyMode();

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

        if (modeSelect) {
            modeSelect.addEventListener("change", function () {
                // Results found for the other kind of slot no longer apply.
                clearResults();
                showError("");
                applyMode();
                loadEligible();
            });
        }

        [startTimeSelect, endTimeSelect].forEach(function (select) {
            if (!select) {
                return;
            }

            select.addEventListener("change", function () {
                const from = minutesFromTime(startTimeSelect.value);
                const to = minutesFromTime(endTimeSelect.value);

                if (from !== null && to !== null && from >= to) {
                    clearResults();
                    showError("The end time must be later than the start time.");

                    return;
                }

                showError("");
                loadEligible();
            });
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
            const params = scheduleParams();

            if (!selectedProjectIds.length || !params) {
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
                body: JSON.stringify(
                    Object.assign({}, params, {
                        project_ids: selectedProjectIds,
                    }),
                ),
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
            // Partial days arrive as timed events; without this the bar would
            // abbreviate 8:00 AM to "8a".
            eventTimeFormat: {
                hour: "numeric",
                minute: "2-digit",
                meridiem: "short",
            },
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
                const scheduleLabel =
                    info.event.extendedProps.scheduleLabel || "";

                const tooltipParts = [info.event.title];

                if (projectName) {
                    tooltipParts.push(projectName);
                }

                if (scheduleLabel) {
                    tooltipParts.push(scheduleLabel);
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
