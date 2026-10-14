/**
 * Configuration -> System Settings -> Materials.
 *
 * The list a project's materials are picked from, and its groups. Every write
 * goes to the server and the list is redrawn from what it answers, so what is
 * on screen is always what was saved.
 *
 * Nothing is loaded until the panel is first shown: it sits hidden behind the
 * settings sidebar, and a DataTable built while hidden measures its columns
 * as zero wide.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const pane = document.getElementById("materialsPane");
    const routes = window.configurationRoutes || {};

    if (!pane || !routes.materials) {
        return;
    }

    const holder = pane.closest("[data-content-extra]");
    const modalEl = document.querySelector("[data-material-modal]");
    const modalForm = modalEl.querySelector("[data-material-form]");
    const modalError = modalEl.querySelector("[data-material-modal-error]");
    const modalTitle = modalEl.querySelector("[data-material-modal-title]");
    const saveButton = modalEl.querySelector("[data-material-save]");
    const saveSpinner = modalEl.querySelector("[data-material-save-spinner]");
    const unitLocked = modalEl.querySelector("[data-material-unit-locked]");
    const typesBar = pane.querySelector("[data-material-types]");
    const typeFilter = pane.querySelector("[data-materials-type-filter]");
    const statusFilter = pane.querySelector("[data-materials-status-filter]");
    const typeAddForm = pane.querySelector("[data-material-type-add-form]");
    const typeNameInput = pane.querySelector("[data-material-type-name]");
    const errorBox = pane.querySelector("[data-materials-error]");
    const successBox = pane.querySelector("[data-materials-success]");
    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";

    let table = null;
    let types = [];
    let renamingId = null;
    let started = false;

    function field(name) {
        return modalEl.querySelector('[data-material-field="' + name + '"]');
    }

    function showError(message) {
        errorBox.textContent = message || "";
        errorBox.classList.toggle("d-none", !message);
    }

    function showSuccess(message) {
        successBox.textContent = message || "";
        successBox.classList.toggle("d-none", !message);
    }

    function request(url, method, payload) {
        return fetch(url, {
            method: method,
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-Requested-With": "XMLHttpRequest",
            },
            body: payload ? JSON.stringify(payload) : undefined,
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (body) {
                        return { ok: response.ok, body: body };
                    });
            })
            .catch(function () {
                return { ok: false, body: { error: "Unable to reach the server." } };
            });
    }

    function redraw() {
        if (table) {
            table.draw(false);
        }
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    function fillTypeSelects() {
        const filterValue = typeFilter.value;
        const modalSelect = field("material_type_id");
        const modalValue = modalSelect.value;

        [typeFilter, modalSelect].forEach(function (select) {
            const first = select.options[0];

            select.replaceChildren(first);

            types.forEach(function (type) {
                const option = document.createElement("option");

                option.value = String(type.material_type_id);
                option.textContent = type.type_name;
                select.appendChild(option);
            });
        });

        typeFilter.value = filterValue;
        modalSelect.value = modalValue;
    }

    function renderTypes() {
        typesBar.replaceChildren();

        if (!types.length) {
            const none = document.createElement("span");

            none.className = "text-secondary small";
            none.textContent = "No types yet.";
            typesBar.appendChild(none);
        }

        types.forEach(function (type) {
            const chip = document.createElement("span");

            chip.className = "material-type-chip";

            if (renamingId === type.material_type_id) {
                const input = document.createElement("input");
                const save = document.createElement("button");
                const cancel = document.createElement("button");

                input.type = "text";
                input.maxLength = 100;
                input.value = type.type_name;
                input.className = "form-control form-control-sm";
                input.setAttribute("aria-label", "Type name");
                input.dataset.materialTypeInput = "";

                save.type = "button";
                save.dataset.materialTypeSave = String(type.material_type_id);
                save.title = "Save";
                save.innerHTML = '<i class="bi bi-check-lg" aria-hidden="true"></i>';

                cancel.type = "button";
                cancel.dataset.materialTypeCancel = "";
                cancel.title = "Cancel";
                cancel.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

                chip.append(input, save, cancel);
                typesBar.appendChild(chip);

                setTimeout(function () {
                    input.focus();
                    input.select();
                });

                return;
            }

            const name = document.createElement("span");
            const count = document.createElement("span");
            const rename = document.createElement("button");
            const remove = document.createElement("button");

            name.textContent = type.type_name;
            count.className = "material-type-count";
            count.textContent = "(" + type.material_count + ")";

            rename.type = "button";
            rename.dataset.materialTypeRename = String(type.material_type_id);
            rename.title = "Rename";
            rename.innerHTML = '<i class="bi bi-pencil" aria-hidden="true"></i>';

            remove.type = "button";
            remove.dataset.materialTypeRemove = String(type.material_type_id);
            remove.dataset.materialTypeLabel = type.type_name;
            remove.title = type.material_count ? "Has materials" : "Remove";
            remove.disabled = type.material_count > 0;
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

            chip.append(name, count, rename, remove);
            typesBar.appendChild(chip);
        });

        fillTypeSelects();
    }

    function loadTypes() {
        return request(routes.materialTypes, "GET").then(function (result) {
            if (!result.ok) {
                showError(result.body.error || "Unable to load types.");

                return;
            }

            types = result.body.types || [];
            renderTypes();
        });
    }

    function handleTypes(result) {
        if (!result.ok) {
            showError(result.body.error || "Unable to save type.");

            return false;
        }

        showError("");
        showSuccess(result.body.message || "");
        renamingId = null;
        types = result.body.types || [];
        renderTypes();
        redraw();

        return true;
    }

    typeAddForm.addEventListener("submit", function (event) {
        event.preventDefault();

        const name = typeNameInput.value.trim();

        if (!name) {
            showError("Enter a type name.");

            return;
        }

        request(routes.materialTypes, "POST", { type_name: name }).then(function (result) {
            if (handleTypes(result)) {
                typeNameInput.value = "";
            }
        });
    });

    typesBar.addEventListener("click", function (event) {
        const rename = event.target.closest("[data-material-type-rename]");

        if (rename) {
            renamingId = Number(rename.dataset.materialTypeRename);
            renderTypes();

            return;
        }

        if (event.target.closest("[data-material-type-cancel]")) {
            renamingId = null;
            renderTypes();

            return;
        }

        const save = event.target.closest("[data-material-type-save]");

        if (save) {
            const input = typesBar.querySelector("[data-material-type-input]");

            request(routes.materialTypes + "/" + save.dataset.materialTypeSave, "PUT", {
                type_name: input ? input.value.trim() : "",
            }).then(handleTypes);

            return;
        }

        const remove = event.target.closest("[data-material-type-remove]");

        if (remove && !remove.disabled && window.configurationConfirm) {
            const url = routes.materialTypes + "/" + remove.dataset.materialTypeRemove;

            window.configurationConfirm({
                title: 'Remove "' + remove.dataset.materialTypeLabel + '"?',
                body: "Only empty types can be removed.",
                label: "Remove Type",
                variant: "btn-danger",
                onConfirm: function () {
                    return request(url, "DELETE");
                },
                onSuccess: function (body) {
                    handleTypes({ ok: true, body: body });
                },
            });
        }
    });

    typesBar.addEventListener("keydown", function (event) {
        if (!event.target.matches("[data-material-type-input]")) {
            return;
        }

        if (event.key === "Enter") {
            event.preventDefault();
            typesBar.querySelector("[data-material-type-save]")?.click();
        }

        if (event.key === "Escape") {
            renamingId = null;
            renderTypes();
        }
    });

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------

    function startTable() {
        if (table || !window.serverTable) {
            return;
        }

        table = window.serverTable("#materialsTable", {
            url: routes.materials,
            params: function () {
                return { type: typeFilter.value, status: statusFilter.value };
            },
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            order: [[2, "asc"]],
            columnDefs: [{ targets: -1, orderable: false }],
            language: {
                search: "",
                searchPlaceholder: "Search materials...",
                emptyTable: "No materials yet.",
            },
        });
    }

    typeFilter.addEventListener("change", function () {
        if (table) {
            table.draw();
        }
    });

    statusFilter.addEventListener("change", function () {
        if (table) {
            table.draw();
        }
    });

    function openModal(material) {
        modalForm.reset();
        modalError.classList.add("d-none");

        field("material_id").value = material ? material.material_id : "";
        field("name").value = material ? material.name : "";
        field("brand").value = material && material.brand ? material.brand : "";
        field("material_type_id").value = material ? String(material.material_type_id) : "";
        field("unit").value = material ? material.unit : "";
        field("description").value = material && material.description ? material.description : "";

        // The server refuses a unit change on a used material; saying so
        // here saves the round trip.
        const locked = !!(material && material.is_used);

        field("unit").disabled = locked;
        unitLocked.classList.toggle("d-none", !locked);

        modalTitle.textContent = material ? "Edit Material" : "Add Material";

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    pane.querySelector("[data-material-new]").addEventListener("click", function () {
        openModal(null);
    });

    modalEl.addEventListener("shown.bs.modal", function () {
        field("name").focus();
    });

    modalForm.addEventListener("submit", function (event) {
        event.preventDefault();

        const id = field("material_id").value;
        const payload = {
            name: field("name").value.trim(),
            brand: field("brand").value.trim(),
            material_type_id: field("material_type_id").value,
            unit: field("unit").value,
            description: field("description").value.trim(),
        };

        saveButton.disabled = true;
        saveSpinner.classList.remove("d-none");

        request(id ? routes.materials + "/" + id : routes.materials, id ? "PUT" : "POST", payload).then(
            function (result) {
                saveButton.disabled = false;
                saveSpinner.classList.add("d-none");

                if (!result.ok) {
                    modalError.textContent = result.body.error || "Unable to save material.";
                    modalError.classList.remove("d-none");

                    return;
                }

                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                showError("");
                showSuccess(result.body.message || "");
                redraw();
                loadTypes();
            },
        );
    });

    pane.querySelector("#materialsTable").addEventListener("click", function (event) {
        const edit = event.target.closest("[data-material-edit]");

        if (edit) {
            try {
                openModal(JSON.parse(edit.dataset.materialEdit));
            } catch (error) {
                showError("Unable to open that material.");
            }

            return;
        }

        const toggle = event.target.closest("[data-material-toggle]");

        if (toggle) {
            const activate = toggle.dataset.materialActive !== "1";

            toggle.disabled = true;

            request(routes.materials + "/" + toggle.dataset.materialToggle + "/status", "PUT", {
                is_active: activate,
            }).then(function (result) {
                toggle.disabled = false;

                if (!result.ok) {
                    showError(result.body.error || "Unable to change status.");

                    return;
                }

                showError("");
                showSuccess(result.body.message || "");
                redraw();
            });

            return;
        }

        const remove = event.target.closest("[data-material-delete]");

        if (remove && !remove.disabled && window.configurationConfirm) {
            const url = routes.materials + "/" + remove.dataset.materialDelete;

            window.configurationConfirm({
                title: 'Remove "' + remove.dataset.materialLabel + '"?',
                body: "Unused materials only.",
                label: "Remove Material",
                variant: "btn-danger",
                onConfirm: function () {
                    return request(url, "DELETE");
                },
                onSuccess: function (body) {
                    showError("");
                    showSuccess(body.message || "");
                    redraw();
                    loadTypes();
                },
            });
        }
    });

    // ------------------------------------------------------------------
    // First shown
    // ------------------------------------------------------------------

    function start() {
        if (holder && holder.hidden) {
            return;
        }

        if (!started) {
            started = true;
            loadTypes();
            startTable();

            return;
        }

        if (table) {
            table.columns.adjust();
        }
    }

    if (holder) {
        new MutationObserver(start).observe(holder, { attributes: true, attributeFilter: ["hidden"] });
    }

    // The Configuration tab itself may open later than the panel is unhidden.
    document.addEventListener("shown.bs.tab", start);

    start();
});
