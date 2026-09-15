/**
 * Shared task date pickers for the Tasks page and the Project Details page.
 *
 * A project's schedule can have gaps - booked Aug 10-15 and Aug 25-30, say. A
 * task may SPAN such a gap (start Aug 14, finish Aug 26): a task is a piece of
 * work with a deadline, not a claim on a day. What it may not do is begin or
 * end on a day nobody is booked, so only the booked days are selectable and
 * the gap days are greyed out - the calendar shows the project's actual shape
 * rather than one long block that is mostly untrue.
 *
 * The server re-checks the same rule (TaskScheduleRules), so this is a
 * convenience layer, not the source of truth.
 */
(function (global) {
    'use strict';

    /**
     * The period the ranges span: earliest booked date to latest.
     */
    function scheduleWindow(ranges) {
        if (!ranges || !ranges.length) {
            return null;
        }

        return ranges.reduce(function (window, range) {
            return {
                start: range.start < window.start ? range.start : window.start,
                end: range.end > window.end ? range.end : window.end,
            };
        }, { start: ranges[0].start, end: ranges[0].end });
    }

    function formatDate(dateString) {
        return new Date(dateString + 'T00:00:00').toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    }

    /**
     * "Aug 10, 2026 - Aug 30, 2026", matching TaskScheduleRules::describeWindow().
     */
    function describeWindow(ranges) {
        const window = scheduleWindow(ranges);

        return window ? formatDate(window.start) + ' - ' + formatDate(window.end) : '';
    }

    /**
     * The hint under the pickers, word for word what
     * TaskScheduleRules::describeSelectable() writes on the server-rendered
     * forms - the Tasks page builds its own when a project is chosen, and the
     * two must not describe the same rule differently.
     */
    function describeSelectable(ranges) {
        if (!ranges || !ranges.length) {
            return 'No schedule set - tasks cannot be dated yet.';
        }

        return 'Booked: ' + ranges.map(function (range) {
            return formatDate(range.start) + ' - ' + formatDate(range.end);
        }).join('; ') + '.';
    }

    /**
     * The booked ranges as flatpickr's `enable` list. Every other day in the
     * calendar is then unselectable, which is what stops a gap day being
     * chosen as a start or a deadline.
     */
    function enabledRanges(ranges) {
        return (ranges || []).map(function (range) {
            return { from: range.start, to: range.end };
        });
    }

    function applyScheduleRanges(startInput, dueInput, ranges) {
        if (!global.flatpickr || !startInput || !dueInput) {
            return;
        }

        [startInput, dueInput].forEach(function (input) {
            if (input._flatpickr) {
                input._flatpickr.destroy();
            }
        });

        const window = scheduleWindow(ranges);

        // This function owns whether the two fields can be used at all: a
        // project with booked days can be dated, one without cannot.
        if (!window) {
            // Nothing is booked, so there is no day a task could start or end
            // on. Drawn as the same picker as every other date field, switched
            // off and saying why - not left as a bare text box that accepts
            // anything and is only refused once it reaches the server.
            [startInput, dueInput].forEach(function (input) {
                input.value = '';
                input.disabled = true;
                input.title = 'This project has no schedule yet, so tasks cannot be dated.';

                if (global.appDatePicker) {
                    global.appDatePicker.upgrade(input);
                }
            });

            return;
        }

        [startInput, dueInput].forEach(function (input) {
            input.disabled = false;
            input.removeAttribute('title');
        });

        const enable = enabledRanges(ranges);

        // `minDate` on the deadline is what keeps it at or after the start.
        // It narrows the same enabled set rather than replacing it, so the gap
        // days stay unselectable however the start moves - which is the whole
        // point: a task may run across a gap, but not stop in one.
        const duePicker = global.flatpickr(dueInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            enable: enable,
            minDate: startInput.value || window.start,
        });

        global.flatpickr(startInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            enable: enable,
            onChange: function (selectedDates, dateStr) {
                // The only rule left between the two: a task cannot finish
                // before it starts.
                duePicker.set('minDate', dateStr);

                if (dueInput.value && dueInput.value < dateStr) {
                    duePicker.clear(false);
                }
            },
        });
    }

    // ------------------------------------------------------------------
    // Who is assigned on which days
    //
    // A task's technician has to be assigned to the project for every day of
    // it - one continuous period, start to due date (TaskAssignmentRules on
    // the server). Each technician card carries its periods as
    // data-assignment-periods='[{"start":"2026-08-01","end":"2026-08-20"}]',
    // `end` being the last day covered and null for no end. From those:
    //
    //   - choosing a technician greys out, in both date pickers, every booked
    //     day they are not assigned for;
    //   - choosing dates switches off every technician no single period of
    //     theirs covers, with the reason on the card.
    //
    // A task being edited keeps its own holder and dates selectable, the same
    // allowance the server makes: an edit that changes neither is never
    // refused. The server re-checks all of it.
    // ------------------------------------------------------------------

    function addDays(value, days) {
        const date = new Date(value + 'T00:00:00');

        date.setDate(date.getDate() + days);

        return date.getFullYear() + '-' +
            String(date.getMonth() + 1).padStart(2, '0') + '-' +
            String(date.getDate()).padStart(2, '0');
    }

    function periodsOf(radio) {
        try {
            return JSON.parse(radio.dataset.assignmentPeriods || '[]');
        } catch (error) {
            return [];
        }
    }

    /** One period holds the whole of [start, due]. */
    function covers(periods, start, due) {
        return periods.some(function (period) {
            return (!period.start || period.start <= start) && (period.end === null || period.end >= due);
        });
    }

    /** The day inside a period, or null. */
    function periodOn(periods, day) {
        return periods.find(function (period) {
            return (!period.start || period.start <= day) && (period.end === null || period.end >= day);
        }) || null;
    }

    /**
     * Why this technician cannot hold [start, due] - the server's sentences,
     * shortened for a card.
     */
    function refusal(periods, start, due) {
        if (!periods.length) {
            return 'Not assigned to this project';
        }

        const atStart = periodOn(periods, start);
        const atDue = periodOn(periods, due);

        if (atStart && atDue) {
            return 'Off this project ' + formatDate(addDays(atStart.end, 1)) + ' - ' + formatDate(addDays(atDue.start, -1));
        }

        if (atStart) {
            return 'Assigned until ' + formatDate(atStart.end);
        }

        if (atDue) {
            return 'Joins on ' + formatDate(atDue.start);
        }

        return 'Not assigned for these dates';
    }

    /**
     * A short note for a card that is not simply "on the team from before
     * today with no end": when they join, when they leave, and any stretch in
     * between that they are off the project.
     */
    function periodHint(periods, today) {
        const upcoming = periods.filter(function (period) {
            return period.end === null || period.end >= today;
        });

        if (!upcoming.length) {
            return '';
        }

        const notes = [];
        const first = upcoming[0];

        if (first.start && first.start > today) {
            notes.push('Joins ' + formatDate(first.start));
        }

        for (let index = 1; index < upcoming.length; index++) {
            notes.push('Off ' + formatDate(addDays(upcoming[index - 1].end, 1)) + ' - ' +
                formatDate(addDays(upcoming[index].start, -1)));
        }

        const last = upcoming[upcoming.length - 1];

        if (last.end !== null) {
            notes.push('Until ' + formatDate(last.end));
        }

        return notes.join(' · ');
    }

    /** The schedule's booked ranges narrowed to one technician's periods. */
    function intersect(ranges, periods) {
        const result = [];

        ranges.forEach(function (range) {
            periods.forEach(function (period) {
                const start = !period.start || period.start < range.start ? range.start : period.start;
                const end = period.end === null || period.end > range.end ? range.end : period.end;

                if (start <= end) {
                    result.push({ start: start, end: end });
                }
            });
        });

        return result;
    }

    function todayString() {
        const now = new Date();

        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0');
    }

    /**
     * Tie a task form's technician cards to its two date pickers.
     */
    function bindTechnicianPeriods(form, startInput, dueInput, ranges) {
        if (!form || !startInput || !dueInput) {
            return;
        }

        const radios = Array.prototype.slice.call(
            form.querySelectorAll('input[name="technician_id"][data-assignment-periods]')
        );

        if (!radios.length) {
            return;
        }

        // Kept on the form so a page that redraws its cards - the Tasks page,
        // when a different project is chosen - can bind again without the
        // date inputs collecting a second set of listeners.
        const state = {
            radios: radios,
            ranges: ranges,
            originalStart: startInput.value,
            originalDue: dueInput.value,
        };

        const today = todayString();

        function isUnchangedHolder(radio) {
            return radio.dataset.holdsTask === '1'
                && startInput.value === state.originalStart
                && dueInput.value === state.originalDue;
        }

        function noteFor(radio) {
            const card = radio.parentElement.querySelector('.task-assign-card');

            if (!card) {
                return null;
            }

            let note = card.querySelector('[data-period-note]');

            if (!note) {
                note = document.createElement('div');
                note.className = 'task-assign-period';
                note.setAttribute('data-period-note', '');
                card.appendChild(note);
            }

            return note;
        }

        function refreshCards() {
            const start = startInput.value;
            const due = dueInput.value;

            state.radios.forEach(function (radio) {
                const periods = periodsOf(radio);
                const note = noteFor(radio);
                const blocked = Boolean(start && due)
                    && !covers(periods, start, due)
                    && !isUnchangedHolder(radio);

                // Only this rule's own switch: a card switched off because the
                // account is inactive stays off whatever the dates say.
                if (blocked) {
                    radio.disabled = true;
                    radio.dataset.periodBlocked = '1';
                } else if (radio.dataset.periodBlocked === '1') {
                    radio.disabled = false;
                    delete radio.dataset.periodBlocked;
                }

                if (note) {
                    note.textContent = blocked ? refusal(periods, start, due) : periodHint(periods, today);
                    note.classList.toggle('is-blocked', blocked);
                }
            });
        }

        function applyForSelected() {
            const selected = state.radios.find(function (radio) {
                return radio.checked;
            });

            if (!selected) {
                applyScheduleRanges(startInput, dueInput, state.ranges);
                refreshCards();

                return;
            }

            let narrowed = intersect(state.ranges, periodsOf(selected));

            // The holder of a task being edited may keep its dates.
            if (selected.dataset.holdsTask === '1' && state.originalStart && state.originalDue) {
                narrowed = narrowed.concat([
                    { start: state.originalStart, end: state.originalStart },
                    { start: state.originalDue, end: state.originalDue },
                ]);
            }

            // Nobody is left without a calendar: a technician with no booked
            // day in common with the project is refused on the card instead.
            applyScheduleRanges(startInput, dueInput, narrowed.length ? narrowed : state.ranges);
            refreshCards();
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', applyForSelected);
        });

        form._taskPeriods = { refresh: refreshCards };

        if (!startInput.dataset.periodListener) {
            [startInput, dueInput].forEach(function (input) {
                input.dataset.periodListener = '1';
                input.addEventListener('change', function () {
                    if (form._taskPeriods) {
                        form._taskPeriods.refresh();
                    }
                });
            });
        }

        applyForSelected();
    }

    /**
     * Wire up any markup that carries its ranges inline, i.e.
     * <div data-task-date-row data-schedule-ranges='[...]'>.
     */
    function initInlineRows(root) {
        (root || document).querySelectorAll('[data-task-date-row]').forEach(function (row) {
            const startInput = row.querySelector('[data-task-start]');
            const dueInput = row.querySelector('[data-task-due]');

            if (!startInput || !dueInput) {
                return;
            }

            // A task that can no longer be edited still shows its dates the
            // way every other date field does - "Sep 27, 2026", not the raw
            // 2026-09-27 the field holds - through the shared picker, which
            // will not open on a readonly field.
            if (startInput.hasAttribute('readonly')) {
                if (global.appDatePicker) {
                    global.appDatePicker.upgrade(startInput);
                    global.appDatePicker.upgrade(dueInput);
                }

                return;
            }

            let ranges = [];

            try {
                ranges = JSON.parse(row.dataset.scheduleRanges || '[]');
            } catch (error) {
                ranges = [];
            }

            applyScheduleRanges(startInput, dueInput, ranges);
            bindTechnicianPeriods(row.closest('form'), startInput, dueInput, ranges);
        });
    }

    global.taskDatePickers = {
        scheduleWindow: scheduleWindow,
        describeWindow: describeWindow,
        describeSelectable: describeSelectable,
        applyScheduleRanges: applyScheduleRanges,
        initInlineRows: initInlineRows,
        bindTechnicianPeriods: bindTechnicianPeriods,
    };

    document.addEventListener('DOMContentLoaded', function () {
        initInlineRows(document);
    });

    // Project Details redraws its content after a save - see
    // projectWorkspace.js - and the redrawn task dialogs need pickers again.
    document.addEventListener('workspace:updated', function (event) {
        initInlineRows(event.detail.root);
    });
})(window);
