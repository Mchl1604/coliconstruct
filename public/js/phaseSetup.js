/**
 * The phase setup editor.
 *
 * Rows are held in an array and redrawn from it, rather than the DOM being the
 * source of truth: reordering has to renumber every row, and a phase's number
 * is the thing tasks are filed under, so "what is Phase 3" needs one answer
 * rather than one per element. The array is written into hidden inputs named
 * phases[N][...] on submit, which is the shape ProjectPhaseController expects.
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

    function readInitialRows() {
        const holder = document.querySelector("[data-phase-initial]");

        try {
            const parsed = JSON.parse(holder.dataset.phaseInitial || "[]");

            // old() comes back keyed by index as an object when a row was
            // removed before the refused submit, so normalise it.
            return Object.values(parsed).map(function (row) {
                return {
                    phase_id: row.phase_id ? parseInt(row.phase_id, 10) : null,
                    title: row.title || "",
                    description: row.description || "",
                    tasks: parseInt(row.tasks || 0, 10),
                };
            });
        } catch (error) {
            return [];
        }
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

    function render() {
        container.innerHTML = rows
            .map(function (row, index) {
                const number = index + 1;
                const hasTasks = row.tasks > 0;

                return (
                    '<div class="phase-row" draggable="true" data-phase-row="' +
                    index +
                    '">' +
                    '<div class="phase-row-handle" aria-hidden="true">' +
                    '<i class="bi bi-grip-vertical"></i>' +
                    "</div>" +
                    '<div class="phase-row-number">' +
                    '<span class="phase-row-badge">Phase ' +
                    number +
                    "</span>" +
                    (hasTasks
                        ? '<span class="phase-row-tasks">' +
                          row.tasks +
                          " task" +
                          (row.tasks === 1 ? "" : "s") +
                          "</span>"
                        : "") +
                    "</div>" +
                    '<div class="phase-row-fields">' +
                    '<input type="text" class="form-control mb-2" maxlength="150"' +
                    ' placeholder="Phase title, e.g. Site Preparation"' +
                    ' aria-label="Phase ' +
                    number +
                    ' title" data-phase-title value="' +
                    escapeHtml(row.title) +
                    '">' +
                    '<textarea class="form-control" rows="2" maxlength="500"' +
                    ' placeholder="Short description of what happens in this phase"' +
                    ' aria-label="Phase ' +
                    number +
                    ' description" data-phase-description>' +
                    escapeHtml(row.description) +
                    "</textarea>" +
                    "</div>" +
                    '<div class="phase-row-actions">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary"' +
                    ' data-phase-move="up" aria-label="Move phase ' +
                    number +
                    ' up"' +
                    (index === 0 ? " disabled" : "") +
                    '><i class="bi bi-arrow-up"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary"' +
                    ' data-phase-move="down" aria-label="Move phase ' +
                    number +
                    ' down"' +
                    (index === rows.length - 1 ? " disabled" : "") +
                    '><i class="bi bi-arrow-down"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger"' +
                    ' data-phase-remove aria-label="Remove phase ' +
                    number +
                    '"' +
                    (rows.length <= minPhases ? " disabled" : "") +
                    '><i class="bi bi-trash"></i></button>' +
                    "</div>" +
                    "</div>"
                );
            })
            .join("");

        countBadge.textContent =
            rows.length + " phase" + (rows.length === 1 ? "" : "s");

        addButton.disabled = rows.length >= maxPhases;
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
            });
    }

    addButton.addEventListener("click", function () {
        syncFromInputs();

        if (rows.length >= maxPhases) {
            return;
        }

        rows.push({ phase_id: null, title: "", description: "", tasks: 0 });
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

    /**
     * Removing a phase with tasks on it is never a plain removal: the work has
     * to be told where to go first. Only reachable after a Super Admin
     * override - a project that has never been finalized has no tasks.
     */
    function requestRemoval(index) {
        const row = rows[index];

        if (rows.length <= minPhases) {
            setError("A project needs at least " + minPhases + " phase.");

            return;
        }

        if (!row.tasks) {
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
            row.tasks +
            " task" +
            (row.tasks === 1 ? "" : "s") +
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
     */
    function validationError() {
        if (rows.length < minPhases) {
            return "Add at least " + minPhases + " phase before continuing.";
        }

        for (let index = 0; index < rows.length; index += 1) {
            if (!rows[index].title.trim()) {
                return "Phase " + (index + 1) + " needs a title.";
            }

            if (!rows[index].description.trim()) {
                return "Phase " + (index + 1) + " needs a short description.";
            }
        }

        return null;
    }

    /**
     * Turn the array into the phases[N][...] inputs the controller reads, then
     * post to the given endpoint.
     */
    function submitTo(url) {
        syncFromInputs();

        const problem = validationError();

        if (problem) {
            setError(problem);

            return false;
        }

        form.querySelectorAll("[data-phase-payload]").forEach(function (input) {
            input.remove();
        });

        rows.forEach(function (row, index) {
            appendHidden("phases[" + index + "][phase_id]", row.phase_id || "");
            appendHidden("phases[" + index + "][title]", row.title.trim());
            appendHidden(
                "phases[" + index + "][description]",
                row.description.trim(),
            );
        });

        Object.keys(reassignments).forEach(function (phaseId) {
            appendHidden(
                "reassign[" + phaseId + "]",
                reassignments[phaseId],
            );
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
        // The dialog is already open when this fires, so a refusal has to
        // close it - otherwise the reason sits behind the backdrop.
        if (!submitTo(form.dataset.finalizeUrl)) {
            bootstrap.Modal.getOrCreateInstance(
                document.getElementById("finalizePhasesModal"),
            ).hide();
        }
    });

    // What is about to be locked, listed in the confirmation dialog: the
    // warning is about a structure, so the structure should be on screen while
    // it is read.
    document
        .getElementById("finalizePhasesModal")
        .addEventListener("show.bs.modal", function () {
            syncFromInputs();

            summary.innerHTML =
                "<strong>" +
                rows.length +
                " phase" +
                (rows.length === 1 ? "" : "s") +
                " will be locked:</strong><ol class='mb-0 mt-1 ps-3'>" +
                rows
                    .map(function (row) {
                        return (
                            "<li>" +
                            escapeHtml(row.title || "Untitled phase") +
                            "</li>"
                        );
                    })
                    .join("") +
                "</ol>";
        });

    // Reordering by hand. The grip is the handle a person aims at, but the
    // whole row is draggable: a 12px target is not one, and the arrow buttons
    // are there for anybody who would rather not drag at all.
    let draggingIndex = null;

    container.addEventListener("dragstart", function (event) {
        const rowEl = event.target.closest("[data-phase-row]");

        if (!rowEl) {
            return;
        }

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
    // the finalize action, skipping the confirmation entirely.
    form.addEventListener("keydown", function (event) {
        if (event.key === "Enter" && event.target.tagName === "INPUT") {
            event.preventDefault();
        }
    });

    render();
});
