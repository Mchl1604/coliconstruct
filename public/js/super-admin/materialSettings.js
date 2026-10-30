/**
 * Configuration -> Inventory Settings.
 *
 * The lists every material is filed under: its types, the sub-types within
 * each, and its brands. Every write goes to the server, which answers with the
 * whole list again, and the tables are redrawn from that - so what is on
 * screen is always what was saved.
 *
 * Asks its destructive questions through Configuration's own dialog when the
 * page has one (configuration.js), and through the shared one otherwise - a
 * Purchasing Admin's Configuration is this tab alone, without that script.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const pane = document.getElementById("materialSettingsPane");
    const routes = window.materialSettingsRoutes || {};

    if (!pane || !routes.materialTypes) {
        return;
    }

    const typesBar = pane.querySelector("[data-material-types]");
    const typeAddForm = pane.querySelector("[data-material-type-add-form]");
    const typeNameInput = pane.querySelector("[data-material-type-name]");
    const brandsBar = pane.querySelector("[data-brands]");
    const brandAddForm = pane.querySelector("[data-brand-add-form]");
    const brandNameInput = pane.querySelector("[data-brand-name]");
    const errorBox = pane.querySelector("[data-material-settings-error]");
    const successBox = pane.querySelector("[data-material-settings-success]");

    const viewModalEl = document.querySelector("[data-material-type-view-modal]");
    const viewTitle = viewModalEl.querySelector("[data-material-type-view-title]");
    const viewRows = viewModalEl.querySelector("[data-material-type-view-rows]");

    const subtypeModalEl = document.querySelector("[data-material-subtype-modal]");
    const subtypeTitle = subtypeModalEl.querySelector("[data-material-subtype-title]");
    const subtypeRows = subtypeModalEl.querySelector("[data-material-subtype-rows]");
    const subtypeError = subtypeModalEl.querySelector("[data-material-subtype-error]");
    const subtypeAddForm = subtypeModalEl.querySelector("[data-material-subtype-add-form]");
    const subtypeNameInput = subtypeModalEl.querySelector("[data-material-subtype-name]");

    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";

    let types = [];
    let renamingId = null;
    let brands = [];
    let renamingBrandId = null;
    let subtypeTypeId = null;
    let renamingSubtypeId = null;
    let removingSubtypeId = null;
    let started = false;

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

    /**
     * Ask before removing something, with the request run by the dialog so a
     * refusal is shown inside it.
     */
    function askToRemove(settings) {
        if (window.configurationConfirm) {
            window.configurationConfirm({
                title: settings.title,
                body: settings.body,
                label: settings.label,
                variant: "btn-danger",
                onConfirm: settings.onConfirm,
                onSuccess: settings.onSuccess,
            });

            return;
        }

        if (!window.confirmDialog) {
            return;
        }

        window
            .confirmDialog({
                title: settings.title,
                body: settings.body,
                label: settings.label,
                onConfirm: settings.onConfirm,
            })
            .then(function (body) {
                if (body) {
                    settings.onSuccess(body);
                }
            });
    }

    function iconButton(className, icon, title) {
        const button = document.createElement("button");

        button.type = "button";
        button.className = "btn btn-sm py-1 px-2 " + className;
        button.title = title;
        button.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i>';

        return button;
    }

    function messageRow(body, columns, text) {
        const row = document.createElement("tr");
        const cell = document.createElement("td");

        cell.colSpan = columns;
        cell.className = "text-secondary small";
        cell.textContent = text;
        row.appendChild(cell);
        body.appendChild(row);
    }

    /**
     * The name cell of a row being renamed: an input that takes focus, with
     * Save and Cancel beside it.
     */
    function renameControls(nameCell, actions, value, label, dataName, saveId) {
        const input = document.createElement("input");
        const save = iconButton("btn-outline-success me-1", "bi-check-lg", "Save");
        const cancel = iconButton("btn-outline-secondary", "bi-x-lg", "Cancel");

        input.type = "text";
        input.maxLength = 100;
        input.value = value;
        input.className = "form-control form-control-sm";
        input.setAttribute("aria-label", label);
        input.dataset[dataName + "Input"] = "";

        save.dataset[dataName + "Save"] = String(saveId);
        cancel.dataset[dataName + "Cancel"] = "";

        nameCell.appendChild(input);
        actions.append(save, cancel);

        setTimeout(function () {
            input.focus();
            input.select();
        });
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    function renderTypes() {
        typesBar.replaceChildren();

        if (!types.length) {
            messageRow(typesBar, 4, "No types yet.");
        }

        types.forEach(function (type) {
            const row = document.createElement("tr");
            const nameCell = document.createElement("td");
            const subtypesCell = document.createElement("td");
            const countCell = document.createElement("td");
            const actions = document.createElement("td");

            actions.className = "text-center text-nowrap";
            subtypesCell.className = "small";

            if ((type.subtypes || []).length) {
                type.subtypes.forEach(function (subtype) {
                    const chip = document.createElement("span");

                    chip.className = "material-subtype-chip";
                    chip.textContent = subtype.subtype_name;
                    subtypesCell.appendChild(chip);
                });
            } else {
                subtypesCell.classList.add("text-secondary");
                subtypesCell.textContent = "–";
            }

            if (renamingId === type.material_type_id) {
                renameControls(nameCell, actions, type.type_name, "Type name", "materialType", type.material_type_id);
            } else {
                const view = iconButton("btn-outline-info me-1", "bi-eye", "View");
                const subtypes = iconButton("btn-outline-secondary me-1", "bi-diagram-3", "Sub-types");
                const rename = iconButton("btn-outline-primary me-1", "bi-pencil", "Edit");
                const remove = iconButton("btn-outline-danger", "bi-trash3", type.material_count ? "Has materials" : "Remove");

                nameCell.className = "fw-semibold";
                nameCell.textContent = type.type_name;

                view.dataset.materialTypeView = String(type.material_type_id);
                subtypes.dataset.materialTypeSubtypes = String(type.material_type_id);
                rename.dataset.materialTypeRename = String(type.material_type_id);
                remove.dataset.materialTypeRemove = String(type.material_type_id);
                remove.dataset.materialTypeLabel = type.type_name;
                remove.disabled = type.material_count > 0;

                actions.append(view, subtypes, rename, remove);
            }

            countCell.textContent = String(type.material_count);

            row.append(nameCell, subtypesCell, countCell, actions);
            typesBar.appendChild(row);
        });

        renderSubtypes();
    }

    function viewType(id) {
        viewTitle.textContent = "Materials";
        viewRows.replaceChildren();
        messageRow(viewRows, 3, "Loading materials…");

        bootstrap.Modal.getOrCreateInstance(viewModalEl).show();

        request(routes.materialTypes + "/" + id, "GET").then(function (result) {
            viewRows.replaceChildren();

            if (!result.ok) {
                messageRow(viewRows, 3, result.body.error || "Unable to load materials.");

                return;
            }

            viewTitle.textContent = result.body.type_name;

            const materials = result.body.materials || [];

            if (!materials.length) {
                messageRow(viewRows, 3, "No materials yet.");
            }

            materials.forEach(function (material) {
                const row = document.createElement("tr");
                const name = document.createElement("td");
                const brand = document.createElement("td");
                const unit = document.createElement("td");

                if (!material.is_active) {
                    row.className = "text-secondary";
                }

                name.textContent = material.name;

                if (material.subtype) {
                    const meta = document.createElement("div");

                    meta.className = "material-meta";
                    meta.textContent = material.subtype;
                    name.appendChild(meta);
                }

                brand.textContent = material.brand || "–";
                unit.textContent = material.unit_label;

                row.append(name, brand, unit);
                viewRows.appendChild(row);
            });
        });
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
        const view = event.target.closest("[data-material-type-view]");

        if (view) {
            viewType(view.dataset.materialTypeView);

            return;
        }

        const subtypes = event.target.closest("[data-material-type-subtypes]");

        if (subtypes) {
            openSubtypes(subtypes.dataset.materialTypeSubtypes);

            return;
        }

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

        if (remove && !remove.disabled) {
            const url = routes.materialTypes + "/" + remove.dataset.materialTypeRemove;

            askToRemove({
                title: 'Remove "' + remove.dataset.materialTypeLabel + '"?',
                body: "Only empty types can be removed.",
                label: "Remove Type",
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
    // Sub-types
    //
    // One type's at a time, in materialSubtypeModal. Every answer from the
    // server is the whole type list, so the Material Types table and the
    // dialog redraw from the same data.
    // ------------------------------------------------------------------

    function showSubtypeError(message) {
        subtypeError.textContent = message || "";
        subtypeError.classList.toggle("d-none", !message);
    }

    function renderSubtypes() {
        if (subtypeTypeId === null) {
            return;
        }

        const type = types.find(function (each) {
            return each.material_type_id === subtypeTypeId;
        });

        subtypeRows.replaceChildren();

        if (!type) {
            messageRow(subtypeRows, 3, "That type no longer exists.");

            return;
        }

        subtypeTitle.textContent = type.type_name + " Sub-types";

        if (!type.subtypes.length) {
            messageRow(subtypeRows, 3, "No sub-types yet.");
        }

        type.subtypes.forEach(function (subtype) {
            const row = document.createElement("tr");
            const nameCell = document.createElement("td");
            const countCell = document.createElement("td");
            const actions = document.createElement("td");
            const id = subtype.material_subtype_id;

            actions.className = "text-center text-nowrap";
            countCell.textContent = String(subtype.material_count);

            if (renamingSubtypeId === id) {
                renameControls(nameCell, actions, subtype.subtype_name, "Sub-type name", "subtype", id);
            } else if (removingSubtypeId === id) {
                // Asked in the row rather than in a second dialog over this
                // one: an empty sub-type is all that can be removed.
                const confirm = iconButton("btn-danger me-1", "bi-check-lg", "Remove");
                const cancel = iconButton("btn-outline-secondary", "bi-x-lg", "Keep");

                nameCell.textContent = "Remove " + subtype.subtype_name + "?";
                nameCell.className = "fw-semibold text-danger";

                confirm.dataset.subtypeRemoveConfirm = String(id);
                cancel.dataset.subtypeCancel = "";

                actions.append(confirm, cancel);
            } else {
                const rename = iconButton("btn-outline-primary me-1", "bi-pencil", "Edit");
                const remove = iconButton("btn-outline-danger", "bi-trash3", subtype.material_count ? "Has materials" : "Remove");

                nameCell.className = "fw-semibold";
                nameCell.textContent = subtype.subtype_name;

                rename.dataset.subtypeRename = String(id);
                remove.dataset.subtypeRemove = String(id);
                remove.disabled = subtype.material_count > 0;

                actions.append(rename, remove);
            }

            row.append(nameCell, countCell, actions);
            subtypeRows.appendChild(row);
        });
    }

    function openSubtypes(typeId) {
        subtypeTypeId = Number(typeId);
        renamingSubtypeId = null;
        removingSubtypeId = null;
        subtypeNameInput.value = "";
        showSubtypeError("");
        renderSubtypes();

        bootstrap.Modal.getOrCreateInstance(subtypeModalEl).show();
    }

    function handleSubtypes(result) {
        if (!result.ok) {
            showSubtypeError(result.body.error || "Unable to save sub-type.");

            return false;
        }

        showSubtypeError("");
        showSuccess(result.body.message || "");
        renamingSubtypeId = null;
        removingSubtypeId = null;
        types = result.body.types || [];
        renderTypes();

        return true;
    }

    subtypeModalEl.addEventListener("shown.bs.modal", function () {
        subtypeNameInput.focus();
    });

    subtypeAddForm.addEventListener("submit", function (event) {
        event.preventDefault();

        const name = subtypeNameInput.value.trim();

        if (!name) {
            showSubtypeError("Enter a sub-type name.");

            return;
        }

        request(routes.materialTypes + "/" + subtypeTypeId + "/subtypes", "POST", { subtype_name: name }).then(
            function (result) {
                if (handleSubtypes(result)) {
                    subtypeNameInput.value = "";
                }
            },
        );
    });

    subtypeRows.addEventListener("click", function (event) {
        const rename = event.target.closest("[data-subtype-rename]");

        if (rename) {
            renamingSubtypeId = Number(rename.dataset.subtypeRename);
            removingSubtypeId = null;
            renderSubtypes();

            return;
        }

        if (event.target.closest("[data-subtype-cancel]")) {
            renamingSubtypeId = null;
            removingSubtypeId = null;
            renderSubtypes();

            return;
        }

        const save = event.target.closest("[data-subtype-save]");

        if (save) {
            const input = subtypeRows.querySelector("[data-subtype-input]");

            request(routes.subtypes + "/" + save.dataset.subtypeSave, "PUT", {
                subtype_name: input ? input.value.trim() : "",
            }).then(handleSubtypes);

            return;
        }

        const remove = event.target.closest("[data-subtype-remove]");

        if (remove && !remove.disabled) {
            removingSubtypeId = Number(remove.dataset.subtypeRemove);
            renamingSubtypeId = null;
            renderSubtypes();

            return;
        }

        const confirm = event.target.closest("[data-subtype-remove-confirm]");

        if (confirm) {
            request(routes.subtypes + "/" + confirm.dataset.subtypeRemoveConfirm, "DELETE").then(handleSubtypes);
        }
    });

    subtypeRows.addEventListener("keydown", function (event) {
        if (!event.target.matches("[data-subtype-input]")) {
            return;
        }

        if (event.key === "Enter") {
            event.preventDefault();
            subtypeRows.querySelector("[data-subtype-save]")?.click();
        }

        if (event.key === "Escape") {
            // Kept from the dialog, which would otherwise close on it.
            event.stopPropagation();
            renamingSubtypeId = null;
            renderSubtypes();
        }
    });

    // ------------------------------------------------------------------
    // Brands
    // ------------------------------------------------------------------

    function renderBrands() {
        brandsBar.replaceChildren();

        if (!brands.length) {
            messageRow(brandsBar, 3, "No brands yet.");
        }

        brands.forEach(function (brand) {
            const row = document.createElement("tr");
            const nameCell = document.createElement("td");
            const countCell = document.createElement("td");
            const actions = document.createElement("td");

            actions.className = "text-center text-nowrap";

            if (renamingBrandId === brand.brand_id) {
                renameControls(nameCell, actions, brand.brand_name, "Brand name", "brand", brand.brand_id);
            } else {
                const rename = iconButton("btn-outline-primary me-1", "bi-pencil", "Edit");
                const remove = iconButton("btn-outline-danger", "bi-trash3", brand.material_count ? "Has materials" : "Remove");

                nameCell.className = "fw-semibold";
                nameCell.textContent = brand.brand_name;

                rename.dataset.brandRename = String(brand.brand_id);
                remove.dataset.brandRemove = String(brand.brand_id);
                remove.dataset.brandLabel = brand.brand_name;
                remove.disabled = brand.material_count > 0;

                actions.append(rename, remove);
            }

            countCell.textContent = String(brand.material_count);

            row.append(nameCell, countCell, actions);
            brandsBar.appendChild(row);
        });
    }

    function loadBrands() {
        return request(routes.brands, "GET").then(function (result) {
            if (!result.ok) {
                showError(result.body.error || "Unable to load brands.");

                return;
            }

            brands = result.body.brands || [];
            renderBrands();
        });
    }

    function handleBrands(result) {
        if (!result.ok) {
            showError(result.body.error || "Unable to save brand.");

            return false;
        }

        showError("");
        showSuccess(result.body.message || "");
        renamingBrandId = null;
        brands = result.body.brands || [];
        renderBrands();

        return true;
    }

    brandAddForm.addEventListener("submit", function (event) {
        event.preventDefault();

        const name = brandNameInput.value.trim();

        if (!name) {
            showError("Enter a brand name.");

            return;
        }

        request(routes.brands, "POST", { brand_name: name }).then(function (result) {
            if (handleBrands(result)) {
                brandNameInput.value = "";
            }
        });
    });

    brandsBar.addEventListener("click", function (event) {
        const rename = event.target.closest("[data-brand-rename]");

        if (rename) {
            renamingBrandId = Number(rename.dataset.brandRename);
            renderBrands();

            return;
        }

        if (event.target.closest("[data-brand-cancel]")) {
            renamingBrandId = null;
            renderBrands();

            return;
        }

        const save = event.target.closest("[data-brand-save]");

        if (save) {
            const input = brandsBar.querySelector("[data-brand-input]");

            request(routes.brands + "/" + save.dataset.brandSave, "PUT", {
                brand_name: input ? input.value.trim() : "",
            }).then(handleBrands);

            return;
        }

        const remove = event.target.closest("[data-brand-remove]");

        if (remove && !remove.disabled) {
            const url = routes.brands + "/" + remove.dataset.brandRemove;

            askToRemove({
                title: 'Remove "' + remove.dataset.brandLabel + '"?',
                body: "Only unused brands can be removed.",
                label: "Remove Brand",
                onConfirm: function () {
                    return request(url, "DELETE");
                },
                onSuccess: function (body) {
                    handleBrands({ ok: true, body: body });
                },
            });
        }
    });

    brandsBar.addEventListener("keydown", function (event) {
        if (!event.target.matches("[data-brand-input]")) {
            return;
        }

        if (event.key === "Enter") {
            event.preventDefault();
            brandsBar.querySelector("[data-brand-save]")?.click();
        }

        if (event.key === "Escape") {
            renamingBrandId = null;
            renderBrands();
        }
    });

    // ------------------------------------------------------------------
    // First shown
    // ------------------------------------------------------------------

    function start() {
        if (started) {
            return;
        }

        started = true;
        loadTypes();
        loadBrands();
    }

    start();
});
