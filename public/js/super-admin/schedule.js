document.addEventListener("DOMContentLoaded", function () {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
        window.jQuery("#schedulesTable").DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            info: false,
            // A project ID is a label, not a quantity: without this
            // DataTables types the column as numeric and right-aligns it.
            // Ordering stays numeric.
            columnDefs: [{ targets: 0, className: "dt-left" }],
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

    /**
     * Dates read as "Aug 25, 2026" and are still submitted as 2026-08-25.
     *
     * flatpickr's alt input is what the person sees and types into; the real
     * field keeps the ISO value, which is what the server parses and what
     * every date comparison on this page is written against. Changing the
     * stored format instead would have meant rewriting all of those.
     */
    const FRIENDLY_DATE = {
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "M j, Y",
        allowInput: true,
    };

    const technicianSchedules =
        window.scheduleTechnicianAvailability &&
        typeof window.scheduleTechnicianAvailability === "object"
            ? window.scheduleTechnicianAvailability
            : {};
    const calendarEvents = Array.isArray(window.scheduleCalendarEvents)
        ? window.scheduleCalendarEvents
        : [];
    const projectLabels =
        window.scheduleProjectLabels &&
        typeof window.scheduleProjectLabels === "object"
            ? window.scheduleProjectLabels
            : {};
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

    /**
     * "Aug 25, 2026" - what a stored date reads as anywhere a person sees one,
     * matching the alt inputs the pickers show.
     */
    function friendlyDate(dateString) {
        if (!dateString) {
            return "";
        }

        return new Date(dateString + "T00:00:00").toLocaleDateString("en-US", {
            month: "short",
            day: "numeric",
            year: "numeric",
        });
    }

    function formatDateList(dates) {
        const currentYear = new Date().getFullYear();
        const allCurrentYear = dates.every(function (date) {
            return Number(date.slice(0, 4)) === currentYear;
        });

        const shown = dates.slice(0, 8);
        const remaining = dates.length - shown.length;

        // Abbreviated, matching the server's formatDateList(): a technician
        // blocked on six dates produced a sentence nobody read to the end, and
        // the month is the part that repeats. It is also the shape the date
        // pickers show, so a date quoted in a refusal reads as the date on the
        // field it came from.
        const labels = shown.map(function (date) {
            return new Date(date + "T00:00:00").toLocaleDateString(
                undefined,
                allCurrentYear
                    ? { month: "short", day: "numeric" }
                    : { month: "short", day: "numeric", year: "numeric" },
            );
        });

        if (remaining > 0) {
            labels.push(
                remaining + " more date" + (remaining === 1 ? "" : "s"),
            );
        }

        return formatList(labels);
    }

    /**
     * "A", "A and B", "A, B, and C" - the wording the dates already used,
     * pulled out so the project list reads the same way. Matches the server's
     * TechnicianAvailabilityService::joinList().
     */
    function formatList(items) {
        if (items.length === 1) {
            return items[0];
        }

        if (items.length === 2) {
            return items[0] + " and " + items[1];
        }

        const rest = items.slice();
        const last = rest.pop();

        return rest.join(", ") + ", and " + last;
    }

    /**
     * The projects standing in the way, named the way people quote them.
     *
     * A project's own bookings never reach here - busyIntervalsOn() drops them
     * - so anything listed is genuinely other work. Saying which turns "the
     * system is wrong about my own project" into "Kevin is on PRJ-000020 that
     * day", which somebody can actually act on.
     */
    function blockingProjects(intervals) {
        const seen = [];

        intervals.forEach(function (interval) {
            const label = projectLabels[interval.projectId];

            if (label && seen.indexOf(label) === -1) {
                seen.push(label);
            }
        });

        return seen.sort();
    }

    /**
     * " (booked on PRJ-000020)", matching the server's wording exactly - see
     * TechnicianAvailabilityService::describeBlockers().
     */
    function describeBlockers(projects) {
        if (!projects || !projects.length) {
            return "";
        }

        return " (booked on " + formatList(projects) + ")";
    }

    /**
     * Who cannot work it, when, and what is holding them - and nothing else.
     *
     * Word for word what the server says, so a refusal caught here and the
     * same refusal caught on save read identically. See
     * TechnicianAvailabilityService::conflictMessage().
     */
    function conflictMessage(conflicts) {
        if (!conflicts.length) {
            return "";
        }

        // Hours-only clashes name the hours; anything touching whole days
        // speaks in dates.
        if (
            conflicts.every(function (conflict) {
                return conflict.labels && conflict.labels.length;
            })
        ) {
            return conflicts
                .map(function (conflict) {
                    return (
                        conflict.name +
                        " is already booked " +
                        conflict.labels.join(" and ") +
                        " on " +
                        formatDateList([conflict.date]) +
                        describeBlockers(conflict.projects) +
                        "."
                    );
                })
                .join(" ");
        }

        return conflicts
            .map(function (conflict) {
                return (
                    conflict.name +
                    " is unavailable on " +
                    formatDateList(conflict.dates) +
                    describeBlockers(conflict.projects) +
                    "."
                );
            })
            .join(" ");
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
                    intervals.push({ from: from, to: to, projectId: range.project_id });
                }

                return;
            }

            if (date >= range.start && date <= range.end) {
                intervals.push({
                    from: 0,
                    to: MINUTES_PER_DAY,
                    projectId: range.project_id,
                });
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
                        projects: blockingProjects(clashes),
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
            const clashes = [];

            const hits = selectedDays.filter(function (day) {
                const intervals = busyIntervalsOn(
                    technicianId,
                    day,
                    excludeProjectId,
                );

                intervals.forEach(function (interval) {
                    clashes.push(interval);
                });

                return intervals.length > 0;
            });

            if (hits.length) {
                conflicts.push({
                    name:
                        technicianNames[technicianId] ||
                        "A selected technician",
                    dates: hits,
                    projects: blockingProjects(clashes),
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

    /**
     * Whether choosing `candidate` would stretch a booking across a day
     * nobody on the project can work.
     *
     * A date being free in itself was never enough. A booking that ends on
     * Aug 25 and is pulled back to start on Aug 10 books every day between the
     * two, so if somebody is busy on Aug 15 then Aug 10 is no more pickable
     * than Aug 15 is. The save has always said so - every day of the resulting
     * range is checked server-side - but the picker only greyed out the busy
     * day itself, so the refusal arrived after the click rather than instead
     * of it.
     *
     * `anchor` is the date at the range's other end as it stands right now:
     * the end date while a start is being chosen, the start date while an end
     * is. With no anchor there is no resulting range to judge and nothing is
     * blocked.
     *
     * A candidate on the far side of its anchor - a start later than the
     * current end, an end earlier than the current start - is left alone. That
     * is not a range at all but the first half of moving a booking, which the
     * other field's minDate then completes; refusing it here would make a
     * booking impossible to move forward past a busy day.
     *
     * `isBlocked` answers for one 'YYYY-MM-DD' string, so each caller keeps
     * whatever notion of "blocked" its own screen already uses.
     */
    function rangeSpansBlockedDay(candidate, anchor, candidateIsStart, isBlocked) {
        if (!candidate || !anchor) {
            return false;
        }

        const from = candidateIsStart ? candidate : anchor;
        const to = candidateIsStart ? anchor : candidate;

        if (from > to) {
            return false;
        }

        return eachDate(from, to).some(isBlocked);
    }

    /**
     * A Clear button inside the calendar itself.
     *
     * Emptying a date field matters more here than it looks. Each calendar is
     * bounded by whatever the other field holds - the start may not pass the
     * end, and neither may drag the range over a day somebody is booked on -
     * so a booking hemmed in at both ends can only be moved somewhere else by
     * letting go of one of its dates first. Clearing is that release.
     *
     * It goes on the calendar rather than in the modal because the calendar is
     * what somebody is looking at when they find they cannot pick the date
     * they want, and because it belongs to one field: a control in the modal
     * would have to say which of the two it meant.
     *
     * Clearing fires the picker's own change handler, which is what rebounds
     * the other calendar - so nothing else has to be told about it.
     */
    function attachClearButton(instance) {
        const button = document.createElement("button");

        // Explicitly not a submit button: it lives inside a form.
        button.type = "button";
        button.className = "schedule-picker-clear";
        button.textContent = "Clear";

        button.addEventListener("click", function () {
            instance.clear();
            instance.close();
        });

        instance.calendarContainer.appendChild(button);
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
        // A booking that has ended and that this reader may not correct. It
        // carries no schedule_id - the server keeps it whatever the form says -
        // so it must not be mistaken for a new row, whose dates would then read
        // as a new promise about days that have gone.
        const locked = row.dataset.locked === "1";

        if (rowMode(row) === MODE_PARTIAL_DAY) {
            return {
                mode: MODE_PARTIAL_DAY,
                date: value("[data-range-project-date]"),
                from: minutesFromTime(value("[data-range-start-time]")),
                to: minutesFromTime(value("[data-range-end-time]")),
                isNew: !locked && (!scheduleIdInput || !scheduleIdInput.value),
                locked: locked,
            };
        }

        return {
            mode: MODE_DATE_BASED,
            start: value("[data-range-start]"),
            end: value("[data-range-end]"),
            isNew: !locked && (!scheduleIdInput || !scheduleIdInput.value),
            locked: locked,
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

            // A date field is really two: the one that carries the value and
            // flatpickr's alt input, which is what a person sees and what the
            // browser validates. Hiding the pair leaves the alt input a
            // candidate for validation - being out of view is not the same as
            // being disabled - so an unused one would hold the whole form back
            // with a message nobody can see. It follows its own field.
            const altInput = field._flatpickr && field._flatpickr.altInput;

            if (altInput) {
                altInput.disabled = !pair[1];
                altInput.required = pair[1];
            }
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

    /**
     * The saved booking a row stands for, or null for one added just now.
     */
    function rowScheduleId(row) {
        const input = row.querySelector('input[name*="[schedule_id]"]');

        return input && input.value ? input.value : row.dataset.scheduleId || null;
    }

    /**
     * One row in the words the confirmation and the activity log use.
     */
    function describeEntry(entry) {
        if (entry.mode === MODE_PARTIAL_DAY) {
            const hours =
                entry.from === null || entry.to === null
                    ? "?"
                    : formatMinutes(entry.from) + " - " + formatMinutes(entry.to);

            return (entry.date || "?") + " " + hours;
        }

        return entry.start === entry.end
            ? entry.start || "?"
            : (entry.start || "?") + " - " + (entry.end || "?");
    }

    /**
     * Present a date field as settled: readable, submitted, but not offering to
     * change. Used for the start of a booking that is already under way, whose
     * first days have been worked.
     *
     * flatpickr is simply never attached, so the input the person sees is the
     * plain field carrying the stored date.
     */
    function markFrozen(input) {
        if (!input) {
            return;
        }

        input.readOnly = true;
        input.classList.add("schedule-range-input-frozen");
        input.title = "This schedule has started, so its start date is fixed.";
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

        // A booking that has ended, drawn as the record it is. Its fields are
        // disabled, and giving them date pickers would only invite a change
        // this page cannot make.
        if (row.dataset.locked === "1") {
            return;
        }

        // How far back each field may go, decided on the server by
        // ScheduleModeRules - editabilityOf() for a saved booking,
        // limitsForNewRange() for a row being added - so the picker offers
        // exactly what the save will accept. Empty means no bound, which is
        // now only a Super Admin correcting a booking that has ended: any date
        // may replace dates that were wrong.
        const startFrozen = row.dataset.startFrozen === "1";
        const earliestStart = row.dataset.earliestStart || null;
        const earliestEnd = row.dataset.earliestEnd || null;

        // Time already claimed by the other rows of this same form, so a row's
        // picker also blocks what is spoken for elsewhere in this project.
        const otherRowEntries = function () {
            return Array.from(container.querySelectorAll("[data-range-row]"))
                .filter(function (otherRow) {
                    // A locked row is entirely in the past and this form cannot
                    // move it, so it can never collide with anything being
                    // picked here.
                    return otherRow !== row && otherRow.dataset.locked !== "1";
                })
                .map(readRow)
                .filter(entryIsComplete);
        };

        // One answer per date, kept for as long as a picker stays open.
        //
        // The question is asked far more often than it used to be: flatpickr
        // puts it to every day it draws, and each of those now walks the whole
        // range the day would produce. The answer depends on the other rows
        // and on other projects' bookings, neither of which can change while a
        // calendar is open, so it is worked out once and cleared on the next
        // opening.
        const blockedDayCache = new Map();

        const wholeDayBlockedOn = function (dateString) {
            if (!dateString) {
                return false;
            }

            if (blockedDayCache.has(dateString)) {
                return blockedDayCache.get(dateString);
            }

            const busyElsewhere = technicianIds.some(function (technicianId) {
                return (
                    busyIntervalsOn(technicianId, dateString, projectId).length >
                    0
                );
            });

            const blocked =
                busyElsewhere ||
                otherRowEntries().some(function (entry) {
                    return entriesOverlap(entry, {
                        mode: MODE_DATE_BASED,
                        start: dateString,
                        end: dateString,
                    });
                });

            blockedDayCache.set(dateString, blocked);

            return blocked;
        };

        // A start is refused when the day itself is taken, and equally when
        // the range it would produce with the end already in the field runs
        // over a day that is. The end is judged the same way against the start.
        const startBlocked = function (date) {
            const dateString = normalizeDateString(date);

            return (
                wholeDayBlockedOn(dateString) ||
                rangeSpansBlockedDay(
                    dateString,
                    endInput ? endInput.value : "",
                    true,
                    wholeDayBlockedOn,
                )
            );
        };

        const endBlocked = function (date) {
            const dateString = normalizeDateString(date);

            return (
                wholeDayBlockedOn(dateString) ||
                rangeSpansBlockedDay(
                    dateString,
                    startInput ? startInput.value : "",
                    false,
                    wholeDayBlockedOn,
                )
            );
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
            // Each picker's disabled days depend on what the other field
            // currently holds, so moving one date redraws the other's calendar
            // rather than leaving it offering dates that no longer work.
            let startPicker = null;

            const endPicker = window.flatpickr(endInput, {
                ...FRIENDLY_DATE,
                minDate: earliestEnd,
                disable: [endBlocked],
                onReady: function (selectedDates, dateString, instance) {
                    attachClearButton(instance);
                },
                onOpen: function (selectedDates, dateString, instance) {
                    // Rows may have been edited since this calendar was built,
                    // so the answers are thrown away and the days redrawn
                    // against what the form says now.
                    blockedDayCache.clear();
                    instance.redraw();
                },
                onChange: function () {
                    if (!startPicker) {
                        return;
                    }

                    // A start may never sit after the end, so the ceiling
                    // moves with it. Clearing the end lifts the ceiling
                    // altogether, which is how a booking pinned between its
                    // own end and a busy day is moved somewhere else.
                    startPicker.set("maxDate", endInput.value || null);
                    startPicker.redraw();
                },
            });

            // A booking under way keeps the start it already worked. The field
            // still submits - the server compares it - but nothing here offers
            // to change it.
            if (startFrozen) {
                markFrozen(startInput);
            } else {
                startPicker = window.flatpickr(startInput, {
                    ...FRIENDLY_DATE,
                    minDate: earliestStart,
                    // The end the row already holds is the start's ceiling, so
                    // a date after it cannot be picked in the first place.
                    // Empty means the row has no end yet and nothing to cap.
                    maxDate: endInput.value || null,
                    disable: [startBlocked],
                    onReady: function (selectedDates, dateString, instance) {
                        attachClearButton(instance);
                    },
                    onOpen: function (selectedDates, dateString, instance) {
                        blockedDayCache.clear();
                        instance.redraw();
                    },
                    onChange: function (selectedDates) {
                        if (selectedDates[0]) {
                            endPicker.set("minDate", selectedDates[0]);
                        } else {
                            endPicker.set("minDate", earliestEnd);
                        }

                        endPicker.redraw();
                    },
                });
            }

            // The end may never precede the start, nor retreat past whatever
            // floor the row carries - whichever of the two is later wins.
            if (startInput.value && (!earliestEnd || startInput.value > earliestEnd)) {
                endPicker.set("minDate", startInput.value);
            }
        }

        if (projectDateInput) {
            if (startFrozen) {
                markFrozen(projectDateInput);

                return;
            }

            window.flatpickr(projectDateInput, {
                ...FRIENDLY_DATE,
                minDate: earliestStart,
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

    /**
     * The list can be emptied completely: a project holding no dates is
     * Unscheduled, which is a state it is allowed to be saved in. So no row is
     * ever undeletable, and the empty state stands in for the last one.
     */
    function refreshRangesState(modal, container) {
        const rows = container.querySelectorAll("[data-range-row]");
        const emptyState = modal.querySelector("[data-ranges-empty]");
        const countPill = modal.querySelector("[data-ranges-count]");

        if (emptyState) {
            emptyState.classList.toggle("d-none", rows.length > 0);
        }

        if (countPill) {
            countPill.textContent = rows.length + " scheduled";
        }
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

        // The rows exactly as the server sent them. Read before any picker has
        // touched the DOM: flatpickr replaces each date field with a visible
        // alt input of its own, and a snapshot taken later would carry those
        // duplicates with it.
        const pristineRows = container.innerHTML;
        const pristineNextIndex = container.dataset.nextIndex || "0";

        function clearError() {
            if (errorBox) {
                errorBox.classList.add("d-none");
                errorBox.textContent = "";
            }
        }

        function destroyRowPickers() {
            container
                .querySelectorAll(
                    "[data-range-start], [data-range-end], [data-range-project-date]",
                )
                .forEach(destroyPicker);
        }

        function initRows() {
            container
                .querySelectorAll("[data-range-row]")
                .forEach(function (row) {
                    initRangeRow(row, technicianIds, projectId, container);
                });

            refreshRangesState(modal, container);
        }

        initRows();

        // Closing without saving changes nothing. An edit lives only in this
        // form until it is submitted, so the form goes back to the schedule
        // the server sent rather than keeping dates that were never saved -
        // including rows that were removed, and rows that were added.
        modal.addEventListener("hidden.bs.modal", function () {
            destroyRowPickers();

            container.innerHTML = pristineRows;
            container.dataset.nextIndex = pristineNextIndex;

            initRows();
            clearError();
        });

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
                refreshRangesState(modal, container);
                clearError();
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
            refreshRangesState(modal, container);

            // Removing the row that was complained about may well have fixed
            // the complaint, so it does not stay on screen.
            clearError();
        });

        // Set only once a Super Admin has confirmed a correction, and honoured
        // by the server only for a Super Admin - the flag says the
        // confirmation was given, the account says it counts.
        const overrideInput = form
            ? form.querySelector("[data-override-past-lock]")
            : null;

        // What the bookings that have already ended said when this page was
        // drawn. A Super Admin may correct one, and the confirmation has to be
        // able to say what it is changing from.
        //
        // Read once, at load: once a field has been edited the row no longer
        // knows what it used to hold.
        const endedOnLoad = Array.from(
            container.querySelectorAll('[data-range-row][data-lock-state="locked"]'),
        ).map(function (row) {
            const entry = readRow(row);

            return {
                id: rowScheduleId(row),
                label: describeEntry(entry),
                entry: entry,
            };
        });

        /**
         * The corrections a Super Admin is about to make to work already on the
         * record - a booking that has ended and has been edited, or one that
         * has been taken off the form altogether.
         *
         * An Admin never produces any of these: their locked rows are drawn
         * read-only and carry no remove button, and the server keeps them
         * whatever is submitted.
         */
        function pendingOverrides() {
            return endedOnLoad
                .map(function (original) {
                    const row = original.id
                        ? container.querySelector(
                              '[data-range-row][data-schedule-id="' + original.id + '"]',
                          )
                        : null;

                    if (!row) {
                        return original.label + " -> removed";
                    }

                    const now = describeEntry(readRow(row));

                    return now === original.label
                        ? null
                        : original.label + " -> " + now;
                })
                .filter(Boolean);
        }

        if (form) {
            form.addEventListener("submit", function (event) {
                const rows = Array.from(
                    container.querySelectorAll("[data-range-row]"),
                );
                const todayString = normalizeDateString(new Date());

                let hasOverlap = false;
                let hasPastDate = false;
                let hasBadTimes = false;
                // Checked here rather than left to the browser: the date
                // fields are flatpickr alt inputs, and the real field behind
                // one is hidden, which bars it from native validation.
                let hasIncomplete = false;
                const availabilityConflicts = [];
                // Locked rows submit nothing and cannot be changed here, so
                // they are not checked: their dates are in the past by
                // definition, and reading them would report every one of them
                // as a schedule starting before today.
                const entries = rows.map(readRow).filter(function (entry) {
                    return !entry.locked;
                });

                entries.forEach(function (entry) {
                    if (entry.mode === MODE_PARTIAL_DAY) {
                        if (!entry.date || entry.from === null || entry.to === null) {
                            hasIncomplete = true;

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
                            hasIncomplete = true;

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
                            // Carried through the merge rather than dropped.
                            // conflictsForEntry() works out which projects are
                            // holding somebody, and losing them here was what
                            // left this page saying only that a technician is
                            // unavailable - the one screen that could name the
                            // work standing in the way and did not.
                            projects: conflict.projects
                                ? conflict.projects.slice()
                                : undefined,
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

                    // One technician held by several projects across the rows
                    // of this form is named once, with all of them.
                    if (conflict.projects && existing.projects) {
                        conflict.projects.forEach(function (project) {
                            if (existing.projects.indexOf(project) === -1) {
                                existing.projects.push(project);
                            }
                        });
                        existing.projects.sort();
                    }
                });

                const isInvalid =
                    hasOverlap ||
                    hasPastDate ||
                    hasBadTimes ||
                    hasIncomplete ||
                    mergedConflicts.length > 0;

                if (isInvalid && errorBox) {
                    event.preventDefault();

                    if (hasIncomplete) {
                        errorBox.textContent =
                            "Every schedule needs its dates filled in.";
                    } else if (hasPastDate) {
                        errorBox.textContent =
                            "New schedules cannot start before today.";
                    } else if (hasBadTimes) {
                        errorBox.textContent =
                            "The end time must be later than the start time.";
                    } else if (mergedConflicts.length) {
                        errorBox.textContent = conflictMessage(mergedConflicts);
                    } else {
                        errorBox.textContent =
                            "Two schedules in this form overlap.";
                    }

                    errorBox.classList.remove("d-none");

                    return;
                }

                // Correcting work already on the record is the one change on
                // this form that is asked about before it happens. Everything
                // else here can be put back by editing again; this cannot,
                // because what it overwrites is the record of what happened.
                const overrides = pendingOverrides();

                if (overrides.length) {
                    const confirmed = window.confirm(
                        "These ranges have already ended:\n\n" +
                            overrides.join("\n") +
                            "\n\nChanging them alters the record of completed work " +
                            "and is logged against your account. Continue?",
                    );

                    if (!confirmed) {
                        event.preventDefault();

                        return;
                    }

                    if (overrideInput) {
                        overrideInput.value = "1";
                    }
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
                // A lighter green than Completed, matching
                // Project::statusBadgeClass(): the work is done, but the
                // project is not closed until the client says so, and the two
                // must not look identical at a glance.
                awaiting_client_confirmation:
                    "bg-success-subtle text-success-emphasis border border-success-subtle",
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
        const dateErrorEl = modal.querySelector("[data-date-error]");
        const dateSuccessEl = modal.querySelector("[data-date-success]");

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
            // Nothing may be booked onto a day that has already gone - the
            // server refuses it, and offering the form here only produces an
            // error the person could not have avoided.
            toggleBtn.classList.toggle(
                "d-none",
                selectedDate < normalizeDateString(new Date()),
            );
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
                dateInput.value = friendlyDate(selectedDate);
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
                        "Unavailable - " +
                        blockingTechnician +
                        " is on " +
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
                                "Unable to load available projects.",
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
                    showError("Unable to load available projects.");
                });
        }

        // One booking, with the action that takes the clicked date off it.
        // The confirmation sits inline rather than in a dialog of its own: it
        // asks about one date, and it should not cover the list it came from.
        function bookingMarkup(project, range, dateLabel) {
            const chip =
                '<span class="schedule-date-card-range">' +
                '<i class="bi bi-calendar3"></i>' +
                escapeHtml(range.label) +
                "</span>";

            if (project.read_only) {
                return (
                    '<div class="schedule-date-card-booking">' +
                    chip +
                    '<span class="schedule-date-readonly">View only</span>' +
                    "</div>"
                );
            }

            // A day that has been worked is part of the project's record, and
            // today is being worked right now. Neither is given up from here -
            // the button is absent rather than disabled, so nothing invites a
            // click the server would refuse. Correcting a day that has passed
            // is a Super Admin's to do, in the schedule editor.
            const today = normalizeDateString(new Date());

            if (selectedDate <= today) {
                return (
                    '<div class="schedule-date-card-booking">' +
                    chip +
                    '<span class="schedule-date-readonly">' +
                    (selectedDate === today ? "Under way" : "Already worked") +
                    "</span>" +
                    "</div>"
                );
            }

            return (
                '<div class="schedule-date-card-booking">' +
                chip +
                '<button type="button" class="schedule-date-remove" data-remove-date="' +
                escapeHtml(range.remove_url) +
                '">' +
                '<i class="bi bi-calendar-x" aria-hidden="true"></i>' +
                "Remove This Date" +
                "</button>" +
                "</div>" +
                '<div class="schedule-date-confirm d-none" data-remove-confirm>' +
                "<span>Remove <strong>" +
                escapeHtml(dateLabel) +
                "</strong> from this project?</span>" +
                '<span class="schedule-date-confirm-actions">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-remove-cancel>' +
                "Cancel</button>" +
                '<button type="button" class="btn btn-sm btn-danger py-0 px-2" data-remove-go>' +
                "Remove</button>" +
                "</span>" +
                "</div>"
            );
        }

        function renderDateProjects(payload) {
            const projects = payload.projects || [];
            const dateLabel = payload.label || selectedDate;

            listEl.innerHTML = projects
                .map(function (project) {
                    const ranges = (project.ranges || [])
                        .map(function (range) {
                            return bookingMarkup(project, range, dateLabel);
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

        function showDateError(message) {
            dateErrorEl.textContent = message || "";
            dateErrorEl.classList.toggle("d-none", !message);
        }

        function showDateSuccess(message) {
            dateSuccessEl.textContent = message || "";
            dateSuccessEl.classList.toggle("d-none", !message);
        }

        function closeConfirmations() {
            listEl
                .querySelectorAll("[data-remove-confirm]")
                .forEach(function (confirmation) {
                    confirmation.classList.add("d-none");
                });
        }

        // Take the clicked date off one booking. The whole page is reloaded
        // afterwards, the same way saving a new booking does: the calendar,
        // the table and the availability data all have to catch up.
        function removeDate(button) {
            const url = button.dataset.removeDate;

            if (!url) {
                return;
            }

            button.disabled = true;
            showDateError("");

            fetch(url, {
                method: "DELETE",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
            })
                .then(function (response) {
                    return response.json().then(function (body) {
                        return { ok: response.ok, body: body };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        button.disabled = false;
                        showDateError(
                            result.body.error ||
                                "Unable to remove that date.",
                        );

                        return;
                    }

                    closeConfirmations();
                    showDateSuccess(
                        (result.body.message || "The date was removed.") +
                            " Refreshing…",
                    );

                    window.setTimeout(function () {
                        window.location.reload();
                    }, 900);
                })
                .catch(function () {
                    button.disabled = false;
                    showDateError("Unable to remove that date.");
                });
        }

        listEl.addEventListener("click", function (event) {
            const remove = event.target.closest("[data-remove-date]");

            if (remove) {
                // Only one date is ever in question at a time.
                closeConfirmations();
                remove
                    .closest(".schedule-date-card-booking")
                    .nextElementSibling.classList.remove("d-none");

                return;
            }

            const cancel = event.target.closest("[data-remove-cancel]");

            if (cancel) {
                cancel.closest("[data-remove-confirm]").classList.add("d-none");
                showDateError("");

                return;
            }

            const go = event.target.closest("[data-remove-go]");

            if (go) {
                const confirmation = go.closest("[data-remove-confirm]");
                const button = confirmation.previousElementSibling.querySelector(
                    "[data-remove-date]",
                );

                go.disabled = true;
                removeDate(button);
            }
        });

        function loadDateProjects() {
            const token = ++dateToken;

            loadingEl.classList.remove("d-none");
            listEl.innerHTML = "";
            emptyEl.classList.add("d-none");
            countEl.classList.add("d-none");
            showDateError("");
            showDateSuccess("");

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
                    emptyEl.textContent = "Unable to load projects for this date.";
                    emptyEl.classList.remove("d-none");
                });
        }

        toggleBtn.addEventListener("click", function () {
            toggleBtn.classList.add("d-none");
            panel.classList.remove("d-none");
            startInput.value = friendlyDate(selectedDate);
            applyMode();

            if (window.flatpickr) {
                endPicker = window.flatpickr(endInput, {
                    ...FRIENDLY_DATE,
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
                                    : "Unable to save schedule."),
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
                    showError("Unable to save schedule.");
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

    // ---------------------------------------------------------------
    // Unscheduled projects: the work the calendar cannot show, and the
    // flow for giving one of them its first dates.
    //
    // Both steps live in the one dialog. Picking a project swaps the list
    // for its form and Back swaps it straight back, so a second modal is
    // never stacked on this one.
    // ---------------------------------------------------------------

    function initUnscheduledModal(modal) {
        const listPanel = modal.querySelector("[data-unscheduled-list-panel]");
        const formPanel = modal.querySelector("[data-unscheduled-form-panel]");
        const backBtn = modal.querySelector("[data-unscheduled-back]");
        const titleEl = modal.querySelector("[data-unscheduled-title]");
        const eyebrowEl = modal.querySelector("[data-unscheduled-eyebrow]");

        const modeWrap = modal.querySelector("[data-unscheduled-mode-wrap]");
        const modeSelect = modal.querySelector("[data-unscheduled-mode]");
        const modeHint = modal.querySelector("[data-unscheduled-mode-hint]");
        const dateBasedFields = Array.from(
            modal.querySelectorAll("[data-unscheduled-date-based]"),
        );
        const partialDayFields = Array.from(
            modal.querySelectorAll("[data-unscheduled-partial-day]"),
        );

        const startInput = modal.querySelector("[data-unscheduled-start]");
        const endInput = modal.querySelector("[data-unscheduled-end]");
        const dateInput = modal.querySelector("[data-unscheduled-date]");
        const startTimeSelect = modal.querySelector(
            "[data-unscheduled-start-time]",
        );
        const endTimeSelect = modal.querySelector("[data-unscheduled-end-time]");

        const errorBox = modal.querySelector("[data-unscheduled-error]");
        const successBox = modal.querySelector("[data-unscheduled-success]");
        const saveBtn = modal.querySelector("[data-unscheduled-save]");
        const saveSpinner = modal.querySelector("[data-unscheduled-save-spinner]");

        // Both are read off the markup rather than written out again here,
        // so renaming the heading in the view renames it on the way back
        // from a project too.
        const listTitle = titleEl ? titleEl.textContent : "";
        const listEyebrow = eyebrowEl ? eyebrowEl.textContent : "";

        // The project being scheduled, or null while the list is showing.
        let picked = null;
        let pickers = [];

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

        function destroyPickers() {
            pickers.forEach(function (picker) {
                picker.destroy();
            });
            pickers = [];
        }

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
                    ? "Books set hours on one date, leaving the rest of that day free."
                    : "Books the whole of every day in the range.";
            }
        }

        // A day nobody on this project can work is not offered at all, the
        // same rule the per-project editor's pickers apply.
        function wholeDayBlockedOn(dateString) {
            if (!dateString || !picked) {
                return false;
            }

            return picked.technicianIds.some(function (technicianId) {
                return (
                    busyIntervalsOn(technicianId, dateString, picked.projectId)
                        .length > 0
                );
            });
        }

        // And neither is a free day whose selection would carry the range over
        // a day that is taken - the same cross-check the editor's pickers do,
        // so a range is created under the rule it will later be edited under.
        function startBlocked(date) {
            const dateString = normalizeDateString(date);

            return (
                wholeDayBlockedOn(dateString) ||
                rangeSpansBlockedDay(
                    dateString,
                    endInput ? endInput.value : "",
                    true,
                    wholeDayBlockedOn,
                )
            );
        }

        function endBlocked(date) {
            const dateString = normalizeDateString(date);

            return (
                wholeDayBlockedOn(dateString) ||
                rangeSpansBlockedDay(
                    dateString,
                    startInput ? startInput.value : "",
                    false,
                    wholeDayBlockedOn,
                )
            );
        }

        // A partial day only needs one hour of the date still open.
        function partialDayBlocked(date) {
            const dateString = normalizeDateString(date);

            if (!dateString || !picked) {
                return false;
            }

            return picked.technicianIds.some(function (technicianId) {
                return !hasFreeHourOn(
                    technicianId,
                    dateString,
                    picked.projectId,
                );
            });
        }

        function buildPickers() {
            destroyPickers();

            if (!window.flatpickr) {
                [startInput, endInput, dateInput].forEach(function (input) {
                    if (input) {
                        input.type = "date";
                        input.min = normalizeDateString(new Date());
                    }
                });

                return;
            }

            // Each picker's disabled days depend on what the other field
            // holds, so a change to one redraws the other.
            let startPicker = null;

            const endPicker = window.flatpickr(endInput, {
                ...FRIENDLY_DATE,
                minDate: "today",
                disable: [endBlocked],
                onReady: function (selectedDates, dateString, instance) {
                    attachClearButton(instance);
                },
                onOpen: function (selectedDates, dateString, instance) {
                    instance.redraw();
                },
                onChange: function () {
                    if (startPicker) {
                        // The start may never sit after the end; clearing the
                        // end lifts the ceiling again.
                        startPicker.set("maxDate", endInput.value || null);
                        startPicker.redraw();
                    }

                    showError("");
                },
            });

            startPicker = window.flatpickr(startInput, {
                ...FRIENDLY_DATE,
                minDate: "today",
                maxDate: endInput.value || null,
                disable: [startBlocked],
                onReady: function (selectedDates, dateString, instance) {
                    attachClearButton(instance);
                },
                onOpen: function (selectedDates, dateString, instance) {
                    instance.redraw();
                },
                onChange: function (selectedDates) {
                    endPicker.set("minDate", selectedDates[0] || "today");
                    endPicker.redraw();
                    showError("");
                },
            });

            const datePicker = window.flatpickr(dateInput, {
                ...FRIENDLY_DATE,
                minDate: "today",
                disable: [partialDayBlocked],
                onChange: function () {
                    // The hours on offer belong to the date just chosen.
                    [startTimeSelect, endTimeSelect].forEach(function (select) {
                        select.value = "";
                    });
                    refreshTimeOptions();
                    showError("");
                },
            });

            pickers = [endPicker, startPicker, datePicker];
        }

        // Grey out the hours this project's team cannot work on the chosen
        // date, exactly as the per-project editor does.
        function refreshTimeOptions() {
            if (!picked) {
                return;
            }

            const date = dateInput.value;

            const freeBetween = function (from, to) {
                return picked.technicianIds.every(function (technicianId) {
                    return isFreeBetween(
                        busyIntervalsOn(technicianId, date, picked.projectId),
                        from,
                        to,
                    );
                });
            };

            Array.from(startTimeSelect.options).forEach(function (option) {
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

            const startMinutes = minutesFromTime(startTimeSelect.value);

            Array.from(endTimeSelect.options).forEach(function (option) {
                if (!option.value) {
                    return;
                }

                const to = minutesFromTime(option.value);

                if (to === null || startMinutes === null) {
                    option.disabled = false;

                    return;
                }

                option.disabled =
                    to <= startMinutes ||
                    (date ? !freeBetween(startMinutes, to) : false);
            });

            [startTimeSelect, endTimeSelect].forEach(function (select) {
                const selected = select.options[select.selectedIndex];

                if (selected && selected.disabled) {
                    select.value = "";
                }
            });
        }

        function showList() {
            picked = null;
            destroyPickers();

            listPanel.classList.remove("d-none");
            formPanel.classList.add("d-none");
            saveBtn.classList.add("d-none");

            if (titleEl) {
                titleEl.textContent = listTitle;
            }

            if (eyebrowEl) {
                eyebrowEl.textContent = listEyebrow;
            }

            showError("");
            showSuccess("");
        }

        function showForm(button) {
            picked = {
                projectId: button.dataset.unscheduledPick,
                name: button.dataset.projectName || "",
                referenceNo: button.dataset.referenceNo || "",
                partialDayAllowed: button.dataset.partialDayAllowed === "1",
                technicianIds: (button.dataset.technicianIds || "")
                    .split(",")
                    .map(function (id) {
                        return id.trim();
                    })
                    .filter(Boolean),
            };

            listPanel.classList.add("d-none");
            formPanel.classList.remove("d-none");
            saveBtn.classList.remove("d-none");
            saveBtn.disabled = false;

            if (titleEl) {
                titleEl.textContent = picked.name;
            }

            if (eyebrowEl) {
                eyebrowEl.textContent = picked.referenceNo
                    ? "Schedule · " + picked.referenceNo
                    : "Schedule";
            }

            // Partial days are a Residential offering, so a Commercial
            // project is never shown the choice.
            modeSelect.value = MODE_DATE_BASED;
            modeWrap.classList.toggle("d-none", !picked.partialDayAllowed);

            [startInput, endInput, dateInput].forEach(function (input) {
                input.value = "";
            });

            [startTimeSelect, endTimeSelect].forEach(function (select) {
                select.value = "";
            });

            applyMode();
            buildPickers();
            refreshTimeOptions();
            showError("");
            showSuccess("");
        }

        // What the picked slot means, as the assign endpoint reads it.
        function scheduleParams() {
            if (isPartialDay()) {
                if (
                    !dateInput.value ||
                    !startTimeSelect.value ||
                    !endTimeSelect.value
                ) {
                    return null;
                }

                return {
                    scheduling_mode: MODE_PARTIAL_DAY,
                    project_date: dateInput.value,
                    start_time: startTimeSelect.value,
                    end_time: endTimeSelect.value,
                };
            }

            if (!startInput.value || !endInput.value) {
                return null;
            }

            return {
                scheduling_mode: MODE_DATE_BASED,
                start_date: startInput.value,
                end_date: endInput.value,
            };
        }

        /**
         * Everything the server will check, checked here first so a mistake is
         * answered without a round trip. The server checks it all again.
         */
        function validationError(params) {
            const todayString = normalizeDateString(new Date());

            if (params.scheduling_mode === MODE_PARTIAL_DAY) {
                const from = minutesFromTime(params.start_time);
                const to = minutesFromTime(params.end_time);

                if (from === null || to === null || from >= to) {
                    return "The end time must be later than the start time.";
                }

                if (params.project_date < todayString) {
                    return "The project date cannot be in the past.";
                }

                return conflictMessage(
                    conflictsForEntry(
                        {
                            mode: MODE_PARTIAL_DAY,
                            date: params.project_date,
                            from: from,
                            to: to,
                        },
                        picked.technicianIds,
                        picked.projectId,
                    ),
                );
            }

            if (params.end_date < params.start_date) {
                return "The end date must be on or after the start date.";
            }

            if (params.start_date < todayString) {
                return "The start date cannot be in the past.";
            }

            return conflictMessage(
                conflictsForEntry(
                    {
                        mode: MODE_DATE_BASED,
                        start: params.start_date,
                        end: params.end_date,
                    },
                    picked.technicianIds,
                    picked.projectId,
                ),
            );
        }

        listPanel.addEventListener("click", function (event) {
            const button = event.target.closest("[data-unscheduled-pick]");

            if (button) {
                showForm(button);
            }
        });

        backBtn.addEventListener("click", showList);

        if (modeSelect) {
            modeSelect.addEventListener("change", function () {
                applyMode();
                showError("");
            });
        }

        [startTimeSelect, endTimeSelect].forEach(function (select) {
            select.addEventListener("change", function () {
                refreshTimeOptions();
                showError("");
            });
        });

        saveBtn.addEventListener("click", function () {
            if (!picked) {
                return;
            }

            const params = scheduleParams();

            if (!params) {
                showError(
                    isPartialDay()
                        ? "Pick the date and both times first."
                        : "Pick a start date and an end date first.",
                );

                return;
            }

            const problem = validationError(params);

            if (problem) {
                showError(problem);

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
                        project_ids: [Number(picked.projectId)],
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
                                "Unable to save schedule.",
                        );

                        return;
                    }

                    showSuccess(
                        (result.body.message || "Schedule saved.") +
                            " Refreshing…",
                    );

                    // Reload so the calendar, the table and the availability
                    // data all reflect the new booking.
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 900);
                })
                .catch(function () {
                    saveSpinner.classList.add("d-none");
                    saveBtn.disabled = false;
                    showError("Unable to save schedule.");
                });
        });

        // Reopening starts at the list, never half way into a project that
        // was picked and then abandoned.
        modal.addEventListener("hidden.bs.modal", showList);
    }

    const unscheduledModalEl = document.querySelector("[data-unscheduled-modal]");

    if (unscheduledModalEl) {
        initUnscheduledModal(unscheduledModalEl);
    }

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
                    // Still clickable - it opens the view-only panel - so the
                    // tooltip says what clicking will get you.
                    tooltipParts.push("View only");
                    info.el.classList.add("fc-event-readonly");
                }

                info.el.setAttribute("title", tooltipParts.join(" · "));
            },
            // A completed project opens as a record of its dates; everything
            // else opens the editor.
            eventClick: function (info) {
                const projectId = info.event.extendedProps.projectId;
                const modalEl = info.event.extendedProps.readOnly
                    ? document.getElementById("scheduleViewModal" + projectId)
                    : document.getElementById("scheduleEditModal" + projectId);

                if (modalEl && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
            },
        });

        calendar.render();
        window.calendarHeader.attach(calendar, calendarEl);
    }
});
