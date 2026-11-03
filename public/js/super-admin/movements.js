/**
 * The Movements page: every stock change, newest first.
 *
 * History on an Inventory row opens this page with ?material= narrowing the
 * list. A movement is voided from its row, and the list is redrawn from what
 * the server answers.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const routes = window.inventoryRoutes || {};
    const tableEl = document.getElementById("movementsTable");

    if (!tableEl || !routes.movements) {
        return;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const errorBox = document.querySelector("[data-movement-error]");
    const successBox = document.querySelector("[data-movement-success]");
    const typeFilter = document.querySelector("[data-movement-type-filter]");
    const materialChip = document.querySelector("[data-movement-material-chip]");

    let materialId = materialChip ? materialChip.dataset.movementMaterial : "";

    function showError(message) {
        errorBox.textContent = message || "";
        errorBox.classList.toggle("d-none", !message);
    }

    function showSuccess(message) {
        successBox.textContent = message || "";
        successBox.classList.toggle("d-none", !message);
    }

    function voidMovement(url) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-Requested-With": "XMLHttpRequest",
            },
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

    const table = window.serverTable("#movementsTable", {
        url: routes.movements,
        params: function () {
            return { type: typeFilter.value, material: materialId };
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

    typeFilter.addEventListener("change", function () {
        table.draw();
    });

    document.querySelector("[data-movement-material-clear]")?.addEventListener("click", function () {
        materialId = "";
        materialChip.classList.add("d-none");
        window.history.replaceState(null, "", window.location.pathname);
        table.draw();
    });

    tableEl.addEventListener("click", function (event) {
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
                    return voidMovement(url);
                },
            })
            .then(function (body) {
                if (!body) {
                    return;
                }

                showError("");
                showSuccess(body.message || "");
                table.draw(false);
            });
    });
});
