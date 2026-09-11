/**
 * Configuration -> System Settings -> Project Settings -> Default Phases & Tasks.
 *
 * Two steps, laid out as two steps, because the feature has two levels and the
 * first version of this screen showed them as two equal columns - which left
 * "Site Preparation" on screen twice with nothing saying how the two were
 * related.
 *
 *   Step 1, the stage vocabulary, is shared by every project type. It is what
 *   makes a project that is two types at once come out with ONE Site
 *   Preparation phase rather than two, and it is the only place the order of
 *   phases is decided. Writes here go to the server immediately.
 *
 *   Step 2 is one type's template: which of those stages its work goes
 *   through, and what it starts with in each. This is the half that differs
 *   between types and gets merged under a shared heading. Nothing here is
 *   written until Save, so the working copy is held in `template` and the bar
 *   at the bottom says whether it matches what is stored.
 *
 * Every write answers with the whole catalogue, so what is on screen is what
 * was saved rather than what this file guessed. Destructive questions go
 * through the page's own confirmation dialog rather than window.confirm(),
 * which cannot show the server's reason for a refusal.
 */
document.addEventListener("DOMContentLoaded", function () {
    const pane = document.getElementById("phaseTemplatesPane");

    if (!pane) {
        return;
    }

    const routes = window.configurationRoutes || {};

    if (!routes.phaseTemplates) {
        return;
    }

    const loading = pane.querySelector("[data-phase-template-loading]");
    const body = pane.querySelector("[data-phase-template-body]");
    const errorBox = pane.querySelector("[data-phase-template-error]");
    const successBox = pane.querySelector("[data-phase-template-success]");

    const addToggle = pane.querySelector("[data-stage-add-toggle]");
    const addCancel = pane.querySelector("[data-stage-add-cancel]");
    const stageForm = pane.querySelector("[data-stage-add-form]");
    const stageName = pane.querySelector("[data-stage-name]");
    const stageDescription = pane.querySelector("[data-stage-description]");
    const stageAddButton = pane.querySelector("[data-stage-add]");
    const stageAddSpinner = pane.querySelector("[data-stage-add-spinner]");
    const stageList = pane.querySelector("[data-stage-list]");
    const stageEmpty = pane.querySelector("[data-stage-empty]");

    const typePicker = pane.querySelector("[data-phase-template-types]");
    const stagesHolder = pane.querySelector("[data-phase-template-stages]");
    const templateEmpty = pane.querySelector("[data-phase-template-empty]");
    const saveBar = pane.querySelector("[data-phase-template-savebar]");
    const saveState = pane.querySelector("[data-phase-template-state]");
    const saveButton = pane.querySelector("[data-phase-template-save]");
    const saveSpinner = pane.querySelector("[data-phase-template-save-spinner]");
    const discardButton = pane.querySelector("[data-phase-template-discard]");

    const token =
        document.querySelector('meta[name="csrf-token"]')?.content || "";

    let stages = [];
    let types = [];
    let maxTasks = 50;

    /** The template being edited, and what it looked like when it was loaded. */
    let template = [];
    let saved = "";

    let currentTypeId = null;
    let editingStageId = null;

    function escapeHtml(value) {
        const span = document.createElement("span");
        span.textContent = value == null ? "" : String(value);

        return span.innerHTML;
    }

    function plural(count, word) {
        return count + " " + word + (count === 1 ? "" : "s");
    }

    function showError(message) {
        errorBox.textContent = message || "";
        errorBox.classList.toggle("d-none", !message);

        if (message) {
            showSuccess("");
            errorBox.scrollIntoView({ block: "nearest", behavior: "smooth" });
        }
    }

    function showSuccess(message) {
        successBox.textContent = message || "";
        successBox.classList.toggle("d-none", !message);
    }

    function request(url, method, payload) {
        return fetch(url, {
            method: method,
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-Requested-With": "XMLHttpRequest",
            },
            body: payload === undefined ? undefined : JSON.stringify(payload),
        }).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (payload) {
                    return { ok: response.ok, body: payload };
                });
        });
    }

    /** The same call, for the paths that would rather throw than branch. */
    function must(url, method, payload) {
        return request(url, method, payload).then(function (result) {
            if (!result.ok) {
                throw new Error(
                    result.body.error || "Something went wrong. Try again.",
                );
            }

            return result.body;
        });
    }

    function busy(button, spinner, on) {
        button.disabled = on;
        spinner.classList.toggle("d-none", !on);
    }

    // ------------------------------------------------------------------
    // Step 1: the shared vocabulary
    // ------------------------------------------------------------------

    function toggleAddForm(open) {
        stageForm.classList.toggle("d-none", !open);
        addToggle.classList.toggle("d-none", open);
        addToggle.setAttribute("aria-expanded", open ? "true" : "false");

        if (open) {
            stageName.focus();
        } else {
            stageName.value = "";
            stageDescription.value = "";
        }
    }

    addToggle.addEventListener("click", function () {
        toggleAddForm(true);
    });

    addCancel.addEventListener("click", function () {
        toggleAddForm(false);
    });

    function renderStages() {
        stageEmpty.classList.toggle("d-none", stages.length > 0);

        // Which stages the type on the right is using, so the two steps read as
        // one screen: a stage in play is marked as such in the list it comes
        // from, not only in the panel beside it.
        const inUse = new Set(
            template
                .filter(function (stage) {
                    return stage.selected;
                })
                .map(function (stage) {
                    return stage.stage_id;
                }),
        );

        stageList.innerHTML = stages
            .map(function (stage, index) {
                if (stage.stage_id === editingStageId) {
                    return (
                        '<li class="phase-stage-item is-editing" data-stage-id="' +
                        stage.stage_id +
                        '">' +
                        '<label class="form-label small fw-semibold mb-1">Stage name</label>' +
                        '<input type="text" class="form-control form-control-sm mb-2" maxlength="150"' +
                        ' value="' +
                        escapeHtml(stage.name) +
                        '" data-stage-edit-name>' +
                        '<label class="form-label small fw-semibold mb-1">Description</label>' +
                        '<textarea class="form-control form-control-sm mb-2" rows="2" maxlength="500"' +
                        " data-stage-edit-description>" +
                        escapeHtml(stage.default_description) +
                        "</textarea>" +
                        '<div class="d-flex gap-2">' +
                        '<button type="button" class="btn btn-sm btn-primary px-3" data-stage-save>Save</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary px-3" data-stage-cancel>Cancel</button>' +
                        "</div>" +
                        "</li>"
                    );
                }

                const used = inUse.has(stage.stage_id);

                return (
                    '<li class="phase-stage-item' +
                    (used ? " is-in-use" : "") +
                    '" data-stage-id="' +
                    stage.stage_id +
                    '">' +
                    '<span class="phase-stage-index" aria-hidden="true">' +
                    (index + 1) +
                    "</span>" +
                    '<div class="phase-stage-body">' +
                    '<div class="phase-stage-name">' +
                    escapeHtml(stage.name) +
                    (used
                        ? '<span class="phase-stage-inuse-tag">in this type</span>'
                        : "") +
                    "</div>" +
                    '<div class="phase-stage-desc">' +
                    escapeHtml(stage.default_description) +
                    "</div>" +
                    '<div class="phase-stage-usage">' +
                    '<span class="phase-usage-pill phase-usage-types">' +
                    '<i class="bi bi-diagram-3"></i>' +
                    plural(stage.type_count, "type") +
                    "</span>" +
                    '<span class="phase-usage-pill phase-usage-tasks">' +
                    '<i class="bi bi-check2-square"></i>' +
                    plural(stage.task_count, "task") +
                    "</span>" +
                    "</div>" +
                    "</div>" +
                    '<div class="phase-stage-actions">' +
                    '<div class="phase-stage-order">' +
                    '<button type="button" class="phase-icon-btn" data-stage-move="up"' +
                    ' aria-label="Move ' +
                    escapeHtml(stage.name) +
                    ' earlier"' +
                    (index === 0 ? " disabled" : "") +
                    '><i class="bi bi-chevron-up"></i></button>' +
                    '<button type="button" class="phase-icon-btn" data-stage-move="down"' +
                    ' aria-label="Move ' +
                    escapeHtml(stage.name) +
                    ' later"' +
                    (index === stages.length - 1 ? " disabled" : "") +
                    '><i class="bi bi-chevron-down"></i></button>' +
                    "</div>" +
                    '<button type="button" class="phase-icon-btn phase-icon-edit" data-stage-edit' +
                    ' aria-label="Edit ' +
                    escapeHtml(stage.name) +
                    '"><i class="bi bi-pencil"></i></button>' +
                    '<button type="button" class="phase-icon-btn phase-icon-delete" data-stage-remove' +
                    ' aria-label="Remove ' +
                    escapeHtml(stage.name) +
                    '"><i class="bi bi-trash"></i></button>' +
                    "</div>" +
                    "</li>"
                );
            })
            .join("");
    }

    stageForm.addEventListener("submit", function (event) {
        event.preventDefault();

        const name = stageName.value.trim();
        const description = stageDescription.value.trim();

        if (!name || !description) {
            showError("A stage needs a name and a description.");

            return;
        }

        busy(stageAddButton, stageAddSpinner, true);

        must(routes.phaseTemplateBase + "/stages", "POST", {
            name: name,
            default_description: description,
        })
            .then(function (data) {
                toggleAddForm(false);
                absorb(data);
                showError("");
                showSuccess(data.message);

                // The new stage is a tick box the open template can now use.
                return reloadTemplate();
            })
            .catch(function (error) {
                showError(error.message);
            })
            .finally(function () {
                busy(stageAddButton, stageAddSpinner, false);
            });
    });

    stageList.addEventListener("click", function (event) {
        const item = event.target.closest("[data-stage-id]");

        if (!item) {
            return;
        }

        const stageId = parseInt(item.dataset.stageId, 10);

        if (event.target.closest("[data-stage-edit]")) {
            editingStageId = stageId;
            renderStages();

            return;
        }

        if (event.target.closest("[data-stage-cancel]")) {
            editingStageId = null;
            renderStages();

            return;
        }

        if (event.target.closest("[data-stage-save]")) {
            const name = item
                .querySelector("[data-stage-edit-name]")
                .value.trim();
            const description = item
                .querySelector("[data-stage-edit-description]")
                .value.trim();

            if (!name || !description) {
                showError("A stage needs a name and a description.");

                return;
            }

            must(routes.phaseTemplateBase + "/stages/" + stageId, "PUT", {
                name: name,
                default_description: description,
            })
                .then(function (data) {
                    editingStageId = null;
                    absorb(data);
                    showError("");
                    showSuccess(data.message);

                    return reloadTemplate();
                })
                .catch(function (error) {
                    showError(error.message);
                });

            return;
        }

        if (event.target.closest("[data-stage-remove]")) {
            const stage = stages.find(function (candidate) {
                return candidate.stage_id === stageId;
            });

            askRemoveStage(stage);

            return;
        }

        const move = event.target.closest("[data-stage-move]");

        if (move) {
            reorderStage(stageId, move.dataset.stageMove);
        }
    });

    /**
     * The page's dialog rather than window.confirm(), because this endpoint
     * refuses a stage that a project type is still using - and that refusal is
     * the most useful thing the dialog can say. It comes back into the dialog,
     * naming the types, while the person is still looking at it.
     */
    function askRemoveStage(stage) {
        if (!stage) {
            return;
        }

        const url = routes.phaseTemplateBase + "/stages/" + stage.stage_id;

        window.configurationConfirm({
            title: 'Remove the stage "' + stage.name + '"?',
            body:
                stage.type_count > 0
                    ? "It is used by " +
                      plural(stage.type_count, "project type") +
                      ". Untick it there first - removing it would take " +
                      plural(stage.task_count, "default task") +
                      " with it."
                    : "New projects will no longer be offered this phase. " +
                      "Projects already set up keep the phases they have.",
            label: "Remove Stage",
            variant: "btn-danger",
            onConfirm: function () {
                return request(url, "DELETE");
            },
            onSuccess: function (data) {
                absorb(data);
                showError("");
                showSuccess(data.message);
                reloadTemplate();
            },
        });
    }

    function reorderStage(stageId, direction) {
        const index = stages.findIndex(function (candidate) {
            return candidate.stage_id === stageId;
        });

        const target = direction === "up" ? index - 1 : index + 1;

        if (target < 0 || target >= stages.length) {
            return;
        }

        const reordered = stages.slice();
        const moved = reordered.splice(index, 1)[0];
        reordered.splice(target, 0, moved);

        // Drawn immediately so the arrow feels like it did something, then
        // corrected by whatever the server says came back.
        stages = reordered;
        renderStages();

        must(routes.phaseTemplateBase + "/stages/reorder", "POST", {
            stage_ids: reordered.map(function (stage) {
                return stage.stage_id;
            }),
        })
            .then(function (data) {
                absorb(data);
                showError("");
                showSuccess(data.message);

                return reloadTemplate();
            })
            .catch(function (error) {
                showError(error.message);
                load();
            });
    }

    // ------------------------------------------------------------------
    // Step 2: one type's template
    // ------------------------------------------------------------------

    function renderTypes() {
        typePicker.innerHTML = types
            .map(function (type) {
                const active = String(type.type_id) === String(currentTypeId);
                const empty = type.stage_count === 0;

                return (
                    '<button type="button" role="tab" class="phase-type-card' +
                    (active ? " is-active" : "") +
                    (empty ? " is-empty" : "") +
                    '" aria-selected="' +
                    (active ? "true" : "false") +
                    '" data-phase-type="' +
                    type.type_id +
                    '">' +
                    '<span class="phase-type-name">' +
                    escapeHtml(type.type_name) +
                    "</span>" +
                    '<span class="phase-type-meta">' +
                    (empty
                        ? '<span class="phase-type-tag phase-type-tag-empty">Not set up</span>'
                        : '<span class="phase-type-tag">' +
                          plural(type.stage_count, "stage") +
                          "</span>" +
                          '<span class="phase-type-tag">' +
                          plural(type.task_count, "task") +
                          "</span>") +
                    "</span>" +
                    "</button>"
                );
            })
            .join("");
    }

    typePicker.addEventListener("click", function (event) {
        const card = event.target.closest("[data-phase-type]");

        if (!card || String(card.dataset.phaseType) === String(currentTypeId)) {
            return;
        }

        const move = function () {
            currentTypeId = card.dataset.phaseType;
            renderTypes();
            reloadTemplate();
        };

        // Switching type replaces the working copy, so unsaved work would go
        // without anybody being asked.
        if (!isDirty()) {
            move();

            return;
        }

        window.configurationConfirm({
            title: "Discard unsaved changes?",
            body:
                "The default phases for " +
                currentTypeName() +
                " have been changed and not saved. Switching to another type " +
                "will lose those changes.",
            label: "Discard and Switch",
            variant: "btn-danger",
            onConfirm: function () {
                return Promise.resolve({ ok: true, body: {} });
            },
            onSuccess: move,
        });
    });

    function currentTypeName() {
        const type = types.find(function (candidate) {
            return String(candidate.type_id) === String(currentTypeId);
        });

        return type ? type.type_name : "this project type";
    }

    function renderTemplate() {
        const hasStages = template.length > 0;

        templateEmpty.classList.toggle("d-none", hasStages);
        stagesHolder.classList.toggle("d-none", !hasStages);
        saveBar.classList.toggle("d-none", !hasStages);

        stagesHolder.innerHTML = template
            .map(function (stage, index) {
                const count = stage.tasks.length;

                const tasks = stage.selected
                    ? '<div class="phase-template-tasks">' +
                      (count
                          ? stage.tasks
                                .map(function (task, taskIndex) {
                                    return taskMarkup(task, index, taskIndex);
                                })
                                .join("")
                          : '<p class="phase-template-notasks mb-2">' +
                            "No default tasks yet. Projects will get this phase with no work in it." +
                            "</p>") +
                      '<button type="button" class="phase-add-task" data-template-task-add' +
                      (count >= maxTasks ? " disabled" : "") +
                      '><i class="bi bi-plus-lg"></i>Add a default task</button>' +
                      "</div>"
                    : "";

                return (
                    '<div class="phase-template-stage' +
                    (stage.selected ? " is-selected" : "") +
                    '" data-template-stage="' +
                    index +
                    '">' +
                    '<label class="phase-template-tick">' +
                    '<input type="checkbox" class="form-check-input"' +
                    (stage.selected ? " checked" : "") +
                    " data-template-stage-tick>" +
                    '<span class="phase-template-tick-body">' +
                    '<span class="phase-template-stage-name">' +
                    escapeHtml(stage.name) +
                    (stage.selected
                        ? '<span class="phase-template-count">' +
                          plural(count, "task") +
                          "</span>"
                        : '<span class="phase-template-count phase-template-count-off">Not used</span>') +
                    "</span>" +
                    '<span class="phase-template-stage-desc">' +
                    escapeHtml(stage.default_description) +
                    "</span>" +
                    "</span>" +
                    "</label>" +
                    tasks +
                    "</div>"
                );
            })
            .join("");

        renderDirty();
        // A ticked stage is marked in step 1's list too.
        renderStages();
    }

    function taskMarkup(task, stageIndex, taskIndex) {
        const label =
            "Default task " + (taskIndex + 1) + " for stage " + (stageIndex + 1);

        return (
            '<div class="phase-template-task" data-template-task="' +
            taskIndex +
            '">' +
            '<span class="phase-task-index" aria-hidden="true">' +
            (taskIndex + 1) +
            "</span>" +
            '<div class="flex-grow-1 min-width-0">' +
            '<input type="text" class="form-control form-control-sm" maxlength="255"' +
            ' placeholder="Task title" aria-label="' +
            label +
            ' title" data-template-task-title value="' +
            escapeHtml(task.title) +
            '">' +
            '<textarea class="form-control form-control-sm mt-1" rows="2"' +
            ' placeholder="What this task involves" aria-label="' +
            label +
            ' description" data-template-task-description>' +
            escapeHtml(task.description) +
            "</textarea>" +
            "</div>" +
            '<button type="button" class="phase-icon-btn phase-icon-delete"' +
            ' data-template-task-remove aria-label="Remove ' +
            label +
            '"><i class="bi bi-x-lg"></i></button>' +
            "</div>"
        );
    }

    /**
     * Read the typed values back into the working copy, so a tick or a removal
     * does not throw away edits made since the last render.
     */
    function syncTemplate() {
        stagesHolder
            .querySelectorAll("[data-template-stage]")
            .forEach(function (element) {
                const index = parseInt(element.dataset.templateStage, 10);
                const stage = template[index];

                if (!stage) {
                    return;
                }

                element
                    .querySelectorAll("[data-template-task]")
                    .forEach(function (taskEl) {
                        const taskIndex = parseInt(
                            taskEl.dataset.templateTask,
                            10,
                        );

                        if (!stage.tasks[taskIndex]) {
                            return;
                        }

                        stage.tasks[taskIndex].title = taskEl.querySelector(
                            "[data-template-task-title]",
                        ).value;
                        stage.tasks[taskIndex].description =
                            taskEl.querySelector(
                                "[data-template-task-description]",
                            ).value;
                    });
            });
    }

    // ------------------------------------------------------------------
    // Saved / unsaved
    //
    // Compared against a snapshot taken at load rather than tracked with a
    // flag: typing a word and deleting it again leaves the template exactly as
    // it was, and a screen that still claims unsaved changes after that trains
    // people to ignore the warning.
    // ------------------------------------------------------------------

    function snapshot() {
        return JSON.stringify(
            template.map(function (stage) {
                return {
                    stage_id: stage.stage_id,
                    selected: stage.selected,
                    // Only the ticked stages' work counts: tasks left under an
                    // unticked stage are not sent, so they are not a change.
                    tasks: stage.selected
                        ? stage.tasks.map(function (task) {
                              return [task.title.trim(), task.description.trim()];
                          })
                        : [],
                };
            }),
        );
    }

    function isDirty() {
        syncTemplate();

        return snapshot() !== saved;
    }

    function renderDirty() {
        const dirty = snapshot() !== saved;

        saveButton.disabled = !dirty;
        discardButton.disabled = !dirty;
        saveBar.classList.toggle("is-dirty", dirty);

        saveState.innerHTML = dirty
            ? '<i class="bi bi-pencil-fill"></i>Unsaved changes'
            : '<i class="bi bi-check2-circle"></i>All changes saved';
    }

    stagesHolder.addEventListener("change", function (event) {
        const tick = event.target.closest("[data-template-stage-tick]");

        if (!tick) {
            return;
        }

        syncTemplate();

        const index = parseInt(
            tick.closest("[data-template-stage]").dataset.templateStage,
            10,
        );

        template[index].selected = tick.checked;

        // Unticking keeps the tasks in the working copy rather than discarding
        // them, so a mis-click is one click to undo. They are only actually
        // lost if Save is pressed while the stage is unticked.
        renderTemplate();
    });

    // Typing is what most changes are, so the bar has to notice it.
    stagesHolder.addEventListener("input", function (event) {
        if (
            event.target.matches(
                "[data-template-task-title], [data-template-task-description]",
            )
        ) {
            syncTemplate();
            renderDirty();
        }
    });

    stagesHolder.addEventListener("click", function (event) {
        const stageEl = event.target.closest("[data-template-stage]");

        if (!stageEl) {
            return;
        }

        const index = parseInt(stageEl.dataset.templateStage, 10);

        if (event.target.closest("[data-template-task-add]")) {
            syncTemplate();

            if (template[index].tasks.length >= maxTasks) {
                showError(
                    "A project type can have at most " +
                        maxTasks +
                        " default tasks in one stage.",
                );

                return;
            }

            template[index].tasks.push({ title: "", description: "" });
            showError("");
            renderTemplate();

            const input = stagesHolder.querySelector(
                '[data-template-stage="' +
                    index +
                    '"] [data-template-task="' +
                    (template[index].tasks.length - 1) +
                    '"] [data-template-task-title]',
            );

            if (input) {
                input.focus();
            }

            return;
        }

        const removeTask = event.target.closest("[data-template-task-remove]");

        if (removeTask) {
            syncTemplate();

            const taskIndex = parseInt(
                removeTask.closest("[data-template-task]").dataset.templateTask,
                10,
            );

            template[index].tasks.splice(taskIndex, 1);
            renderTemplate();
        }
    });

    discardButton.addEventListener("click", function () {
        if (!isDirty()) {
            return;
        }

        window.configurationConfirm({
            title: "Discard unsaved changes?",
            body:
                "The default phases for " +
                currentTypeName() +
                " will go back to what was last saved.",
            label: "Discard Changes",
            variant: "btn-danger",
            onConfirm: function () {
                return Promise.resolve({ ok: true, body: {} });
            },
            onSuccess: function () {
                reloadTemplate();
                showSuccess("");
                showError("");
            },
        });
    });

    saveButton.addEventListener("click", function () {
        if (!currentTypeId) {
            return;
        }

        syncTemplate();

        const selected = template.filter(function (stage) {
            return stage.selected;
        });

        // A task with a title and no description, or the reverse, is half an
        // answer. An entirely blank row is somebody who pressed Add and thought
        // better of it, and is dropped rather than complained about.
        for (let index = 0; index < selected.length; index += 1) {
            const stage = selected[index];

            for (let t = 0; t < stage.tasks.length; t += 1) {
                const title = stage.tasks[t].title.trim();
                const description = stage.tasks[t].description.trim();

                if (!title && !description) {
                    continue;
                }

                if (!title || !description) {
                    showError(
                        "Every default task in " +
                            stage.name +
                            " needs both a title and a description.",
                    );

                    return;
                }
            }
        }

        busy(saveButton, saveSpinner, true);

        must(routes.phaseTemplateBase + "/types/" + currentTypeId, "PUT", {
            stages: selected.map(function (stage) {
                return {
                    stage_id: stage.stage_id,
                    tasks: stage.tasks
                        .filter(function (task) {
                            return task.title.trim() || task.description.trim();
                        })
                        .map(function (task) {
                            return {
                                title: task.title.trim(),
                                description: task.description.trim(),
                            };
                        }),
                };
            }),
        })
            .then(function (data) {
                template = normaliseTemplate(data.stages);
                saved = snapshot();
                types = data.types || types;

                renderTypes();
                renderTemplate();
                showError("");
                showSuccess(data.message);

                // The counts beside each stage in step 1 have moved.
                return must(routes.phaseTemplates, "GET");
            })
            .then(function (data) {
                stages = data.stages || stages;
                renderStages();
            })
            .catch(function (error) {
                showError(error.message);
            })
            .finally(function () {
                busy(saveButton, saveSpinner, false);
            });
    });

    function normaliseTemplate(rows) {
        return (rows || []).map(function (stage) {
            return {
                stage_id: stage.stage_id,
                name: stage.name,
                default_description: stage.default_description,
                selected: Boolean(stage.selected),
                tasks: (stage.tasks || []).map(function (task) {
                    return {
                        title: task.title || "",
                        description: task.description || "",
                    };
                }),
            };
        });
    }

    function reloadTemplate() {
        if (!currentTypeId) {
            template = [];
            saved = snapshot();
            renderTemplate();

            return Promise.resolve();
        }

        return must(routes.phaseTemplateBase + "/types/" + currentTypeId, "GET")
            .then(function (data) {
                template = normaliseTemplate(data.stages);
                saved = snapshot();
                maxTasks = data.max_tasks_per_stage || maxTasks;
                renderTemplate();
            })
            .catch(function (error) {
                showError(error.message);
            });
    }

    function pickType() {
        if (!types.length) {
            currentTypeId = null;

            return;
        }

        const stillThere = types.some(function (type) {
            return String(type.type_id) === String(currentTypeId);
        });

        if (!stillThere) {
            // The first type nobody has written a template for, so the screen
            // opens on the work that still needs doing rather than on whatever
            // sorts first.
            const unset = types.find(function (type) {
                return type.stage_count === 0;
            });

            currentTypeId = String((unset || types[0]).type_id);
        }
    }

    /**
     * Take what a write handed back. Every endpoint answers with the whole
     * catalogue, so this is the only place the two lists are replaced.
     */
    function absorb(data) {
        stages = data.stages || stages;
        types = data.types || types;

        pickType();
        renderStages();
        renderTypes();
    }

    /**
     * The Project Types table above this panel changed.
     *
     * Only the lists are refreshed, and the template being edited is left
     * alone unless the type it belongs to has just been removed - renaming a
     * type somewhere else on the page is no reason to throw away work typed
     * here.
     */
    document.addEventListener("project-types:changed", function () {
        must(routes.phaseTemplates, "GET")
            .then(function (data) {
                stages = data.stages || stages;
                types = data.types || [];

                const survived = types.some(function (type) {
                    return String(type.type_id) === String(currentTypeId);
                });

                pickType();
                renderStages();
                renderTypes();

                if (!survived) {
                    return reloadTemplate();
                }
            })
            .catch(function () {
                // The panel is still showing what it last read, which is not
                // wrong - only possibly a moment out of date. The table above
                // will have reported whatever actually failed.
            });
    });

    function load() {
        must(routes.phaseTemplates, "GET")
            .then(function (data) {
                stages = data.stages || [];
                types = data.types || [];
                maxTasks = data.max_tasks_per_stage || maxTasks;

                pickType();
                renderStages();
                renderTypes();

                loading.classList.add("d-none");
                body.classList.remove("d-none");

                return reloadTemplate();
            })
            .catch(function (error) {
                loading.classList.add("d-none");
                showError(error.message);
            });
    }

    load();
});
