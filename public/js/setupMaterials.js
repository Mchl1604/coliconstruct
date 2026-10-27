/**
 * Step 2 of project setup: the materials the job needs.
 *
 * The rows live in an array, as the phases do in phaseSetup.js, and are drawn
 * as real inputs named materials[N][...] inside the setup form - so the Save
 * and Finalize buttons phaseSetup.js already drives send them with no help
 * from here. What this adds:
 *
 *   - the picker and the "Often used" chips, which add a row;
 *   - a check of every row before anything is sent, wired in through the
 *     form's `phase-setup:before-submit` event;
 *   - the materials line in the Finalize dialog;
 *   - Add to List, for a material the list does not have yet.
 *
 * Every value is written with textContent or as an input's value, so a
 * material name can never become markup.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const form = document.querySelector("[data-phase-setup-form]");
    const body = document.querySelector("[data-setup-material-rows]");
    const initial = document.querySelector("[data-setup-material-initial]");

    const stepTab = document.querySelector('[data-setup-step="materials"]');
    const countBadge = document.querySelector("[data-setup-material-count]");

    // Step navigation works whether or not the plan can be edited.
    document.querySelectorAll("[data-setup-go]").forEach(function (button) {
        button.addEventListener("click", function () {
            const tab = document.querySelector('[data-setup-step="' + button.dataset.setupGo + '"]');

            if (tab && window.bootstrap) {
                bootstrap.Tab.getOrCreateInstance(tab).show();
                window.scrollTo({ top: 0, behavior: "smooth" });
            }
        });
    });

    // The action bar's one button follows the step: Next on Phases,
    // Finish Setup on Materials.
    const nextButton = document.querySelector("[data-setup-next]");
    const finishButton = document.querySelector("[data-setup-finish]");

    document.querySelectorAll("[data-setup-step]").forEach(function (tab) {
        tab.addEventListener("shown.bs.tab", function () {
            const onMaterials = tab.dataset.setupStep === "materials";

            nextButton?.classList.toggle("d-none", onMaterials);
            finishButton?.classList.toggle("d-none", !onMaterials);
        });
    });

    if (!form || !body || !initial || !window.materialPicker) {
        return;
    }

    const search = document.querySelector("[data-setup-material-search]");
    const empty = document.querySelector("[data-setup-material-empty]");
    const errorEl = document.querySelector("[data-setup-material-error]");
    const summary = document.querySelector("[data-material-summary]");
    const quickAddModal = document.querySelector("[data-setup-quick-add]");
    const settings = document.querySelector("[data-material-suggest-url]");

    const suggestUrl = settings ? settings.dataset.materialSuggestUrl : "";
    const maxRows = Number(initial.dataset.maxMaterials) || 200;

    let rows = [];

    try {
        rows = JSON.parse(initial.dataset.setupMaterialInitial || "[]");
    } catch (error) {
        rows = [];
    }

    function show() {
        if (stepTab && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(stepTab).show();
        }
    }

    function setError(message) {
        errorEl.textContent = message || "";
        errorEl.classList.toggle("d-none", !message);
    }

    function cell(content, className) {
        const td = document.createElement("td");

        if (className) {
            td.className = className;
        }

        if (content instanceof Node) {
            td.appendChild(content);
        } else {
            td.textContent = content == null ? "" : String(content);
        }

        return td;
    }

    function input(name, value, attributes) {
        const element = document.createElement("input");

        element.name = name;
        element.value = value == null ? "" : String(value);

        Object.keys(attributes || {}).forEach(function (key) {
            element.setAttribute(key, attributes[key]);
        });

        return element;
    }

    /** Pull what was typed back into the array, so a redraw keeps it. */
    function sync() {
        body.querySelectorAll("[data-material-row]").forEach(function (rowEl) {
            const row = rows[Number(rowEl.dataset.materialRow)];

            if (!row) {
                return;
            }

            row.quantity = rowEl.querySelector("[data-material-qty]").value.trim();
            row.notes = rowEl.querySelector("[data-material-notes]").value;
        });
    }

    function render() {
        body.replaceChildren();

        rows.forEach(function (row, index) {
            const prefix = "materials[" + index + "]";
            const tr = document.createElement("tr");

            tr.dataset.materialRow = String(index);

            const name = document.createElement("div");
            const label = document.createElement("span");

            label.className = "material-name";
            label.textContent = row.name;
            name.appendChild(label);

            if (row.brand) {
                const brand = document.createElement("span");

                brand.className = "material-brand";
                brand.textContent = row.brand;
                name.appendChild(brand);
            }

            if (row.description) {
                const meta = document.createElement("div");

                meta.className = "material-meta";
                meta.textContent = row.description;
                name.appendChild(meta);
            }

            name.appendChild(input(prefix + "[material_id]", row.material_id, { type: "hidden" }));

            const unit = document.createElement("span");

            unit.className = "material-unit-chip";
            unit.textContent = row.unit;
            unit.title = row.unit_label;

            const qty = input(prefix + "[quantity]", row.quantity, {
                type: "number",
                class: "form-control form-control-sm material-qty-input",
                min: row.allows_fractions ? "0.01" : "1",
                step: row.allows_fractions ? "0.01" : "1",
                inputmode: row.allows_fractions ? "decimal" : "numeric",
                "aria-label": "Quantity of " + row.label,
                "data-material-qty": "",
            });

            const notes = input(prefix + "[notes]", row.notes, {
                type: "text",
                class: "form-control form-control-sm",
                maxlength: "255",
                placeholder: "Optional",
                "aria-label": "Notes for " + row.label,
                "data-material-notes": "",
            });

            const remove = document.createElement("button");

            remove.type = "button";
            remove.className = "btn btn-sm btn-outline-danger py-1 px-2";
            remove.title = "Remove";
            remove.setAttribute("aria-label", "Remove " + row.label);
            remove.dataset.materialRemove = String(index);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

            tr.append(
                cell(name),
                cell(row.type || ""),
                cell(unit),
                cell(qty),
                cell(notes),
                cell(remove, "text-center"),
            );

            body.appendChild(tr);
        });

        empty.classList.toggle("d-none", rows.length > 0);

        if (countBadge) {
            countBadge.textContent = rows.length ? String(rows.length) : "";
        }

        syncChips();
    }

    /** A chip for a material already on the list has nothing left to add. */
    function syncChips() {
        document.querySelectorAll("[data-setup-material-suggestion]").forEach(function (chip) {
            let material = null;

            try {
                material = JSON.parse(chip.dataset.setupMaterialSuggestion);
            } catch (error) {
                return;
            }

            chip.classList.toggle("d-none", ids().indexOf(material.material_id) !== -1);
        });
    }

    function ids() {
        return rows.map(function (row) {
            return Number(row.material_id);
        });
    }

    /**
     * Add a material, or - when it is already on the list - take the person
     * to its quantity instead of listing it twice.
     */
    function addMaterial(material) {
        sync();
        setError("");

        const existing = ids().indexOf(Number(material.material_id));

        if (existing === -1) {
            if (rows.length >= maxRows) {
                setError("At most " + maxRows + " materials.");

                return;
            }

            rows.push(Object.assign({}, material, { quantity: "", notes: "" }));
            render();
        }

        const index = existing === -1 ? rows.length - 1 : existing;
        const qty = body.querySelector('[data-material-row="' + index + '"] [data-material-qty]');

        if (qty) {
            qty.focus();
            qty.select();
        }
    }

    body.addEventListener("click", function (event) {
        const remove = event.target.closest("[data-material-remove]");

        if (!remove) {
            return;
        }

        sync();
        rows.splice(Number(remove.dataset.materialRemove), 1);
        render();
    });

    document.querySelectorAll("[data-setup-material-suggestion]").forEach(function (chip) {
        chip.addEventListener("click", function () {
            try {
                addMaterial(JSON.parse(chip.dataset.setupMaterialSuggestion));
            } catch (error) {
                // A chip that cannot be read adds nothing.
            }
        });
    });

    // ------------------------------------------------------------------
    // Add to List
    // ------------------------------------------------------------------

    function quickAddField(name) {
        return quickAddModal.querySelector('[data-quick-add-field="' + name + '"]');
    }

    function openQuickAdd(typedName) {
        if (!quickAddModal || !window.bootstrap) {
            return;
        }

        quickAddModal.querySelectorAll("[data-quick-add-field]").forEach(function (field) {
            field.value = "";
        });

        quickAddField("name").value = typedName || "";
        quickAddModal.querySelector("[data-quick-add-error]").classList.add("d-none");

        bootstrap.Modal.getOrCreateInstance(quickAddModal).show();
    }

    if (quickAddModal) {
        quickAddModal.addEventListener("shown.bs.modal", function () {
            quickAddField("name").focus();
        });

        quickAddModal.querySelector("[data-quick-add-save]").addEventListener("click", function () {
            const button = this;
            const errorBox = quickAddModal.querySelector("[data-quick-add-error]");
            const payload = {};

            quickAddModal.querySelectorAll("[data-quick-add-field]").forEach(function (field) {
                payload[field.dataset.quickAddField] = field.value.trim();
            });

            button.disabled = true;

            materialPicker.quickAdd(quickAddModal.dataset.url, payload).then(function (result) {
                button.disabled = false;

                if (!result.ok) {
                    errorBox.textContent = result.body.error || "Unable to add material.";
                    errorBox.classList.remove("d-none");

                    return;
                }

                bootstrap.Modal.getOrCreateInstance(quickAddModal).hide();
                addMaterial(result.body.material);

                if (window.adminShell && result.body.message) {
                    window.adminShell.showToast("success", result.body.message);
                }
            });
        });
    }

    if (search && suggestUrl) {
        materialPicker.attach(search, {
            url: suggestUrl,
            exclude: ids,
            onPick: addMaterial,
            onAddNew: quickAddModal ? openQuickAdd : null,
        });
    }

    // ------------------------------------------------------------------
    // Before Save or Finalize
    // ------------------------------------------------------------------

    /** What is wrong with the rows, or null. Mirrors ProjectMaterialPlan. */
    function problem() {
        for (let index = 0; index < rows.length; index++) {
            const row = rows[index];
            const value = Number(row.quantity);

            if (row.quantity === "" || !isFinite(value) || value <= 0) {
                return { index: index, message: row.label + ": enter a quantity above 0." };
            }

            if (!row.allows_fractions && Math.round(value) !== value) {
                return { index: index, message: row.label + ": whole numbers only." };
            }

            if (Math.abs(value * 100 - Math.round(value * 100)) > 1e-6) {
                return { index: index, message: row.label + ": use at most 2 decimals." };
            }
        }

        return null;
    }

    form.addEventListener("phase-setup:before-submit", function (event) {
        sync();

        const found = problem();

        if (!found) {
            setError("");

            return;
        }

        event.preventDefault();
        show();
        setError(found.message);

        const qty = body.querySelector('[data-material-row="' + found.index + '"] [data-material-qty]');

        if (qty) {
            qty.classList.add("is-invalid");
            qty.focus();
            qty.addEventListener(
                "input",
                function () {
                    qty.classList.remove("is-invalid");
                },
                { once: true },
            );
        }
    });

    // The materials line of the Finalize dialog, under the phases.
    const finalizeModal = document.getElementById("finalizePhasesModal");

    if (finalizeModal && summary) {
        finalizeModal.addEventListener("show.bs.modal", function () {
            sync();

            summary.replaceChildren();

            const heading = document.createElement("strong");

            if (!rows.length) {
                heading.textContent = "No materials listed.";
                summary.appendChild(heading);

                return;
            }

            heading.textContent = rows.length + (rows.length === 1 ? " material" : " materials") + " planned:";
            summary.appendChild(heading);

            const list = document.createElement("ul");

            list.className = "mb-0 ps-3";

            rows.slice(0, 8).forEach(function (row) {
                const item = document.createElement("li");

                item.textContent = row.label + " — " + (row.quantity || "?") + " " + row.unit;
                list.appendChild(item);
            });

            if (rows.length > 8) {
                const more = document.createElement("li");

                more.className = "text-secondary";
                more.textContent = "and " + (rows.length - 8) + " more";
                list.appendChild(more);
            }

            summary.appendChild(list);
        });
    }

    // A refused save comes back with the materials' reasons on step 2.
    if (document.querySelector("[data-material-errors]")) {
        show();
    }

    render();
});
