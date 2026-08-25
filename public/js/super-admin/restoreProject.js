/**
 * Restoring an archived project, and the Schedule Conflict dialog that opens
 * when its schedule cannot come back as it stands.
 *
 * An archived project keeps its dates but stops occupying anybody, which is
 * the point of archiving - the crew is free to be booked elsewhere. Restoring
 * puts those dates back into force, so the calendar may have moved underneath
 * them in the meantime.
 *
 * What that refusal is about is a SCHEDULE RANGE. A project's schedule is a
 * handful of ranges - "Aug 24-26", "Sep 6-8" - and one of them is in the way;
 * so the dialog shows the schedule as those ranges, marks the one that
 * clashes, and edits that. It does not list loose dates, and it is not
 * organised around technicians: who is unavailable is the answer to "why",
 * which the range carries as supporting detail.
 *
 * Ranges that have entirely ended are shown and nothing more. They are the
 * record of work that happened, they are not coming back into force, and the
 * server neither screens nor accepts a change to one.
 *
 * The resolution moves THIS project's range. The live work it clashed with is
 * somebody's week already in motion, and a restore is no reason to rewrite it.
 *
 * Everything here is a convenience. The restore endpoint re-runs the whole
 * check on the way in, against availability at that moment rather than when
 * this dialog opened, so a Restore button this enables can still be refused -
 * and if it is, the dialog redraws with what is in the way now.
 */
