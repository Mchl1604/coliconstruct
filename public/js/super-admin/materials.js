/**
 * The Materials page.
 *
 * The list a project's materials are picked from. Every write goes to the
 * server and the list is redrawn from what it answers, so what is on screen is
 * always what was saved.
 *
 * The types, sub-types and brands a material is filed under are kept in
 * Configuration -> Inventory Settings (materialSettings.js). This page only
 * reads them, for its filters and for the choices in its dialog.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const pane = document.getElementById("materialsPane");
    const routes = window.inventoryRoutes || {};

    if (!pane || !routes.materials) {
        return;
    }

    const modalEl = document.querySelector("[data-material-modal]");
    const modalForm = modalEl.querySelector("[data-material-form]");
    const modalError = modalEl.querySelector("[data-material-modal-error]");
    const modalTitle = modalEl.querySelector("[data-material-modal-title]");
    const saveButton = modalEl.querySelector("[data-material-save]");
    const saveSpinner = modalEl.querySelector("[data-material-save-spinner]");
    const unitLocked = modalEl.querySelector("[data-material-unit-locked]");
    const typeFilter = pane.querySelector("[data-materials-type-filter]");
    const subtypeFilter = pane.querySelector("[data-materials-subtype-filter]");
    const errorBox = pane.querySelector("[data-materials-error]");
    const successBox = pane.querySelector("[data-materials-success]");
    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";

    let table = null;
    let types = [];
    let brands = [];

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
    // Choices: types, their sub-types, and brands
    // ------------------------------------------------------------------

    function subtypesOf(typeId) {
        const type = types.find(function (each) {
            return String(each.material_type_id) === String(typeId);
        });

        return type ? type.subtypes || [] : [];
    }

    /**
     * Refill a select from a list, keeping the choice when it is still in it.
     */
    function fillSelect(select, items, valueKey, labelKey) {
        const value = select.value;

        select.replaceChildren(select.options[0]);

        items.forEach(function (item) {
            const option = document.createElement("option");

            option.value = String(item[valueKey]);
            option.textContent = item[labelKey];
            select.appendChild(option);
        });

        select.value = items.some(function (item) {
            return String(item[valueKey]) === value;
        })
            ? value
            : "";
    }

    /**
     * A sub-type select offers one type's own, and is disabled when there is
     * nothing to choose.
     */
    function fillSubtypeSelect(select, typeId) {
        const subtypes = subtypesOf(typeId);

        fillSelect(select, subtypes, "material_subtype_id", "subtype_name");
        select.disabled = !subtypes.length;
    }

    function fillChoices() {
        fillSelect(typeFilter, types, "material_type_id", "type_name");
        fillSelect(field("material_type_id"), types, "material_type_id", "type_name");
        fillSubtypeSelect(subtypeFilter, typeFilter.value);
        fillSubtypeSelect(field("material_subtype_id"), field("material_type_id").value);
        fillSelect(field("brand_id"), brands, "brand_id", "brand_name");
    }

    function loadChoices() {
        return Promise.all([request(routes.materialTypes, "GET"), request(routes.brands, "GET")]).then(
            function (results) {
                if (!results[0].ok || !results[1].ok) {
                    showError("Unable to load types and brands.");

                    return;
                }

                types = results[0].body.types || [];
                brands = results[1].body.brands || [];
                fillChoices();
            },
        );
    }

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
                return { type: typeFilter.value, subtype: subtypeFilter.value };
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
        subtypeFilter.value = "";
        fillSubtypeSelect(subtypeFilter, typeFilter.value);

        if (table) {
            table.draw();
        }
    });

    subtypeFilter.addEventListener("change", function () {
        if (table) {
            table.draw();
        }
    });

    // ------------------------------------------------------------------
    // Add / Edit
    // ------------------------------------------------------------------

    // The dialog's sub-types follow its type.
    field("material_type_id").addEventListener("change", function () {
        fillSubtypeSelect(field("material_subtype_id"), field("material_type_id").value);
    });

    function openModal(material) {
        modalForm.reset();
        modalError.classList.add("d-none");

        field("material_id").value = material ? material.material_id : "";
        field("name").value = material ? material.name : "";
        field("brand_id").value = material && material.brand_id ? String(material.brand_id) : "";
        field("material_type_id").value = material ? String(material.material_type_id) : "";
        fillSubtypeSelect(field("material_subtype_id"), field("material_type_id").value);
        field("material_subtype_id").value =
            material && material.material_subtype_id ? String(material.material_subtype_id) : "";
        field("unit").value = material ? material.unit : "";
        field("description").value = material && material.description ? material.description : "";
        field("reorder_level").value =
            material && material.reorder_level !== null && material.reorder_level !== undefined
                ? String(material.reorder_level)
                : "";

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
            brand_id: field("brand_id").value,
            material_type_id: field("material_type_id").value,
            material_subtype_id: field("material_subtype_id").value,
            unit: field("unit").value,
            description: field("description").value.trim(),
            reorder_level: field("reorder_level").value.trim(),
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

        const remove = event.target.closest("[data-material-delete]");

        if (remove && !remove.disabled && window.confirmDialog) {
            const url = routes.materials + "/" + remove.dataset.materialDelete;

            window
                .confirmDialog({
                    title: 'Remove "' + remove.dataset.materialLabel + '"?',
                    body: "Unused materials only.",
                    label: "Remove Material",
                    onConfirm: function () {
                        return request(url, "DELETE");
                    },
                })
                .then(function (body) {
                    if (!body) {
                        return;
                    }

                    showError("");
                    showSuccess(body.message || "");
                    redraw();
                });
        }
    });

    loadChoices();
    startTable();
});
