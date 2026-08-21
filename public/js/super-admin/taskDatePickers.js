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
        return new Date(dateString + 'T00:00:00').toLocaleDateString(undefined, {
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

        if (!window) {
            return;
        }

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

    /**
     * Wire up any markup that carries its ranges inline, i.e.
     * <div data-task-date-row data-schedule-ranges='[...]'>.
     */
    function initInlineRows(root) {
        (root || document).querySelectorAll('[data-task-date-row]').forEach(function (row) {
            const startInput = row.querySelector('[data-task-start]');
            const dueInput = row.querySelector('[data-task-due]');

            if (!startInput || !dueInput || startInput.hasAttribute('readonly')) {
                return;
            }

            let ranges = [];

            try {
                ranges = JSON.parse(row.dataset.scheduleRanges || '[]');
            } catch (error) {
                ranges = [];
            }

            applyScheduleRanges(startInput, dueInput, ranges);
        });
    }

    global.taskDatePickers = {
        scheduleWindow: scheduleWindow,
        describeWindow: describeWindow,
        describeSelectable: describeSelectable,
        applyScheduleRanges: applyScheduleRanges,
        initInlineRows: initInlineRows,
    };

    document.addEventListener('DOMContentLoaded', function () {
        initInlineRows(document);
    });
})(window);
