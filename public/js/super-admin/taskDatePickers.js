/**
 * Shared task date pickers for the Tasks page and the Project Details page.
 *
 * A project's schedule can have gaps - booked Jul 10-15 and Jul 20-25, say.
 * A task has to sit inside ONE of those ranges, so:
 *
 *   - only days covered by a range are selectable, and
 *   - once a start date is picked the due date is pinned to that same range,
 *     which is what stops a task straddling the gap.
 *
 * The server re-checks the same rule (TaskController), so this is a
 * convenience layer, not the source of truth.
 */
(function (global) {
    'use strict';

    function describeRanges(ranges) {
        return (ranges || [])
            .map(function (range) {
                const format = { month: 'short', day: 'numeric', year: 'numeric' };

                return (
                    new Date(range.start + 'T00:00:00').toLocaleDateString(undefined, format) +
                    ' – ' +
                    new Date(range.end + 'T00:00:00').toLocaleDateString(undefined, format)
                );
            })
            .join('; ');
    }

    function enableSpecs(ranges) {
        return (ranges || []).map(function (range) {
            return { from: range.start, to: range.end };
        });
    }

    function rangeContaining(ranges, dateString) {
        return (
            (ranges || []).find(function (range) {
                return dateString >= range.start && dateString <= range.end;
            }) || null
        );
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

        if (!ranges || !ranges.length) {
            return;
        }

        const duePicker = global.flatpickr(dueInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            enable: enableSpecs(ranges),
        });

        global.flatpickr(startInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            enable: enableSpecs(ranges),
            onChange: function (selectedDates, dateStr) {
                const active = rangeContaining(ranges, dateStr);

                if (!active) {
                    return;
                }

                duePicker.set('enable', [{ from: dateStr, to: active.end }]);

                // A due date left over from another range would straddle the
                // gap, so drop it.
                if (dueInput.value && (dueInput.value < dateStr || dueInput.value > active.end)) {
                    duePicker.clear(false);
                }
            },
        });

        // Editing a saved task: narrow the due picker to its range up front.
        if (startInput.value) {
            const active = rangeContaining(ranges, startInput.value);

            if (active) {
                duePicker.set('enable', [{ from: startInput.value, to: active.end }]);
            }
        }
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
        describeRanges: describeRanges,
        applyScheduleRanges: applyScheduleRanges,
        initInlineRows: initInlineRows,
    };

    document.addEventListener('DOMContentLoaded', function () {
        initInlineRows(document);
    });
})(window);
