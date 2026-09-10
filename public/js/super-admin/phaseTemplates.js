/**
 * Configuration -> System Settings -> Project Settings -> Default Phases & Tasks.
 *
 * Two editors side by side, because the feature has two levels and they answer
 * different questions.
 *
 * The stage list on the left is shared vocabulary. Every project type refers to
 * the same "Installation", and the order they are listed in here is the order
 * every project's phases come out in - which is what makes a project that is
 * two types at once produce one coherent structure rather than two lists
 * stapled together.
 *
 * The panel on the right is one type's template: which of those stages its work
 * goes through, and what it starts with in each. That is the half that differs
 * between types, and the half that gets merged under a shared heading when a
 * project has more than one.
 *
 * Every write goes to the server and comes back with the whole catalogue, so
 * what is on screen is what was saved rather than what this file guessed. The
 * one exception is the template editor's own working copy, which is held here
 * while it is being edited and sent in a single save - a tick and a typed task
 * are one decision, and half of it landing would be worse than neither.
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

    const stageForm = pane.querySelector("[data-stage-add-form]");
    const stageName = pane.querySelector("[data-stage-name]");
    const stageDescription = pane.querySelector("[data-stage-description]");
    const stageAddButton = pane.querySelector("[data-stage-add]");
    const stageAddSpinner = pane.querySelector("[data-stage-add-spinner]");
    const stageList = pane.querySelector("[data-stage-list]");
    const stageEmpty = pane.querySelector("[data-stage-empty]");

    const typeSelect = pane.querySelector("[data-phase-template-type]");
    const stagesHolder = pane.querySelector("[data-phase-template-stages]");
    const templateEmpty = pane.querySelector("[data-phase-template-empty]");
    const saveButton = pane.querySelector("[data-phase-template-save]");
    const saveSpinner = pane.querySelector("[data-phase-template-save-spinner]");

    const token =
        document.querySelector('meta[name="csrf-token"]')?.content || "";

    let stages = [];
    let types = [];
    let maxTasks = 50;

    /** The template being edited, held here until Save. */
    let template = [];
    let currentTypeId = null;

    /** The stage whose name is being edited, so only one input is ever open. */
    let editingStageId = null;

    function escapeHtml(value) {
        const span = document.createElement("span");
        span.textContent = value == null ? "" : String(value);

        return span.innerHTML;
    }

    function showError(message) {
        errorBox.textContent = message || "";
        errorBox.classList.toggle("d-none", !message);

        if (message) {
            showSuccess("");
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
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error(
                        data.error || "Something went wrong. Try again.",
                    );
                }

                return data;
            });
        });
    }

    function busy(button, spinner, on) {
        button.disabled = on;
        spinner.classList.toggle("d-none", !on);
    }

    // ------------------------------------------------------------------
    // The vocabulary
    // ------------------------------------------------------------------

    function renderStages() {
        stageEmpty.classList.toggle("d-none", stages.length > 0);

        stageList.innerHTML = stages
            .map(function (stage, index) {
                if (stage.stage_id === editingStageId) {
                    return (
                        '<li class="phase-stage-item is-editing" data-stage-id="' +
                        stage.stage_id +
                        '">' +
                        '<input type="text" class="form-control form-control-sm mb-2" maxlength="150"' +
                        ' value="' +
                        escapeHtml(stage.name) +
                        '" data-stage-edit-name>' +
                        '<textarea class="form-control form-control-sm mb-2" rows="2" maxlength="500"' +
                        " data-stage-edit-description>" +
                        escapeHtml(stage.default_description) +
                        "</textarea>" +
                        '<div class="d-flex gap-2">' +
                        '<button type="button" class="btn btn-sm btn-primary" data-stage-save>Save</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-stage-cancel>Cancel</button>' +
                        "</div>" +
                        "</li>"
                    );
                }

                return (
                    '<li class="phase-stage-item" data-stage-id="' +
                    stage.stage_id +
                    '">' +
                    '<div class="phase-stage-order">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary"' +
                    ' data-stage-move="up" aria-label="Move ' +
                    escapeHtml(stage.name) +
                    ' earlier"' +
                    (index === 0 ? " disabled" : "") +
                    '><i class="bi bi-arrow-up"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary"' +
                    ' data-stage-move="down" aria-label="Move ' +
                    escapeHtml(stage.name) +
                    ' later"' +
                    (index === stages.length - 1 ? " disabled" : "") +
                    '><i class="bi bi-arrow-down"></i></button>' +
                    "</div>" +
                    '<div class="phase-stage-body">' +
                    '<div class="fw-semibold">' +
                    escapeHtml(stage.name) +
                    "</div>" +
                    '<div class="text-secondary small">' +
                    escapeHtml(stage.default_description) +
                    "</div>" +
                    '<div class="phase-stage-usage">' +
                    "Used by " +
                    stage.type_count +
                    " project type" +
                    (stage.type_count === 1 ? "" : "s") +
                    " &middot; " +
                    stage.task_count +
                    " default task" +
                    (stage.task_count === 1 ? "" : "s") +
                    "</div>" +
                    "</div>" +
                    '<div class="phase-stage-actions">' +
                    '<button type="button" class="btn btn-sm btn-outline-primary" data-stage-edit' +
                    ' aria-label="Edit ' +
                    escapeHtml(stage.name) +
                    '"><i class="bi bi-pencil"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" data-stage-remove' +
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

        request(routes.phaseTemplateBase + "/stages", "POST", {
            name: name,
            default_description: description,
        })
            .then(function (data) {
                stageName.value = "";
                stageDescription.value = "";
                absorb(data);
                showSuccess(data.message);
                // The new stage is a tick box the open template can now use.
                reloadTemplate();
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
            const name = item.querySelector("[data-stage-edit-name]").value.trim();
            const description = item
                .querySelector("[data-stage-edit-description]")
                .value.trim();

            if (!name || !description) {
                showError("A stage needs a name and a description.");

                return;
            }

            request(routes.phaseTemplateBase + "/stages/" + stageId, "PUT", {
                name: name,
                default_description: description,
            })
                .then(function (data) {
                    editingStageId = null;
                    absorb(data);
                    showSuccess(data.message);
                    reloadTemplate();
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

            if (
                !window.confirm(
                    "Remove the stage “" +
                        stage.name +
                        "”? Projects already set up keep their phases.",
                )
            ) {
                return;
            }

            request(
                routes.phaseTemplateBase + "/stages/" + stageId,
                "DELETE",
            )
                .then(function (data) {
                    absorb(data);
                    showSuccess(data.message);
                    reloadTemplate();
                })
                .catch(function (error) {
                    showError(error.message);
                });

            return;
        }

        const move = event.target.closest("[data-stage-move]");

        if (move) {
            const index = stages.findIndex(function (candidate) {
                return candidate.stage_id === stageId;
            });

            const target = move.dataset.stageMove === "up" ? index - 1 : index + 1;

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

            request(routes.phaseTemplateBase + "/stages/reorder", "POST", {
                stage_ids: reordered.map(function (stage) {
                    return stage.stage_id;
                }),
            })
                .then(function (data) {
                    absorb(data);
                    showSuccess(data.message);
                    reloadTemplate();
                })
                .catch(function (error) {
                    showError(error.message);
                    load();
                });
        }
    });

    // ------------------------------------------------------------------
    // One type's template
    // ------------------------------------------------------------------

    function renderTemplate() {
        templateEmpty.classList.toggle("d-none", template.length > 0);
        saveButton.disabled = template.length === 0;

        stagesHolder.innerHTML = template
            .map(function (stage, index) {
                const tasks = stage.selected
                    ? '<div class="phase-template-tasks">' +
                      stage.tasks
                          .map(function (task, taskIndex) {
                              return taskMarkup(task, index, taskIndex);
                          })
                          .join("") +
                      '<button type="button" class="btn btn-sm btn-outline-primary mt-1"' +
                      ' data-template-task-add' +
                      (stage.tasks.length >= maxTasks ? " disabled" : "") +
                      '><i class="bi bi-plus-lg me-1"></i>Add Default Task</button>' +
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
                    "<span>" +
                    '<span class="fw-semibold">' +
                    escapeHtml(stage.name) +
                    "</span>" +
                    '<span class="text-secondary small d-block">' +
                    escapeHtml(stage.default_description) +
                    "</span>" +
                    "</span>" +
                    "</label>" +
                    tasks +
                    "</div>"
                );
            })
            .join("");
    }

    function taskMarkup(task, stageIndex, taskIndex) {
        const label =
            "Default task " + (taskIndex + 1) + " for stage " + (stageIndex + 1);

        return (
            '<div class="phase-template-task" data-template-task="' +
            taskIndex +
            '">' +
            '<div class="flex-grow-1">' +
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
            '<button type="button" class="btn btn-sm btn-outline-danger"' +
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
        // lost if Save is pressed while the stage is unticked, which is what
        // sending only the ticked stages means.
        renderTemplate();
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

    typeSelect.addEventListener("change", function () {
        currentTypeId = typeSelect.value || null;
        reloadTemplate();
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
                const task = stage.tasks[t];
                const title = task.title.trim();
                const description = task.description.trim();

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

        request(
            routes.phaseTemplateBase + "/types/" + currentTypeId,
            "PUT",
            {
                stages: selected.map(function (stage) {
                    return {
                        stage_id: stage.stage_id,
                        tasks: stage.tasks
                            .filter(function (task) {
                                return (
                                    task.title.trim() || task.description.trim()
                                );
                            })
                            .map(function (task) {
                                return {
                                    title: task.title.trim(),
                                    description: task.description.trim(),
                                };
                            }),
                    };
                }),
            },
        )
            .then(function (data) {
                template = normaliseTemplate(data.stages);
                types = data.types || types;
                renderTypes();
                renderTemplate();
                showError("");
                showSuccess(data.message);
                // The counts beside each stage have moved.
                return request(routes.phaseTemplates, "GET");
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
            renderTemplate();

            return;
        }

        return request(
            routes.phaseTemplateBase + "/types/" + currentTypeId,
            "GET",
        )
            .then(function (data) {
                template = normaliseTemplate(data.stages);
                maxTasks = data.max_tasks_per_stage || maxTasks;
                renderTemplate();
            })
            .catch(function (error) {
                showError(error.message);
            });
    }

    function renderTypes() {
        const previous = currentTypeId;

        typeSelect.innerHTML = types
            .map(function (type) {
                return (
                    '<option value="' +
                    type.type_id +
                    '">' +
                    escapeHtml(type.type_name) +
                    " — " +
                    type.stage_count +
                    " stage" +
                    (type.stage_count === 1 ? "" : "s") +
                    ", " +
                    type.task_count +
                    " task" +
                    (type.task_count === 1 ? "" : "s") +
                    "</option>"
                );
            })
            .join("");

        if (!types.length) {
            currentTypeId = null;

            return;
        }

        const stillThere = types.some(function (type) {
            return String(type.type_id) === String(previous);
        });

        currentTypeId = stillThere ? previous : String(types[0].type_id);
        typeSelect.value = currentTypeId;
    }

    /**
     * Take what a write handed back. Every endpoint answers with the whole
     * catalogue, so this is the only place the two lists are replaced.
     */
    function absorb(data) {
        stages = data.stages || stages;
        types = data.types || types;

        renderStages();
        renderTypes();
    }

    function load() {
        request(routes.phaseTemplates, "GET")
            .then(function (data) {
                stages = data.stages || [];
                types = data.types || [];
                maxTasks = data.max_tasks_per_stage || maxTasks;

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
