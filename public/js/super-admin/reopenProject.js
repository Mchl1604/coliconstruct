/**
 * Date pickers for the Reopen Project dialog.
 *
 * Reopening books a NEW schedule - the days released when completion was
 * requested are gone and may already have been taken - so the same two
 * refusals that guard every other booking apply, and this is them drawn on
 * the calendar instead of discovered after pressing the button:
 *
 *   - every technician on the team has to be free for the whole of the range,
 *   - and the range may not land on the days the project itself kept.
 *
 * Both lists are worked out on the server by
 * ProjectController::reopenBlockedDates(), from the very rules ProjectReopen
 * enforces, and ride in on the form. The project is left out of its own
 * availability answer there, so it can never read as its own blocker.
 *
 * A whole-day range needs every day it covers free, so a start is refused when
 * the day it lands on is taken AND when the range it would make with the end
 * already in the field runs over a day that is - and the end is judged the
 * same way against the start. An hours-only booking only needs an hour of its
 * one date, which is the second, shorter list.
 *
 * The server re-runs both checks on whatever arrives, so this is a
 * convenience layer, not the source of truth: a date typed past a greyed-out
 * one is still refused.
 */
(function (global) {
    'use strict';

    const MODE_PARTIAL_DAY = 'partial_day';

    function parseDates(value) {
        try {
            const parsed = JSON.parse(value || '[]');

            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function toDateString(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return null;
        }

        return date.getFullYear()
            + '-' + String(date.getMonth() + 1).padStart(2, '0')
            + '-' + String(date.getDate()).padStart(2, '0');
    }

    /**
     * Inclusive list of 'YYYY-MM-DD' between two date strings, in whichever
     * order they arrived - a person picks an end before a start as often as
     * the other way round.
     */
    function eachDate(fromValue, toValue) {
        const from = fromValue < toValue ? fromValue : toValue;
        const to = fromValue < toValue ? toValue : fromValue;
        const cursor = new Date(from + 'T00:00:00');
        const end = new Date(to + 'T00:00:00');
        const dates = [];

        if (Number.isNaN(cursor.getTime()) || Number.isNaN(end.getTime())) {
            return dates;
        }

        while (cursor <= end) {
            dates.push(toDateString(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }

        return dates;
    }

    function initForm(form) {
        if (!global.flatpickr) {
            return;
        }

        const startInput = form.querySelector('[data-reopen-start]');
        const endInput = form.querySelector('[data-reopen-end]');
        const projectDateInput = form.querySelector('[data-reopen-project-date]');
        const earliest = form.dataset.reopenEarliest || null;

        const blockedWholeDay = {};
        const blockedPartialDay = {};

        parseDates(form.dataset.reopenBlockedWholeDay).forEach(function (date) {
            blockedWholeDay[date] = true;
        });

        parseDates(form.dataset.reopenBlockedPartialDay).forEach(function (date) {
            blockedPartialDay[date] = true;
        });

        // A day is refused on its own account, or because the range it would
        // make with the date already in the other field crosses one that is.
        function wholeDayBlocked(date, otherValue) {
            const dateString = toDateString(date);

            if (!dateString) {
                return false;
            }

            if (blockedWholeDay[dateString]) {
                return true;
            }

            if (!otherValue) {
                return false;
            }

            return eachDate(dateString, otherValue).some(function (day) {
                return Boolean(blockedWholeDay[day]);
            });
        }

        if (startInput && endInput) {
            let startPicker = null;

            const endPicker = global.flatpickr(endInput, {
                dateFormat: 'Y-m-d',
                allowInput: false,
                minDate: startInput.value || earliest,
                disable: [function (date) {
                    return wholeDayBlocked(date, startInput.value);
                }],
                onChange: function () {
                    if (!startPicker) {
                        return;
                    }

                    // A start may never sit after the end, so the ceiling
                    // moves with it. Redrawn because the start's own refusals
                    // are judged against whatever the end now holds.
                    startPicker.set('maxDate', endInput.value || null);
                    startPicker.redraw();
                },
            });

            startPicker = global.flatpickr(startInput, {
                dateFormat: 'Y-m-d',
                allowInput: false,
                minDate: earliest,
                maxDate: endInput.value || null,
                disable: [function (date) {
                    return wholeDayBlocked(date, endInput.value);
                }],
                onChange: function (selectedDates, dateString) {
                    endPicker.set('minDate', dateString || earliest);
                    endPicker.redraw();
                },
            });
        }

        if (projectDateInput) {
            global.flatpickr(projectDateInput, {
                dateFormat: 'Y-m-d',
                allowInput: false,
                minDate: earliest,
                disable: [function (date) {
                    const dateString = toDateString(date);

                    return Boolean(dateString && blockedPartialDay[dateString]);
                }],
            });
        }

        // Swapping the mode swaps which fields are live, and the group left
        // behind keeps whatever was typed into it. Clearing it is what stops a
        // reopen carrying dates from a mode nobody chose - the server reads
        // only the fields the chosen mode names, but a stale value re-appearing
        // when somebody switches back reads as a date they picked.
        const modeSelect = form.querySelector('[data-reopen-mode]');

        if (modeSelect) {
            modeSelect.addEventListener('change', function () {
                const partial = modeSelect.value === MODE_PARTIAL_DAY;
                const stale = partial ? [startInput, endInput] : [projectDateInput];

                stale.forEach(function (input) {
                    if (input && input._flatpickr) {
                        input._flatpickr.clear();
                    }
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-reopen-form]').forEach(initForm);
    });
})(window);
