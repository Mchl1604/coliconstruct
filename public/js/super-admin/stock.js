/**
 * Inventory -> Stock and Movements.
 *
 * The Stock table lists every material with how many are on hand; its row
 * buttons open one dialog for Stock In, Stock Out and Adjust Count, and
 * History narrows the Movements table to that material. Every write goes to
 * the server, which answers with fresh figures for the cards above the tabs,
 * and both tables are redrawn from it - so what is on screen is always what
 * was saved.
 *
 * The Movements table is built the first time its tab is shown: a DataTable
 * built while hidden measures its columns as zero wide.
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
    const movementTypeFilter = document.querySelector("[data-movement-type-filter]");
    const materialChip = document.querySelector("[data-movement-material-chip]");
    const materialChipLabel = document.querySelector("[data-movement-material-label]");
    const movementsTabEl = document.getElementById("movementsTab");
    const stockTabEl = document.getElementById("stockTab");

    const modalEl = document.querySelector("[data-stock-modal]");
    const modalForm = modalEl.querySelector("[data-stock-form]");
    const modalTitle = modalEl.querySelector("[data-stock-modal-title]");
    const modalMaterial = modalEl.querySelector("[data-stock-modal-material]");
    const modalOnHand = modalEl.querySelector("[data-stock-modal-on-hand]");
    const modalUnit = modalEl.querySelector("[data-stock-modal-unit]");
    const modalError = modalEl.querySelector("[data-stock-modal-error]");
    const quantityLabel = modalEl.querySelector("[data-stock-quantity-label]");
    const notesLabel = modalEl.querySelector("[data-stock-notes-label]");
    const saveButton = modalEl.querySelector("[data-stock-save]");
    const saveSpinner = modalEl.querySelector("[data-stock-save-spinner]");

    const TITLES = { in: "Stock In", out: "Stock Out", adjust: "Adjust Count" };

    let stockTable = null;
    let movementsTable = null;
    let movementMaterialId = "";
    let mode = "in";
    let current = null;
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
            window.bootstrap?.Tab.getOrCreateInstance(stockTabEl).show();
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

    function redrawMovements(fromStart) {
        if (movementsTable) {
            movementsTable.draw(fromStart ? undefined : false);
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
            columnDefs: [{ targets: [3, 4, 6, 7], orderable: false }],
            language: {
                search: "",
                searchPlaceholder: "Search stock...",
                emptyTable: "No materials yet.",
                zeroRecords: "Nothing matches.",
            },
        });
    }

    function startMovementsTable() {
        if (movementsTable || !window.serverTable) {
            return;
        }

        movementsTable = window.serverTable("#movementsTable", {
            url: routes.movements,
            params: function () {
                return { type: movementTypeFilter.value, material: movementMaterialId };
            },
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            order: [[0, "desc"]],
            columnDefs: [{ targets: [4, 5, 6], orderable: false }],
            language: {
                search: "",
                searchPlaceholder: "Search movements...",
                emptyTable: "No movements yet.",
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

    movementTypeFilter.addEventListener("change", function () {
        redrawMovements(true);
    });

    movementsTabEl.addEventListener("shown.bs.tab", function () {
        if (movementsTable) {
            movementsTable.columns.adjust();

            return;
        }

        startMovementsTable();
    });

    stockTabEl.addEventListener("shown.bs.tab", function () {
        stockTable?.columns.adjust();
    });

    function filterMovementsTo(materialId, label) {
        movementMaterialId = materialId ? String(materialId) : "";
        materialChipLabel.textContent = label || "";
        materialChip.classList.toggle("d-none", !movementMaterialId);

        if (movementsTabEl.classList.contains("active")) {
            redrawMovements(true);
        } else {
            // Built, or redrawn, by the shown handler above.
            window.bootstrap?.Tab.getOrCreateInstance(movementsTabEl).show();
            redrawMovements(true);
        }
    }

    document.querySelector("[data-movement-material-clear]").addEventListener("click", function () {
        filterMovementsTo("", "");
    });

    // A material added, edited or removed on the Materials tab - a new row,
    // a new reorder level - changes the Stock table too.
    document.addEventListener("materials:changed", function () {
        redrawStock(false);
    });

    // ------------------------------------------------------------------
    // The dialog
    // ------------------------------------------------------------------

    /**
     * Show the fields this mode uses. "in" splits again on Received As: a
     * delivery has a supplier, a return names the project it came from.
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

        quantityLabel.textContent = mode === "adjust" ? "Counted" : "Quantity";
        notesLabel.textContent = mode === "adjust" ? "Reason" : "Notes";
        field("notes").placeholder = mode === "adjust" ? "Why it changed" : "Optional";
        field("notes").required = mode === "adjust";
    }

    function openModal(action, material) {
        mode = action;
        current = material;

        modalForm.reset();
        modalError.classList.add("d-none");

        modalTitle.textContent = TITLES[mode];
        modalMaterial.textContent = material.label;
        modalOnHand.textContent = formatQuantity(material.on_hand) + " " + material.unit;
        modalUnit.textContent = material.unit;

        const quantity = field("quantity");

        quantity.step = material.allows_fractions ? "0.01" : "1";
        quantity.value = mode === "adjust" ? String(material.on_hand) : "";
        field("movement_date").value = options.today || "";

        applyMode();

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    field("movement_type").addEventListener("change", applyMode);

    modalEl.addEventListener("shown.bs.modal", function () {
        field("quantity").focus();
        field("quantity").select();
    });

    stockTableEl.addEventListener("click", function (event) {
        const action = event.target.closest("[data-stock-action]");

        if (action && !action.disabled) {
            try {
                openModal(action.dataset.stockAction, JSON.parse(action.dataset.stockMaterial));
            } catch (error) {
                showError("Unable to open that material.");
            }

            return;
        }

        const history = event.target.closest("[data-stock-history]");

        if (history) {
            filterMovementsTo(history.dataset.stockHistory, history.dataset.stockLabel);
        }
    });

    function payload() {
        const base = {
            material_id: current.material_id,
            movement_date: field("movement_date").value,
            notes: field("notes").value.trim(),
        };

        if (mode === "adjust") {
            return Object.assign(base, { counted: field("quantity").value });
        }

        Object.assign(base, {
            quantity: field("quantity").value,
            reference_no: field("reference_no").value.trim(),
            project_id: field("project_id").value,
        });

        if (mode === "in") {
            base.movement_type = field("movement_type").value;
            base.supplier = field("supplier").value.trim();
        }

        return base;
    }

    modalForm.addEventListener("submit", function (event) {
        event.preventDefault();

        if (!current) {
            return;
        }

        const url = { in: routes.receive, out: routes.issue, adjust: routes.adjust }[mode];

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
            redrawMovements(false);
        });
    });

    // ------------------------------------------------------------------
    // Voiding
    // ------------------------------------------------------------------

    document.getElementById("movementsTable").addEventListener("click", function (event) {
        const button = event.target.closest("[data-stock-void]");

        if (!button || !window.confirmDialog) {
            return;
        }

        const url = routes.movementBase + "/" + button.dataset.stockVoid + "/void";

        window
            .confirmDialog({
                title: "Void this entry?",
                body: button.dataset.stockVoidLabel,
                detail: "It stays on the list, struck through, and stops counting.",
                label: "Void Entry",
                onConfirm: function () {
                    return request(url, "POST");
                },
            })
            .then(function (body) {
                if (!body) {
                    return;
                }

                showError("");
                showSuccess(body.message || "");
                updateSummary(body.summary);
                redrawStock(false);
                redrawMovements(false);
            });
    });

    startStockTable();
});
