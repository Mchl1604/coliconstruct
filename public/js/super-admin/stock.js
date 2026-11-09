/**
 * The Inventory page: every material with its total, assigned and available.
 *
 * The buttons above the table and on its rows open one dialog for Stock In and
 * Stock Out, which takes several materials at once. Every write goes to the
 * server, which answers with fresh figures for the cards above the table, and
 * the table is redrawn from it - so what is on screen is always what was saved.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const routes = window.inventoryRoutes || {};
    const options = window.inventoryOptions || {};
    const stockTableEl = document.getElementById("stockTable");

    if (!stockTableEl || !routes.stock) {
        return;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const errorBox = document.querySelector("[data-stock-error]");
    const successBox = document.querySelector("[data-stock-success]");
    const statusFilter = document.querySelector("[data-stock-status-filter]");
    const typeFilter = document.querySelector("[data-stock-type-filter]");
    const subtypeFilter = document.querySelector("[data-stock-subtype-filter]");

    const modalEl = document.querySelector("[data-stock-modal]");
    const modalForm = modalEl.querySelector("[data-stock-form]");
    const modalTitle = modalEl.querySelector("[data-stock-modal-title]");
    const modalError = modalEl.querySelector("[data-stock-modal-error]");
    const searchInput = modalEl.querySelector("[data-stock-search]");
    const linesEl = modalEl.querySelector("[data-stock-lines]");
    const linesEmpty = modalEl.querySelector("[data-stock-lines-empty]");
    const saveButton = modalEl.querySelector("[data-stock-save]");
    const saveSpinner = modalEl.querySelector("[data-stock-save-spinner]");

    const TITLES = { in: "Stock In", out: "Stock Out" };

    let stockTable = null;
    let mode = "in";
    const types = options.types || [];

    function field(name) {
        return modalEl.querySelector('[data-stock-field="' + name + '"]');
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

    function formatQuantity(value) {
        const number = Math.round(Number(value) * 100) / 100;

        return number.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    // ------------------------------------------------------------------
    // Figures
    // ------------------------------------------------------------------

    function updateSummary(summary) {
        if (!summary) {
            return;
        }

        Object.keys(summary).forEach(function (key) {
            const value = document.querySelector('[data-stat="' + key + '"]');

            if (value) {
                value.textContent = String(summary[key]);
            }
        });
    }

    document.querySelectorAll("[data-stat-filter]").forEach(function (button) {
        button.addEventListener("click", function () {
            statusFilter.value = button.dataset.statFilter;
            redrawStock(true);
        });
    });

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    function redrawStock(fromStart) {
        if (stockTable) {
            stockTable.draw(fromStart ? undefined : false);
        }
    }

    function startStockTable() {
        if (stockTable || !window.serverTable) {
            return;
        }

        stockTable = window.serverTable("#stockTable", {
            url: routes.stock,
            params: function () {
                return { stock: statusFilter.value, type: typeFilter.value, subtype: subtypeFilter.value };
            },
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            order: [[0, "asc"]],
            columnDefs: [{ targets: [3, 4, 5, 6], orderable: false }],
            language: {
                search: "",
                searchPlaceholder: "Search stock...",
                emptyTable: "No materials yet.",
                zeroRecords: "Nothing matches.",
            },
        });
    }

    statusFilter.addEventListener("change", function () {
        redrawStock(true);
    });

    /**
     * The Sub-type filter offers the chosen type's own, and nothing until a
     * type is chosen.
     */
    function fillSubtypeFilter() {
        const value = subtypeFilter.value;
        const type = types.find(function (each) {
            return String(each.material_type_id) === typeFilter.value;
        });
        const subtypes = type ? type.subtypes || [] : [];

        subtypeFilter.replaceChildren(subtypeFilter.options[0]);

        subtypes.forEach(function (subtype) {
            const option = document.createElement("option");

            option.value = String(subtype.material_subtype_id);
            option.textContent = subtype.subtype_name;
            subtypeFilter.appendChild(option);
        });

        subtypeFilter.value = subtypes.some(function (subtype) {
            return String(subtype.material_subtype_id) === value;
        })
            ? value
            : "";
        subtypeFilter.disabled = !subtypes.length;
    }

    typeFilter.addEventListener("change", function () {
        subtypeFilter.value = "";
        fillSubtypeFilter();
        redrawStock(true);
    });

    subtypeFilter.addEventListener("change", function () {
        redrawStock(true);
    });

    // ------------------------------------------------------------------
    // The dialog
    // ------------------------------------------------------------------

    /**
     * Show the fields this mode uses. "in" splits again on Received As: a
     * return names the project it came from, a delivery has none.
     */
    function applyMode() {
        const incoming = field("movement_type").value === "returned" ? "in-return" : "in-delivery";
        const active = mode === "in" ? ["in", incoming] : [mode];

        modalEl.querySelectorAll("[data-stock-for]").forEach(function (group) {
            const modes = group.dataset.stockFor.split(" ");

            group.classList.toggle(
                "d-none",
                !modes.some(function (each) {
                    return active.indexOf(each) !== -1;
                }),
            );
        });

        // Stock goes out to work still being done; a return may come back
        // from a job that has since closed.
        field("project_id")
            .querySelectorAll("option[data-open]")
            .forEach(function (option) {
                option.disabled = mode === "out" && option.dataset.open !== "1";
            });

        const project = field("project_id");

        if (project.selectedOptions[0]?.disabled) {
            project.value = "";
        }

        showChosenProject();
    }

    // ------------------------------------------------------------------
    // The project: searched, then shown as the one card the batch goes to
    // ------------------------------------------------------------------

    const projectSearch = modalEl.querySelector("[data-stock-project-search]");
    const projectMenu = modalEl.querySelector("[data-stock-project-menu]");
    const projectPicker = modalEl.querySelector("[data-stock-project-picker]");
    const projectChosen = modalEl.querySelector("[data-stock-project-chosen]");
    const projectLabel = modalEl.querySelector("[data-stock-project-label]");
    let projectMatches = [];
    let projectActive = -1;

    function showChosenProject() {
        const option = field("project_id").selectedOptions[0];
        const chosen = option && option.value !== "";

        projectLabel.textContent = chosen ? option.textContent.trim() : "";
        projectChosen.classList.toggle("d-none", !chosen);
        projectPicker.classList.toggle("d-none", chosen);
        closeProjectMenu();
    }

    function closeProjectMenu() {
        projectMenu.classList.add("d-none");
        projectSearch.setAttribute("aria-expanded", "false");
        projectActive = -1;
    }

    function chooseProject(option) {
        field("project_id").value = option.value;
        projectSearch.value = "";
        showChosenProject();
    }

    function highlightProject(index) {
        projectActive = index;
        projectMenu.querySelectorAll(".material-picker-option").forEach(function (item, itemIndex) {
            item.classList.toggle("is-active", itemIndex === index);

            if (itemIndex === index) {
                item.scrollIntoView({ block: "nearest" });
            }
        });
    }

    /** The projects this mode may name whose label holds every typed word. */
    function renderProjectMenu() {
        const words = projectSearch.value.toLowerCase().split(/\s+/).filter(Boolean);

        projectMatches = Array.from(field("project_id").options).filter(function (option) {
            const label = option.textContent.toLowerCase();

            return (
                option.value !== "" &&
                !option.disabled &&
                words.every(function (word) {
                    return label.indexOf(word) !== -1;
                })
            );
        });

        projectMenu.replaceChildren();

        if (!projectMatches.length) {
            const empty = document.createElement("div");

            empty.className = "material-picker-empty";
            empty.textContent = "No matching projects.";
            projectMenu.appendChild(empty);
        }

        projectMatches.slice(0, 50).forEach(function (option, index) {
            const item = document.createElement("div");

            item.className = "material-picker-option";
            item.setAttribute("role", "option");
            item.textContent = option.textContent.trim();
            item.addEventListener("mousedown", function (event) {
                event.preventDefault();
                chooseProject(option);
            });
            item.addEventListener("mousemove", function () {
                if (projectActive !== index) {
                    highlightProject(index);
                }
            });
            projectMenu.appendChild(item);
        });

        projectMenu.classList.remove("d-none");
        projectSearch.setAttribute("aria-expanded", "true");
        highlightProject(projectMatches.length ? 0 : -1);
    }

    projectSearch.addEventListener("input", renderProjectMenu);
    projectSearch.addEventListener("focus", renderProjectMenu);
    projectSearch.addEventListener("blur", closeProjectMenu);

    projectSearch.addEventListener("keydown", function (event) {
        const open = !projectMenu.classList.contains("d-none");
        const shown = Math.min(projectMatches.length, 50);

        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();

            if (!open) {
                renderProjectMenu();

                return;
            }

            if (shown) {
                highlightProject((projectActive + (event.key === "ArrowDown" ? 1 : shown - 1)) % shown);
            }
        } else if (event.key === "Enter") {
            // Never sends the form from the search.
            event.preventDefault();

            if (open && projectMatches[projectActive]) {
                chooseProject(projectMatches[projectActive]);
            }
        } else if (event.key === "Escape" && open) {
            // Closes the list, not the dialog.
            event.preventDefault();
            event.stopPropagation();
            closeProjectMenu();
        }
    });

    modalEl.querySelector("[data-stock-project-clear]").addEventListener("click", function () {
        field("project_id").value = "";
        showChosenProject();
        projectSearch.focus();
    });

    function listedIds() {
        return Array.from(linesEl.querySelectorAll("[data-stock-line]")).map(function (line) {
            return Number(line.dataset.stockLine);
        });
    }

    function refreshLines() {
        const count = linesEl.children.length;

        linesEmpty.classList.toggle("d-none", count > 0);
        linesEl.classList.toggle("d-none", count === 0);
        saveButton.disabled = count === 0;
    }

    /**
     * One material on the list, written with textContent so its name can
     * never become markup.
     */
    function addLine(material) {
        if (listedIds().indexOf(Number(material.material_id)) !== -1) {
            return null;
        }

        const line = document.createElement("div");
        const name = document.createElement("div");
        const label = document.createElement("div");
        const figures = document.createElement("div");
        const group = document.createElement("div");
        const quantity = document.createElement("input");
        const unit = document.createElement("span");
        const remove = document.createElement("button");

        line.className = "stock-line";
        line.dataset.stockLine = String(material.material_id);

        name.className = "stock-line-name";
        label.className = "fw-semibold";
        label.textContent = material.label;
        figures.className = "small text-secondary";
        figures.textContent =
            "Total " + formatQuantity((material.assigned || 0) + material.available) + " · Available " + formatQuantity(material.available) + " " + material.unit;
        name.append(label, figures);

        group.className = "input-group input-group-sm stock-line-qty";
        quantity.type = "number";
        quantity.className = "form-control";
        quantity.min = material.allows_fractions ? "0.01" : "1";
        quantity.step = material.allows_fractions ? "0.01" : "1";
        quantity.required = true;
        quantity.setAttribute("aria-label", "Quantity of " + material.label);
        quantity.dataset.stockLineQty = "";

        if (mode === "out") {
            quantity.max = String(material.on_hand);
        }

        unit.className = "input-group-text";
        unit.textContent = material.unit;
        group.append(quantity, unit);

        remove.type = "button";
        remove.className = "btn btn-sm btn-outline-danger py-1 px-2";
        remove.title = "Remove";
        remove.setAttribute("aria-label", "Remove " + material.label);
        remove.dataset.stockLineRemove = "";
        remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

        line.append(name, group, remove);
        linesEl.appendChild(line);
        refreshLines();

        return quantity;
    }

    linesEl.addEventListener("click", function (event) {
        const remove = event.target.closest("[data-stock-line-remove]");

        if (remove) {
            remove.closest("[data-stock-line]").remove();
            refreshLines();
            searchInput.focus();
        }
    });

    function openModal(action, material) {
        mode = action;

        modalForm.reset();
        modalError.classList.add("d-none");
        linesEl.replaceChildren();

        modalTitle.textContent = TITLES[mode];
        field("movement_date").value = options.today || "";

        applyMode();

        if (material) {
            addLine(material);
        }

        refreshLines();

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    window.materialPicker.attach(searchInput, {
        url: routes.stockOptions,
        exclude: listedIds,
        onPick: function (material) {
            addLine(material)?.focus();
        },
    });

    // The picker takes Enter while its list is open. Closed, Enter would
    // send the form from an empty search.
    searchInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
        }
    });

    field("movement_type").addEventListener("change", applyMode);

    modalEl.addEventListener("shown.bs.modal", function () {
        const firstQuantity = linesEl.querySelector("[data-stock-line-qty]");

        (firstQuantity || searchInput).focus();
    });

    document.querySelectorAll("[data-stock-bulk]").forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button.dataset.stockBulk, null);
        });
    });

    stockTableEl.addEventListener("click", function (event) {
        const action = event.target.closest("[data-stock-action]");

        if (action && !action.disabled) {
            try {
                openModal(action.dataset.stockAction, JSON.parse(action.dataset.stockMaterial));
            } catch (error) {
                showError("Unable to open that material.");
            }
        }
    });

    function payload() {
        const base = {
            items: Array.from(linesEl.querySelectorAll("[data-stock-line]")).map(function (line) {
                return {
                    material_id: Number(line.dataset.stockLine),
                    quantity: line.querySelector("[data-stock-line-qty]").value,
                };
            }),
            movement_date: field("movement_date").value,
            notes: field("notes").value.trim(),
            project_id: field("project_id").value,
        };

        if (mode === "in") {
            base.movement_type = field("movement_type").value;
        }

        return base;
    }

    modalForm.addEventListener("submit", function (event) {
        event.preventDefault();

        if (!linesEl.children.length) {
            return;
        }

        const url = mode === "in" ? routes.receiveMany : routes.issueMany;

        saveButton.disabled = true;
        saveSpinner.classList.remove("d-none");

        request(url, "POST", payload()).then(function (result) {
            saveButton.disabled = false;
            saveSpinner.classList.add("d-none");

            if (!result.ok) {
                modalError.textContent = result.body.error || "Unable to save.";
                modalError.classList.remove("d-none");

                return;
            }

            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            showError("");
            showSuccess(result.body.message || "");
            updateSummary(result.body.summary);
            redrawStock(false);
        });
    });

    startStockTable();
});