(function (global) {
    'use strict';

    const MODE_PARTIAL_DAY = 'partial_day';

    const token = document.querySelector('meta[name="csrf-token"]');
    const CSRF = token ? token.getAttribute('content') : '';

    // What is being restored, and what the server last said about it. Held for
    // the page rather than per row: only one restore can be refused at a time,
    // because a refusal is what opens the dialog.
    const state = {
        restoreUrl: null,
        conflictsUrl: null,
        report: null,
        busy: false,
    };

    let conflictModal = null;

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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
     * Inclusive list of 'YYYY-MM-DD' between two dates, in whichever order
     * they arrived - a person picks an end before a start as often as the
     * other way round.
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

    function request(url, options) {
        const settings = Object.assign({
            headers: {},
            credentials: 'same-origin',
        }, options || {});

        settings.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': CSRF,
        }, settings.headers);

        return fetch(url, settings).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (payload) {
                    return { ok: response.ok, status: response.status, payload: payload };
                });
        });
    }

    function form(body) {
        return {
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams(body).toString(),
        };
    }

    function messageFrom(payload, fallback) {
        if (!payload) {
            return fallback;
        }

        if (payload.error) {
            return payload.error;
        }

        // A Laravel validation failure, which is what an impossible range
        // comes back as.
        if (payload.errors) {
            const first = Object.keys(payload.errors)[0];

            if (first && Array.isArray(payload.errors[first]) && payload.errors[first][0]) {
                return payload.errors[first][0];
            }
        }

        return payload.message || fallback;
    }

    // ------------------------------------------------------------------
    // Restore
    // ------------------------------------------------------------------

    function restoreError(element, message) {
        const box = element.closest('.modal-content')
            ? element.closest('.modal-content').querySelector('[data-restore-error]')
            : null;

        if (!box) {
            return;
        }

        box.textContent = message;
        box.classList.remove('d-none');
    }

    function clearRestoreError(element) {
        const content = element.closest('.modal-content');
        const box = content ? content.querySelector('[data-restore-error]') : null;

        if (box) {
            box.classList.add('d-none');
            box.textContent = '';
        }
    }

    function submitRestore(restoreForm, button) {
        if (state.busy) {
            return;
        }

        state.busy = true;

        if (button) {
            button.disabled = true;
        }

        clearRestoreError(restoreForm);

        state.restoreUrl = restoreForm.getAttribute('action');
        state.conflictsUrl = restoreForm.dataset.conflictsUrl || null;

        request(state.restoreUrl, Object.assign({ method: 'POST' }, form({ _method: 'PUT' })))
            .then(function (result) {
                state.busy = false;

                if (button) {
                    button.disabled = false;
                }

                if (result.ok) {
                    // The success toast belongs on the page the project is now
                    // on, which is the active Projects list.
                    global.location = result.payload.redirect || global.location.href;

                    return;
                }

                // 409 is the calendar refusing, and the only refusal that
                // carries enough with it to be worth a dialog.
                if (result.status === 409 && result.payload.conflicts) {
                    const dialog = restoreForm.closest('.modal');

                    if (dialog && global.bootstrap) {
                        const instance = global.bootstrap.Modal.getInstance(dialog);

                        if (instance) {
                            instance.hide();
                        }
                    }

                    openConflicts(result.payload.conflicts);

                    return;
                }

                restoreError(restoreForm, messageFrom(result.payload, 'Unable to restore project. Nothing was changed.'));
            })
            .catch(function () {
                state.busy = false;

                if (button) {
                    button.disabled = false;
                }

                restoreError(restoreForm, 'Unable to reach the server. Nothing was changed.');
            });
    }

    // ------------------------------------------------------------------
    // The Schedule Conflict dialog
    // ------------------------------------------------------------------

    function modalElement() {
        return document.querySelector('[data-conflict-modal]');
    }

    function openConflicts(report) {
        const element = modalElement();

        if (!element || !global.bootstrap) {
            return;
        }

        state.report = report;
        render();

        if (!conflictModal) {
            conflictModal = new global.bootstrap.Modal(element);
        }

        conflictModal.show();
    }

    function feedback(kind, message) {
        const box = modalElement().querySelector('[data-conflict-feedback]');

        box.className = 'alert alert-' + kind;
        box.textContent = message;
        box.classList.remove('d-none');
    }

    function clearFeedback() {
        const box = modalElement().querySelector('[data-conflict-feedback]');

        box.classList.add('d-none');
        box.textContent = '';
    }

    function rangeFor(scheduleId) {
        return ((state.report && state.report.ranges) || []).find(function (range) {
            return String(range.schedule_id) === String(scheduleId);
        }) || null;
    }

    function projectPanel(project) {
        return ''
            + '<div class="conflict-panel">'
            + '<span class="conflict-panel-title">Restoring Project</span>'
            + '<div class="conflict-project-ref">' + escapeHtml(project.reference_no) + '</div>'
            + '<div class="conflict-panel-line text-secondary">Returns as '
            + escapeHtml(project.returns_as) + ', with its original team'
            + (project.team && project.team.length ? ' (' + escapeHtml(project.team.join(', ')) + ')' : '')
            + '.</div>'
            + '</div>';
    }

    /** Why a range is refused - the answer to "why", not the subject. */
    function conflictDetail(conflict) {
        const lines = (conflict.details || []).map(function (detail) {
            return '<li>' + escapeHtml(detail.technician_name)
                + ' is unavailable on ' + escapeHtml(detail.dates_label)
                + (detail.projects && detail.projects.length
                    ? ' (booked on ' + escapeHtml(detail.projects.join(', ')) + ')'
                    : '')
                + '.</li>';
        }).join('');

        return ''
            + '<div class="conflict-why">'
            + '<p class="conflict-why-summary">' + escapeHtml(conflict.summary) + '</p>'
            + '<details class="conflict-why-more">'
            + '<summary>View conflict details</summary>'
            + '<ul>' + lines + '</ul>'
            + '</details>'
            + '</div>';
    }

    function rangeEditor(range) {
        // A range in CONFLICT is sitting on days that are taken, and that is
        // what makes pre-filling it useless: a whole-day range needs every day
        // it covers free, so every candidate start - measured against the end
        // still in the other field - runs over one of the busy days, and the
        // entire calendar greys out. The dates it holds are the problem, so
        // the editor starts empty and the range is picked afresh. What it
        // holds today is on the row above, and repeated here.
        const fresh = range.state === 'conflict';

        const value = function (stored) {
            return fresh ? '' : escapeHtml(stored);
        };

        const placeholder = function (text) {
            return fresh ? ' placeholder="' + text + '"' : '';
        };

        const fields = range.partial_day
            ? ''
                + '<label class="conflict-field">'
                + '<span>Date</span>'
                + '<input type="text" class="form-control form-control-sm" data-range-date'
                + ' value="' + value(range.project_date) + '"' + placeholder('Select a date')
                + (range.start_frozen ? ' readonly' : '') + '>'
                + '</label>'
                + '<label class="conflict-field">'
                + '<span>Start</span>'
                + '<input type="time" class="form-control form-control-sm" data-range-start-time'
                + ' value="' + escapeHtml(range.start_time) + '">'
                + '</label>'
                + '<label class="conflict-field">'
                + '<span>End</span>'
                + '<input type="time" class="form-control form-control-sm" data-range-end-time'
                + ' value="' + escapeHtml(range.end_time) + '">'
                + '</label>'
            : ''
                + '<label class="conflict-field">'
                + '<span>Start date</span>'
                + '<input type="text" class="form-control form-control-sm" data-range-start'
                + ' value="' + value(range.start_date) + '"' + placeholder('Select a start date')
                + (range.start_frozen ? ' readonly' : '') + '>'
                + '</label>'
                + '<label class="conflict-field">'
                + '<span>End date</span>'
                + '<input type="text" class="form-control form-control-sm" data-range-end'
                + ' value="' + value(range.end_date) + '"' + placeholder('Select an end date') + '>'
                + '</label>';

        return ''
            + '<div class="conflict-editor d-none" data-range-editor>'
            + '<p class="conflict-editor-note">'
            + (fresh
                ? 'Pick a new period for this range. Currently <strong>'
                    + escapeHtml(range.label) + '</strong>. '
                : 'Move this range to a period the whole team is free for. ')
            + 'Past days, days the team is already booked on, and days this project’s other '
            + 'ranges hold are greyed out.'
            + '</p>'
            + (range.start_frozen
                ? '<p class="conflict-editor-note">This range is under way, so its start is fixed.</p>'
                : '')
            + '<div class="conflict-fields">' + fields + '</div>'
            + '<div class="conflict-editor-actions">'
            + '<button type="button" class="btn btn-sm btn-light" data-range-cancel>Cancel</button>'
            + '<button type="button" class="btn btn-sm btn-primary" data-range-save>Save range</button>'
            + '</div>'
            + '</div>';
    }

    function rangeCard(range) {
        const badge = range.state === 'conflict'
            ? '<span class="range-badge is-conflict">🔴 Schedule Conflict</span>'
            : (range.state === 'past'
                ? '<span class="range-badge is-past">Past</span><span class="range-readonly">Read-only</span>'
                : '<span class="range-badge is-available">Available</span>');

        const actions = [];

        if (range.editable) {
            actions.push('<button type="button" class="btn btn-sm btn-outline-primary" data-range-edit>'
                + '<i class="bi bi-calendar-event me-1"></i>Edit Schedule</button>');
        }

        if (range.removable) {
            actions.push('<button type="button" class="btn btn-sm btn-outline-danger" data-range-remove>'
                + '<i class="bi bi-x-circle me-1"></i>Remove range</button>');
        }

        return ''
            + '<div class="range-row is-' + escapeHtml(range.state) + '" data-range-row'
            + ' data-schedule-id="' + escapeHtml(range.schedule_id) + '">'
            + '<div class="range-head">'
            + '<span class="range-label">' + escapeHtml(range.label) + '</span>'
            + '<span class="range-state">' + badge + '</span>'
            + '</div>'
            + (range.conflict ? conflictDetail(range.conflict) : '')
            + (actions.length ? '<div class="range-actions">' + actions.join('') + '</div>' : '')
            + (range.editable ? rangeEditor(range) : '')
            + '</div>';
    }

    function render() {
        const element = modalElement();
        const report = state.report;

        if (!element || !report) {
            return;
        }

        element.querySelector('[data-conflict-restoring]').innerHTML = projectPanel(report.project);

        const list = element.querySelector('[data-conflict-list]');

        list.innerHTML = report.ranges.length
            ? '<div class="range-list-title">Project Schedule</div>'
                + report.ranges.map(rangeCard).join('')
            : '<div class="range-empty">This project holds no schedule ranges.</div>';

        const summary = element.querySelector('[data-conflict-summary]');

        summary.className = report.blocked ? 'alert alert-danger' : 'alert alert-success';
        summary.textContent = report.blocked
            ? 'This project’s schedule conflicts with the current availability of its team. '
                + 'Review the affected schedule ranges before restoring the project.'
            : 'Every current and future schedule range is available. This project can be restored.';

        element.querySelector('[data-conflict-restore]').disabled = Boolean(report.blocked);

        const checked = element.querySelector('[data-conflict-checked]');
        const at = report.checked_at ? new Date(report.checked_at) : null;

        checked.textContent = at && !Number.isNaN(at.getTime())
            ? 'Availability last checked ' + at.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
            })
            : '';

        attachPickers(list);
    }

    // ------------------------------------------------------------------
    // Date pickers, greying out what the save would refuse
    // ------------------------------------------------------------------

    function attachPickers(list) {
        if (!global.flatpickr) {
            return;
        }

        list.querySelectorAll('[data-range-row]').forEach(function (row) {
            const range = rangeFor(row.dataset.scheduleId);

            if (!range || !range.editable) {
                return;
            }

            initRow(row, range);
        });
    }

    function initRow(row, range) {
        const wholeDay = {};
        const partialDay = {};

        (range.blocked_dates.whole_day || []).forEach(function (date) {
            wholeDay[date] = true;
        });

        (range.blocked_dates.partial_day || []).forEach(function (date) {
            partialDay[date] = true;
        });

        const startInput = row.querySelector('[data-range-start]');
        const endInput = row.querySelector('[data-range-end]');
        const dateInput = row.querySelector('[data-range-date]');

        // Never earlier than today: a restored project's schedule is work
        // still to come, and the server refuses a past date whatever a picker
        // offered.
        const earliestStart = range.earliest_start || null;
        const earliestEnd = range.earliest_end || null;

        // A whole-day range needs EVERY day it covers free, so a date is
        // refused on its own account and equally when the range it would make
        // with the other field runs over a day that is taken.
        function blocked(date, otherValue) {
            const dateString = toDateString(date);

            if (!dateString) {
                return false;
            }

            if (wholeDay[dateString]) {
                return true;
            }

            if (!otherValue) {
                return false;
            }

            return eachDate(dateString, otherValue).some(function (day) {
                return Boolean(wholeDay[day]);
            });
        }

        if (startInput && endInput) {
            let startPicker = null;

            const endPicker = global.flatpickr(endInput, {
                dateFormat: 'Y-m-d',
                allowInput: false,
                minDate: startInput.value || earliestEnd,
                disable: [function (date) {
                    return blocked(date, startInput.value);
                }],
                onReady: function (selected, dateString, instance) {
                    attachClearButton(instance);
                },
                onChange: function () {
                    if (startPicker) {
                        startPicker.set('maxDate', endInput.value || null);
                        startPicker.redraw();
                    }
                },
            });

            if (!range.start_frozen) {
                startPicker = global.flatpickr(startInput, {
                    dateFormat: 'Y-m-d',
                    allowInput: false,
                    minDate: earliestStart,
                    maxDate: endInput.value || null,
                    disable: [function (date) {
                        return blocked(date, endInput.value);
                    }],
                    onReady: function (selected, dateString, instance) {
                        attachClearButton(instance);
                    },
                    onChange: function (selected, dateString) {
                        // An end left behind the new start is not an end. Given
                        // up rather than argued with, so the next click picks
                        // one that makes sense with the start just chosen.
                        if (dateString && endInput.value && endInput.value < dateString) {
                            endPicker.clear(false);
                            startPicker.set('maxDate', null);
                        }

                        endPicker.set('minDate', dateString || earliestEnd);
                        endPicker.redraw();
                    },
                });
            }
        }

        // An hours-only booking only needs one free hour of its single date.
        if (dateInput && !range.start_frozen) {
            global.flatpickr(dateInput, {
                dateFormat: 'Y-m-d',
                allowInput: false,
                minDate: earliestStart,
                disable: [function (date) {
                    const dateString = toDateString(date);

                    return Boolean(dateString && partialDay[dateString]);
                }],
                onReady: function (selected, dateString, instance) {
                    attachClearButton(instance);
                },
            });
        }
    }

    /**
     * The escape hatch every date picker on the schedules page has, for the
     * same reason: each end of a range is judged against whatever the other
     * end holds, so a range pinned between its own end and a busy day can only
     * be moved once one of the two is let go.
     */
    function attachClearButton(instance) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'conflict-picker-clear';
        button.textContent = 'Clear';

        button.addEventListener('click', function () {
            instance.clear();
            instance.close();
        });

        instance.calendarContainer.appendChild(button);
    }

    // ------------------------------------------------------------------
    // Resolving - always this project's own range
    // ------------------------------------------------------------------

    function saveRange(range, row, button) {
        if (state.busy) {
            return;
        }

        const read = function (selector) {
            const field = row.querySelector(selector);

            return field && field.value ? field.value : '';
        };

        const body = {
            _method: 'PUT',
            action: 'update',
            schedule_id: range.schedule_id,
            scheduling_mode: range.scheduling_mode,
        };

        if (range.scheduling_mode === MODE_PARTIAL_DAY) {
            body.project_date = read('[data-range-date]');
            body.start_time = read('[data-range-start-time]');
            body.end_time = read('[data-range-end-time]');
        } else {
            body.start_date = read('[data-range-start]');
            body.end_date = read('[data-range-end]');
        }

        // A conflicting range is edited from empty, so a half-filled form is a
        // real state. Falling back to the dates it already holds would send
        // the very range that is in the way and report it, correctly and
        // uselessly, as still in conflict.
        const missing = Object.keys(body).some(function (key) {
            return key !== '_method' && body[key] === '';
        });

        if (missing) {
            feedback('warning', range.partial_day
                ? 'Pick a date and the hours for this range.'
                : 'Pick a start date and an end date for this range.');

            return;
        }

        send(button, form(body), 'Schedule range updated.');
    }

    function removeRange(range, button) {
        if (state.busy) {
            return;
        }

        send(
            button,
            form({ _method: 'PUT', action: 'remove', schedule_id: range.schedule_id }),
            'Schedule range removed.'
        );
    }

    /**
     * Every change goes the same way: to this project's schedule, and back as
     * the WHOLE schedule screened afresh. An edit can resolve one range and
     * walk another into trouble, so nothing here assumes a save cleared
     * anything.
     */
    function send(button, options, note) {
        state.busy = true;
        button.disabled = true;
        clearFeedback();

        request(state.report.project.update_url, Object.assign({ method: 'POST' }, options))
            .then(function (result) {
                state.busy = false;
                button.disabled = false;

                if (!result.ok) {
                    feedback('danger', messageFrom(result.payload, 'Unable to change that schedule range.'));

                    return;
                }

                state.report = result.payload;
                render();

                feedback(
                    state.report.blocked ? 'warning' : 'success',
                    note + (state.report.blocked
                        ? ' There are still schedule conflicts to resolve.'
                        : ' No conflicts remain - this project can be restored.')
                );
            })
            .catch(function () {
                state.busy = false;
                button.disabled = false;
                feedback('danger', 'Unable to reach the server. Nothing was changed.');
            });
    }

    /**
     * Ask the server what the schedule looks like now.
     *
     * Availability changes while this dialog is open - somebody else may book
     * the same person - so nothing here is decided from what was loaded when
     * it opened. The restore asks again regardless; this is so the dialog does
     * not offer a button that is going to bounce.
     */
    function recheck(note) {
        if (!state.conflictsUrl) {
            return;
        }

        const button = modalElement().querySelector('[data-conflict-recheck]');

        button.disabled = true;

        request(state.conflictsUrl, { method: 'GET' })
            .then(function (result) {
                button.disabled = false;

                if (!result.ok) {
                    feedback('danger', messageFrom(result.payload, 'Unable to recheck availability.'));

                    return;
                }

                state.report = result.payload;
                render();

                feedback(
                    state.report.blocked ? 'warning' : 'success',
                    (note ? note + ' ' : '') + (state.report.blocked
                        ? 'There are still schedule conflicts to resolve.'
                        : 'No conflicts remain - this project can be restored.')
                );
            })
            .catch(function () {
                button.disabled = false;
                feedback('danger', 'Unable to reach the server.');
            });
    }

    /**
     * Restore from inside the dialog, once every current and future range is
     * showing as available.
     *
     * Sent to the same endpoint the confirmation dialog sends to, which checks
     * the whole schedule again - so a clash that appeared in the last few
     * seconds redraws this dialog rather than slipping through.
     */
    function restoreFromDialog(button) {
        if (state.busy || !state.restoreUrl) {
            return;
        }

        state.busy = true;
        button.disabled = true;
        clearFeedback();

        request(state.restoreUrl, Object.assign({ method: 'POST' }, form({ _method: 'PUT' })))
            .then(function (result) {
                state.busy = false;

                if (result.ok) {
                    global.location = result.payload.redirect || global.location.href;

                    return;
                }

                button.disabled = false;

                if (result.status === 409 && result.payload.conflicts) {
                    state.report = result.payload.conflicts;
                    render();
                    feedback('danger', 'The calendar changed while this was open. The schedule below is current.');

                    return;
                }

                feedback('danger', messageFrom(result.payload, 'Unable to restore project. Nothing was changed.'));
            })
            .catch(function () {
                state.busy = false;
                button.disabled = false;
                feedback('danger', 'Unable to reach the server. Nothing was changed.');
            });
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        // Delegated rather than bound per form: the confirmation dialogs are
        // drawn per row, and a listener attached to a node that is not there
        // yet is no listener at all.
        document.addEventListener('submit', function (event) {
            const restoreForm = event.target.closest('[data-restore-form]');

            if (!restoreForm) {
                return;
            }

            event.preventDefault();
            submitRestore(restoreForm, restoreForm.querySelector('[data-restore-submit]'));
        });

        const element = modalElement();

        if (!element) {
            return;
        }

        element.addEventListener('click', function (event) {
            const row = event.target.closest('[data-range-row]');
            const range = row ? rangeFor(row.dataset.scheduleId) : null;

            if (event.target.closest('[data-range-edit]') && row) {
                row.querySelector('[data-range-editor]').classList.remove('d-none');

                return;
            }

            if (event.target.closest('[data-range-cancel]') && row) {
                row.querySelector('[data-range-editor]').classList.add('d-none');

                return;
            }

            const save = event.target.closest('[data-range-save]');

            if (save && range) {
                saveRange(range, row, save);

                return;
            }

            const remove = event.target.closest('[data-range-remove]');

            if (remove && range) {
                removeRange(range, remove);

                return;
            }

            if (event.target.closest('[data-conflict-recheck]')) {
                clearFeedback();
                recheck(null);

                return;
            }

            const restore = event.target.closest('[data-conflict-restore]');

            if (restore) {
                restoreFromDialog(restore);
            }
        });
    });
})(window);
