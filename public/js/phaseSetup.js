/**
 * The phase setup editor.
 *
 * Rows are held in an array and redrawn from it, rather than the DOM being the
 * source of truth: reordering has to renumber every row, and a phase's number
 * is the thing tasks are filed under, so "what is Phase 3" needs one answer
 * rather than one per element. The array is written into hidden inputs named
 * phases[N][...] on submit, which is the shape ProjectPhaseController expects.
 *
 * Each phase carries its own list of tasks, drawn nested inside it. Those are
 * DRAFT tasks - they become real tasks only when the structure is finalized -
 * and they are deliberately not the same thing as row.task_count, which counts
 * work already filed against a phase and is only ever non-zero on a project a
 * Super Admin has unlocked. Removing a phase asks where its work goes on the
 * strength of task_count; its draft tasks simply go with it, which is what the
 * person deleting the row can see happening.
 *
 * A technician and dates are optional on every task here. That is the whole
 * difference between this screen and the task board: the job is to write the
 * work down while somebody is thinking about the shape of the project, not to
 * staff it.
 *
 * Shared by both portals - the same screen serves Super Admin, Admin and Lead
 * Technician.
 */
document.addEventListener("DOMContentLoaded", function () {
    const form = document.querySelector("[data-phase-setup-form]");

    if (!form) {
        return;
    }

    const container = form.querySelector("[data-phase-rows]");
    const addButton = form.querySelector("[data-phase-add]");
    const saveButton = form.querySelector("[data-phase-save]");
    const finalizeButton = form.querySelector("[data-phase-finalize]");
    const countBadge = form.querySelector("[data-phase-count-badge]");
    const summary = form.querySelector("[data-phase-summary]");
    const errorEl = form.querySelector("[data-phase-error]");

    const minPhases = parseInt(form.dataset.minPhases || "1", 10);
    const maxPhases = parseInt(form.dataset.maxPhases || "20", 10);
    const maxTasks = parseInt(form.dataset.maxTasks || "50", 10);

    const technicians = readJson(form.dataset.technicians, []);

    // Every range the project is booked for. Only those days can be picked:
    // taskDatePickers greys out everything else, gaps between visits included,
    // and keeps each task's end from falling before its start. That is the
    // guidance the old "dates have to fall between..." label gave, enforced in
    // the calendar instead of described above it. The server holds a task to
    // the same rule on the way in.
    const scheduleRanges = readJson(form.dataset.scheduleRanges, []);
    const hasSchedule = scheduleRanges.length > 0;

    /**
     * How many colours the phases cycle through. Each phase takes the colour of
     * its position, the same way it takes the number of its position - "the
     * green one" and "Phase 2" are the same answer, and both follow a reorder.
     */
    const PHASE_COLOURS = 8;

    function colourClass(index) {
        return "phase-colour-" + ((index % PHASE_COLOURS) + 1);
    }

    const reassignModalEl = document.getElementById("reassignPhaseTasksModal");
    const reassignTarget = reassignModalEl.querySelector(
        "[data-reassign-target]",
    );
    const reassignMessage = reassignModalEl.querySelector(
        "[data-reassign-message]",
    );
    const reassignConfirm = reassignModalEl.querySelector(
        "[data-reassign-confirm]",
    );

    /**
     * phase_id => the phase its tasks move to. Only ever filled for a removal
     * the Super Admin has resolved; the server refuses any it has not.
     */
    const reassignments = {};

    let rows = readInitialRows();
    let pendingRemoval = null;

    function readJson(value, fallback) {
        try {
            const parsed = JSON.parse(value || "");

            return parsed === null ? fallback : parsed;
        } catch (error) {
            return fallback;
        }
    }

    function readInitialRows() {
        const holder = document.querySelector("[data-phase-initial]");

        // old() comes back keyed by index as an object when a row was removed
        // before the refused submit, so normalise it - and the same for the
        // task lists nested inside each row.
        return Object.values(readJson(holder.dataset.phaseInitial, [])).map(
            function (row) {
                return {
                    phase_id: row.phase_id ? parseInt(row.phase_id, 10) : null,
                    stage_id: row.stage_id ? parseInt(row.stage_id, 10) : null,
                    title: row.title || "",
                    description: row.description || "",
                    sources: Array.isArray(row.sources) ? row.sources : [],
                    // Real tasks already filed here. Not the drafts below.
                    task_count: parseInt(row.task_count || 0, 10),
                    tasks: Object.values(row.tasks || {}).map(readTask),
                    // Screen state only - never submitted. A long structure is
                    // easier to reorder with its task lists folded away.
                    collapsed: false,
                };
            },
        );
    }

    function readTask(task) {
        return {
            title: task.title || "",
            description: task.description || "",
            technician_id: task.technician_id
                ? parseInt(task.technician_id, 10)
                : "",
            start_date: task.start_date || "",
            due_date: task.due_date || "",
            sources: Array.isArray(task.sources) ? task.sources : [],
        };
    }

    function emptyTask() {
        return {
            title: "",
            description: "",
            technician_id: "",
            start_date: "",
            due_date: "",
            sources: [],
        };
    }

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

    function setError(message) {
        errorEl.textContent = message || "";
        errorEl.classList.toggle("d-none", !message);
    }

    /**
     * Which project types wanted this row. Only ever present on a suggestion
     * straight from the templates, and the reason it is worth drawing: on a
     * project that is two types, "Installation" carries both types' work and
     * the person deleting a line should be able to see whose it was.
     */
    function chips(sources) {
        if (!sources || !sources.length) {
            return "";
        }

        return (
            '<span class="phase-source-chips">' +
            sources
                .map(function (source) {
                    return (
                        '<span class="phase-source-chip">' +
                        escapeHtml(source) +
                        "</span>"
                    );
                })
                .join("") +
            "</span>"
        );
    }

    function technicianOptions(selected) {
        return (
            '<option value="">Unassigned</option>' +
            technicians
                .map(function (technician) {
                    const id = technician.technician_id;

                    return (
                        '<option value="' +
                        id +
                        '"' +
                        (String(id) === String(selected) ? " selected" : "") +
                        ">" +
                        escapeHtml(technician.name) +
                        "</option>"
                    );
                })
                .join("")
        );
    }

    function taskMarkup(task, phaseIndex, taskIndex) {
        const label = "Phase " + (phaseIndex + 1) + " task " + (taskIndex + 1);
        // Plain text inputs, turned into pickers once they are on the page -
        // see bindDatePickers(). data-date-picker is the fallback: a field this
        // screen does not bind (no schedule) still becomes the shared picker, so
        // it looks like every other date field while it sits disabled.
        const dateAttrs =
            " data-date-picker" +
            (hasSchedule
                ? ""
                : ' disabled title="This project has no schedule yet, so tasks cannot be given dates."');

        return (
            '<div class="phase-task" data-task-row="' +
            taskIndex +
            '">' +
            '<div class="phase-task-top">' +
            // "Task 2" rather than a bare number: in a list of nearly identical
            // boxes, the label is what tells one task from the next.
            '<span class="phase-task-label">Task ' +
            (taskIndex + 1) +
            "</span>" +
            chips(task.sources) +
            '<button type="button" class="phase-task-remove" data-task-remove' +
            ' aria-label="Remove ' +
            label +
            '" title="Remove task"><i class="bi bi-x-lg"></i></button>' +
            "</div>" +
            '<input type="text" class="form-control phase-task-title" maxlength="255"' +
            ' placeholder="What needs doing?" aria-label="' +
            label +
            ' title" data-task-title value="' +
            escapeHtml(task.title) +
            '">' +
            '<textarea class="form-control form-control-sm mt-2" rows="2"' +
            ' placeholder="Details for whoever does it" aria-label="' +
            label +
            ' description" data-task-description>' +
            escapeHtml(task.description) +
            "</textarea>" +
            '<div class="phase-task-meta">' +
            '<label class="phase-task-field phase-task-field-wide">' +
            '<span class="phase-task-field-label"><i class="bi bi-person"></i>Technician</span>' +
            '<select class="form-select form-select-sm" aria-label="' +
            label +
            ' technician" data-task-technician>' +
            technicianOptions(task.technician_id) +
            "</select>" +
            "</label>" +
            '<label class="phase-task-field">' +
            '<span class="phase-task-field-label"><i class="bi bi-calendar-event"></i>Start</span>' +
            '<input type="text" class="form-control form-control-sm" aria-label="' +
            label +
            ' start date" data-task-start value="' +
            escapeHtml(task.start_date) +
            '"' +
            dateAttrs +
            ">" +
            "</label>" +
            '<label class="phase-task-field">' +
            '<span class="phase-task-field-label"><i class="bi bi-calendar-check"></i>End</span>' +
            '<input type="text" class="form-control form-control-sm" aria-label="' +
            label +
            ' end date" data-task-due value="' +
            escapeHtml(task.due_date) +
            '"' +
            dateAttrs +
            ">" +
            "</label>" +
            "</div>" +
            "</div>"
        );
    }

    function tasksMarkup(row, index) {
        const drafts = row.tasks
            .map(function (task, taskIndex) {
                return taskMarkup(task, index, taskIndex);
            })
            .join("");

        // Work already filed against this phase, which this screen does not
        // edit. Said plainly so a person does not go looking for it among the
        // rows below.
        const existing = row.task_count
            ? '<p class="phase-task-existing">' +
              '<i class="bi bi-list-check me-1"></i>' +
              plural(row.task_count, "task") +
              " already on this phase, managed on the task board." +
              "</p>"
            : "";

        const empty =
            !row.tasks.length && !row.task_count
                ? '<p class="phase-tasks-empty">No tasks yet.</p>'
                : "";

        return (
            '<div class="phase-tasks"' +
            (row.collapsed ? " hidden" : "") +
            ">" +
            existing +
            empty +
            drafts +
            '<button type="button" class="phase-add-task" data-task-add' +
            (row.tasks.length >= maxTasks ? " disabled" : "") +
            '><i class="bi bi-plus-lg"></i>Add task to Phase ' +
            (index + 1) +
            "</button>" +
            "</div>"
        );
    }

    function plural(count, word) {
        return count + " " + word + (count === 1 ? "" : "s");
    }

    function render() {
        container.innerHTML = rows
            .map(function (row, index) {
                const number = index + 1;
                const count = row.tasks.length;

                return (
                    '<section class="phase-card ' +
                    colourClass(index) +
                    (row.collapsed ? " is-collapsed" : "") +
                    '" data-phase-row="' +
                    index +
                    '" aria-label="Phase ' +
                    number +
                    '">' +
                    '<header class="phase-card-head">' +
                    '<span class="phase-card-handle" draggable="true" title="Drag to reorder" aria-hidden="true">' +
                    '<i class="bi bi-grip-vertical"></i>' +
                    "</span>" +
                    '<span class="phase-card-number" aria-hidden="true">' +
                    number +
                    "</span>" +
                    '<div class="phase-card-heading">' +
                    '<span class="phase-card-kicker">Phase ' +
                    number +
                    "</span>" +
                    '<span class="phase-card-count">' +
                    plural(count, "task") +
                    "</span>" +
                    chips(row.sources) +
                    "</div>" +
                    '<div class="phase-card-actions">' +
                    '<button type="button" class="phase-icon" data-phase-move="up"' +
                    ' aria-label="Move phase ' +
                    number +
                    ' up" title="Move up"' +
                    (index === 0 ? " disabled" : "") +
                    '><i class="bi bi-arrow-up"></i></button>' +
                    '<button type="button" class="phase-icon" data-phase-move="down"' +
                    ' aria-label="Move phase ' +
                    number +
                    ' down" title="Move down"' +
                    (index === rows.length - 1 ? " disabled" : "") +
                    '><i class="bi bi-arrow-down"></i></button>' +
                    '<button type="button" class="phase-icon phase-icon-danger" data-phase-remove' +
                    ' aria-label="Remove phase ' +
                    number +
                    '" title="Remove phase"' +
                    (rows.length <= minPhases ? " disabled" : "") +
                    '><i class="bi bi-trash"></i></button>' +
                    '<button type="button" class="phase-icon" data-phase-collapse' +
                    ' aria-expanded="' +
                    (row.collapsed ? "false" : "true") +
                    '" aria-label="' +
                    (row.collapsed ? "Show" : "Hide") +
                    " phase " +
                    number +
                    ' tasks" title="' +
                    (row.collapsed ? "Show tasks" : "Hide tasks") +
                    '"><i class="bi bi-chevron-' +
                    (row.collapsed ? "down" : "up") +
                    '"></i></button>' +
                    "</div>" +
                    "</header>" +
                    '<div class="phase-card-fields">' +
                    '<input type="text" class="form-control phase-card-title" maxlength="150"' +
                    ' placeholder="Phase title, e.g. Site Preparation"' +
                    ' aria-label="Phase ' +
                    number +
                    ' title" data-phase-title value="' +
                    escapeHtml(row.title) +
                    '">' +
                    // One line, because one sentence is what it is for: the
                    // monitoring card prints it on a single line under the title.
                    '<input type="text" class="form-control form-control-sm mt-2" maxlength="500"' +
                    ' placeholder="One sentence on what happens in this phase"' +
                    ' aria-label="Phase ' +
                    number +
                    ' description" data-phase-description value="' +
                    escapeHtml(row.description) +
                    '">' +
                    "</div>" +
                    tasksMarkup(row, index) +
                    "</section>"
                );
            })
            .join("");

        const total = taskTotal();
        const unassigned = rows.reduce(function (sum, row) {
            return (
                sum +
                row.tasks.filter(function (task) {
                    return !isBlank(task) && !task.technician_id;
                }).length
            );
        }, 0);

        countBadge.innerHTML =
            '<span class="phase-summary-pill"><i class="bi bi-diagram-3"></i>' +
            plural(rows.length, "phase") +
            "</span>" +
            '<span class="phase-summary-pill"><i class="bi bi-check2-square"></i>' +
            plural(total, "task") +
            "</span>" +
            (unassigned
                ? '<span class="phase-summary-pill phase-summary-warn"><i class="bi bi-person-dash"></i>' +
                  unassigned +
                  " unassigned</span>"
                : "");

        addButton.disabled = rows.length >= maxPhases;

        bindDatePickers();
    }

    /**
     * Turn every task's start and end into the booked-days-only pickers.
     *
     * Run after every render, because a render replaces the rows wholesale.
     * The pickers the previous render made go with the fields they belonged
     * to - datePicker.js releases a calendar when its field leaves the page.
     */
    function bindDatePickers() {
        if (!hasSchedule || !window.taskDatePickers) {
            return;
        }

        container.querySelectorAll("[data-task-row]").forEach(function (taskEl) {
            window.taskDatePickers.applyScheduleRanges(
                taskEl.querySelector("[data-task-start]"),
                taskEl.querySelector("[data-task-due]"),
                scheduleRanges,
            );
        });
    }

    function taskTotal() {
        return rows.reduce(function (total, row) {
            return total + row.tasks.length;
        }, 0);
    }

    /**
     * Copy what is currently typed back into the array, so a reorder or a
     * removal does not throw away edits made since the last render.
     */
    function syncFromInputs() {
        container
            .querySelectorAll("[data-phase-row]")
            .forEach(function (element) {
                const index = parseInt(element.dataset.phaseRow, 10);

                if (!rows[index]) {
                    return;
                }

                rows[index].title =
                    element.querySelector("[data-phase-title]").value;
                rows[index].description = element.querySelector(
                    "[data-phase-description]",
                ).value;

                element
                    .querySelectorAll("[data-task-row]")
                    .forEach(function (taskEl) {
                        const taskIndex = parseInt(taskEl.dataset.taskRow, 10);
                        const task = rows[index].tasks[taskIndex];

                        if (!task) {
                            return;
                        }

                        task.title =
                            taskEl.querySelector("[data-task-title]").value;
                        task.description = taskEl.querySelector(
                            "[data-task-description]",
                        ).value;
                        task.technician_id = taskEl.querySelector(
                            "[data-task-technician]",
                        ).value;
                        task.start_date =
                            taskEl.querySelector("[data-task-start]").value;
                        task.due_date =
                            taskEl.querySelector("[data-task-due]").value;
                    });
            });
    }

    addButton.addEventListener("click", function () {
        syncFromInputs();

        if (rows.length >= maxPhases) {
            return;
        }

        rows.push({
            phase_id: null,
            stage_id: null,
            title: "",
            description: "",
            sources: [],
            task_count: 0,
            tasks: [],
            collapsed: false,
        });
        setError("");
        render();

        const last = container.querySelector(
            '[data-phase-row="' + (rows.length - 1) + '"] [data-phase-title]',
        );

        if (last) {
            last.focus();
        }
    });

    container.addEventListener("click", function (event) {
        const rowEl = event.target.closest("[data-phase-row]");

        if (!rowEl) {
            return;
        }

        const index = parseInt(rowEl.dataset.phaseRow, 10);

        // Folding a phase away hides its tasks, never discards them - they are
        // still in the array and still submitted.
        if (event.target.closest("[data-phase-collapse]")) {
            syncFromInputs();
            rows[index].collapsed = !rows[index].collapsed;
            render();

            return;
        }

        // A phase somebody added themselves takes tasks exactly as a suggested
        // one does - there is no second kind of phase here.
        if (event.target.closest("[data-task-add]")) {
            syncFromInputs();

            if (rows[index].tasks.length >= maxTasks) {
                setError(
                    "A phase can hold at most " + maxTasks + " tasks here.",
                );

                return;
            }

            rows[index].tasks.push(emptyTask());
            setError("");
            render();
            focusTask(index, rows[index].tasks.length - 1);

            return;
        }

        const removeTask = event.target.closest("[data-task-remove]");

        if (removeTask) {
            const taskEl = removeTask.closest("[data-task-row]");

            syncFromInputs();
            rows[index].tasks.splice(parseInt(taskEl.dataset.taskRow, 10), 1);
            setError("");
            render();

            return;
        }

        if (event.target.closest("[data-phase-move]")) {
            const direction =
                event.target.closest("[data-phase-move]").dataset.phaseMove;
            const target = direction === "up" ? index - 1 : index + 1;

            if (target < 0 || target >= rows.length) {
                return;
            }

            syncFromInputs();

            const moved = rows.splice(index, 1)[0];
            rows.splice(target, 0, moved);

            render();

            return;
        }

        if (event.target.closest("[data-phase-remove]")) {
            syncFromInputs();
            requestRemoval(index);
        }
    });

    function focusTask(phaseIndex, taskIndex) {
        const input = container.querySelector(
            '[data-phase-row="' +
                phaseIndex +
                '"] [data-task-row="' +
                taskIndex +
                '"] [data-task-title]',
        );

        if (input) {
            input.focus();
        }
    }

    /**
     * Removing a phase with REAL tasks on it is never a plain removal: the work
     * has to be told where to go first. Only reachable after a Super Admin
     * override - a project that has never been finalized has no real tasks, and
     * the draft tasks drawn under the row are not work, so they simply go with
     * it.
     */
    function requestRemoval(index) {
        const row = rows[index];

        if (rows.length <= minPhases) {
            setError("A project needs at least " + minPhases + " phase.");

            return;
        }

        if (!row.task_count) {
            rows.splice(index, 1);
            setError("");
            render();

            return;
        }

        const survivors = rows.filter(function (other, otherIndex) {
            return otherIndex !== index && other.phase_id;
        });

        if (!survivors.length) {
            setError(
                "There is no other saved phase to move this work to. Save the new phases first, then remove this one.",
            );

            return;
        }

        pendingRemoval = index;

        reassignMessage.textContent =
            "Phase " +
            (index + 1) +
            " has " +
            row.task_count +
            " task" +
            (row.task_count === 1 ? "" : "s") +
            " on it. Choose the phase they should move to.";

        reassignTarget.innerHTML = survivors
            .map(function (other) {
                return (
                    '<option value="' +
                    other.phase_id +
                    '">' +
                    escapeHtml(other.title || "Untitled phase") +
                    "</option>"
                );
            })
            .join("");

        bootstrap.Modal.getOrCreateInstance(reassignModalEl).show();
    }

    reassignConfirm.addEventListener("click", function () {
        if (pendingRemoval === null) {
            return;
        }

        const row = rows[pendingRemoval];

        reassignments[row.phase_id] = reassignTarget.value;

        rows.splice(pendingRemoval, 1);
        pendingRemoval = null;

        bootstrap.Modal.getOrCreateInstance(reassignModalEl).hide();
        setError("");
        render();
    });

    /**
     * Everything that is wrong with the structure as typed, or null.
     *
     * The task rules here are the browser's copy of PhaseSetupTaskRules, which
     * checks the same things again on the way in. This one exists to answer
     * without a round trip, not to be the rule.
     */
    /**
     * The first thing wrong with the structure as typed, and where it is - or
     * null.
     *
     * Returns the place as well as the message because a phase can be folded
     * away: an error inside a collapsed phase would otherwise be a sentence
     * about something nobody can see. The caller opens the phase and puts the
     * cursor in the field.
     *
     * The task rules here are the browser's copy of PhaseSetupTaskRules, which
     * checks the same things again on the way in. This one exists to answer
     * without a round trip, not to be the rule.
     */
    function validationError() {
        if (rows.length < minPhases) {
            return {
                message: "Add at least " + minPhases + " phase before continuing.",
            };
        }

        for (let index = 0; index < rows.length; index += 1) {
            const row = rows[index];

            if (!row.title.trim()) {
                return {
                    message: "Phase " + (index + 1) + " needs a title.",
                    phase: index,
                    field: "[data-phase-title]",
                };
            }

            if (!row.description.trim()) {
                return {
                    message: "Phase " + (index + 1) + " needs a one-sentence description.",
                    phase: index,
                    field: "[data-phase-description]",
                };
            }

            for (let taskIndex = 0; taskIndex < row.tasks.length; taskIndex++) {
                const problem = taskError(row.tasks[taskIndex], index, taskIndex);

                if (problem) {
                    return problem;
                }
            }
        }

        return null;
    }

    function taskError(task, phaseIndex, taskIndex) {
        const where = "Phase " + (phaseIndex + 1) + ", Task " + (taskIndex + 1);
        const at = function (message, field) {
            return {
                message: message,
                phase: phaseIndex,
                task: taskIndex,
                field: field,
            };
        };

        // Left entirely blank: somebody pressed Add Task and thought better of
        // it. Dropped on submit rather than complained about.
        if (isBlank(task)) {
            return null;
        }

        if (!task.title.trim()) {
            return at(where + " needs a title.", "[data-task-title]");
        }

        if (!task.description.trim()) {
            return at(where + " needs a description.", "[data-task-description]");
        }

        const hasStart = Boolean(task.start_date);
        const hasDue = Boolean(task.due_date);

        if (hasStart !== hasDue) {
            return at(
                where + " needs both a start date and an end date, or neither.",
                hasStart ? "[data-task-due]" : "[data-task-start]",
            );
        }

        if (hasStart && task.due_date < task.start_date) {
            return at(where + " cannot end before it starts.", "[data-task-due]");
        }

        return null;
    }

    /**
     * Say what is wrong, and take the person to it: open the phase if it was
     * folded away, then focus and mark the field.
     */
    function reportProblem(problem) {
        setError(problem.message);

        if (problem.phase === undefined) {
            errorEl.scrollIntoView({ block: "center", behavior: "smooth" });

            return;
        }

        if (rows[problem.phase].collapsed) {
            rows[problem.phase].collapsed = false;
            render();
        }

        let scope = container.querySelector(
            '[data-phase-row="' + problem.phase + '"]',
        );

        if (scope && problem.task !== undefined) {
            scope = scope.querySelector('[data-task-row="' + problem.task + '"]');
        }

        const field = scope ? scope.querySelector(problem.field) : null;

        if (!field) {
            return;
        }

        // A date field is two inputs: the hidden one holding the value, and
        // the picker's visible one. The mark goes on the real field (the
        // picker copies its classes across); the cursor and the scroll go to
        // the one the person can actually see.
        const visible = (field._flatpickr && field._flatpickr.altInput) || field;

        field.classList.add("is-invalid");
        visible.scrollIntoView({ block: "center", behavior: "smooth" });
        visible.focus({ preventScroll: true });

        // Cleared the moment they start fixing it - typing, or picking a date.
        const clear = function () {
            field.classList.remove("is-invalid");
            field.removeEventListener("input", clear);
            field.removeEventListener("change", clear);
        };

        field.addEventListener("input", clear);
        field.addEventListener("change", clear);
    }

    function isBlank(task) {
        return (
            !task.title.trim() &&
            !task.description.trim() &&
            !task.technician_id &&
            !task.start_date &&
            !task.due_date
        );
    }

    /**
     * Turn the array into the phases[N][...] inputs the controller reads, then
     * post to the given endpoint.
     */
    function submitTo(url) {
        syncFromInputs();

        const problem = validationError();

        if (problem) {
            reportProblem(problem);

            return false;
        }

        form.querySelectorAll("[data-phase-payload]").forEach(function (input) {
            input.remove();
        });

        rows.forEach(function (row, index) {
            const prefix = "phases[" + index + "]";

            appendHidden(prefix + "[phase_id]", row.phase_id || "");
            appendHidden(prefix + "[stage_id]", row.stage_id || "");
            appendHidden(prefix + "[title]", row.title.trim());
            appendHidden(prefix + "[description]", row.description.trim());

            const tasks = row.tasks.filter(function (task) {
                return !isBlank(task);
            });

            // A phase with no tasks simply sends none. The controller reads an
            // absent list as an empty one.
            tasks.forEach(function (task, taskIndex) {
                const taskPrefix = prefix + "[tasks][" + taskIndex + "]";

                appendHidden(taskPrefix + "[title]", task.title.trim());
                appendHidden(
                    taskPrefix + "[description]",
                    task.description.trim(),
                );
                appendHidden(
                    taskPrefix + "[technician_id]",
                    task.technician_id || "",
                );
                appendHidden(taskPrefix + "[start_date]", task.start_date || "");
                appendHidden(taskPrefix + "[due_date]", task.due_date || "");
            });
        });

        Object.keys(reassignments).forEach(function (phaseId) {
            appendHidden("reassign[" + phaseId + "]", reassignments[phaseId]);
        });

        form.action = url;
        form.submit();

        return true;
    }

    function appendHidden(name, value) {
        const input = document.createElement("input");

        input.type = "hidden";
        input.name = name;
        input.value = value;
        input.setAttribute("data-phase-payload", "");

        form.appendChild(input);
    }

    saveButton.addEventListener("click", function () {
        submitTo(form.dataset.saveUrl);
    });

    finalizeButton.addEventListener("click", function () {
        syncFromInputs();

        const problem = validationError();

        if (!problem) {
            submitTo(form.dataset.finalizeUrl);

            return;
        }

        // The dialog is open when this fires, so a refusal has to close it -
        // otherwise the reason sits behind the backdrop. And it is reported
        // only once the dialog has finished closing: Bootstrap hands focus back
        // to the button that opened it, which would take it straight off the
        // field the person is being sent to fix.
        const modalEl = document.getElementById("finalizePhasesModal");

        modalEl.addEventListener(
            "hidden.bs.modal",
            function () {
                reportProblem(problem);
            },
            { once: true },
        );

        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });

    // What is about to be locked, listed in the confirmation dialog: the
    // warning is about a structure, so the structure should be on screen while
    // it is read. The task counts are there because finalizing creates that
    // work for real, and how much of it is unassigned is the thing most worth
    // knowing before pressing the button.
    document
        .getElementById("finalizePhasesModal")
        .addEventListener("show.bs.modal", function () {
            syncFromInputs();

            const real = rows.filter(function (row) {
                return !isBlank(row);
            });

            const tasks = rows.reduce(function (total, row) {
                return (
                    total +
                    row.tasks.filter(function (task) {
                        return !isBlank(task);
                    }).length
                );
            }, 0);

            const unassigned = rows.reduce(function (total, row) {
                return (
                    total +
                    row.tasks.filter(function (task) {
                        return !isBlank(task) && !task.technician_id;
                    }).length
                );
            }, 0);

            summary.innerHTML =
                "<strong>" +
                real.length +
                " phase" +
                (real.length === 1 ? "" : "s") +
                " will be locked" +
                (tasks
                    ? ", and " +
                      tasks +
                      " task" +
                      (tasks === 1 ? "" : "s") +
                      " created"
                    : "") +
                ":</strong><ol class='phase-summary-list'>" +
                rows
                    .map(function (row, index) {
                        const count = row.tasks.filter(function (task) {
                            return !isBlank(task);
                        }).length;

                        return (
                            "<li class='" +
                            colourClass(index) +
                            "'><span class='phase-summary-dot'>" +
                            (index + 1) +
                            "</span>" +
                            escapeHtml(row.title || "Untitled phase") +
                            (count
                                ? " <span class='text-secondary'>&mdash; " +
                                  count +
                                  " task" +
                                  (count === 1 ? "" : "s") +
                                  "</span>"
                                : "") +
                            "</li>"
                        );
                    })
                    .join("") +
                "</ol>" +
                (unassigned
                    ? "<p class='mb-0 mt-2 text-secondary'>" +
                      unassigned +
                      " task" +
                      (unassigned === 1 ? " has" : "s have") +
                      " no technician yet. " +
                      (unassigned === 1 ? "It" : "They") +
                      " can be assigned on the task board afterwards.</p>"
                    : "");
        });

    // ------------------------------------------------------------------
    // Reordering by drag
    //
    // The handle is what is draggable rather than the whole row, now that a row
    // contains text boxes and date pickers - dragging inside an input to select
    // text should not start a reorder.
    // ------------------------------------------------------------------

    let draggingIndex = null;

    container.addEventListener("dragstart", function (event) {
        const handle = event.target.closest(".phase-card-handle");

        if (!handle) {
            // Anything else inside a row - selecting text in a title, dragging
            // across a description - is not the start of a reorder.
            event.preventDefault();

            return;
        }

        const rowEl = handle.closest("[data-phase-row]");

        syncFromInputs();

        draggingIndex = parseInt(rowEl.dataset.phaseRow, 10);
        rowEl.classList.add("is-dragging");

        // Firefox will not start a drag without something on the transfer.
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", String(draggingIndex));
    });

    container.addEventListener("dragover", function (event) {
        if (draggingIndex === null) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = "move";

        const overEl = event.target.closest("[data-phase-row]");

        container.querySelectorAll("[data-phase-row]").forEach(function (el) {
            el.classList.toggle("is-drop-target", el === overEl);
        });
    });

    container.addEventListener("drop", function (event) {
        if (draggingIndex === null) {
            return;
        }

        event.preventDefault();

        const overEl = event.target.closest("[data-phase-row]");

        if (overEl) {
            const target = parseInt(overEl.dataset.phaseRow, 10);

            if (target !== draggingIndex) {
                const moved = rows.splice(draggingIndex, 1)[0];
                rows.splice(target, 0, moved);
            }
        }

        draggingIndex = null;
        render();
    });

    container.addEventListener("dragend", function () {
        draggingIndex = null;
        render();
    });

    // Enter inside a title field would otherwise submit the form straight to
    // the finalize action, skipping the confirmation entirely. Date inputs are
    // left alone: Enter in a date picker is how it is closed.
    form.addEventListener("keydown", function (event) {
        if (
            event.key === "Enter" &&
            event.target.tagName === "INPUT" &&
            event.target.type !== "date"
        ) {
            event.preventDefault();
        }
    });

    render();
});
