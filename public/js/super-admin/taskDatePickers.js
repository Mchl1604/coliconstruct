/**
 * Shared task date pickers for the Tasks page and the Project Details page.
 *
 * A project's schedule can have gaps - booked Aug 10-15 and Aug 25-30, say. A
 * task is measured against the whole period those ranges span, Aug 10 through
 * Aug 30, so every day in that period is offered, including the ones between
 * the ranges. A task is a piece of work with a deadline, not a claim on a day.
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

        const duePicker = global.flatpickr(dueInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            minDate: startInput.value || window.start,
            maxDate: window.end,
        });

        global.flatpickr(startInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            minDate: window.start,
            maxDate: window.end,
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
        applyScheduleRanges: applyScheduleRanges,
        initInlineRows: initInlineRows,
    };

    document.addEventListener('DOMContentLoaded', function () {
        initInlineRows(document);
    });
})(window);
