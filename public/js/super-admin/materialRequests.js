/**
 * The Material Requests page: what leads have asked for, approved or rejected
 * from its rows. The list is redrawn from what the server answers.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const routes = window.materialRequestRoutes || {};
    const tableEl = document.getElementById("materialRequestsTable");

    if (!tableEl || !routes.table) {
        return;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const errorBox = document.querySelector("[data-request-error]");
    const successBox = document.querySelector("[data-request-success]");
    const statusFilter = document.querySelector("[data-request-status-filter]");
    const rejectModal = document.querySelector("[data-reject-request-modal]");
    const rejectForm = rejectModal.querySelector("[data-reject-request-form]");
    const rejectError = rejectModal.querySelector("[data-reject-request-error]");
    const rejectLabel = rejectModal.querySelector("[data-reject-request-label]");
    const rejectReason = rejectModal.querySelector("[data-reject-request-reason]");

    let rejecting = null;

    function show(box, message) {
        box.textContent = message || "";
        box.classList.toggle("d-none", !message);
    }

    function send(url, payload) {
        return fetch(url, {
            method: "POST",
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

    const table = window.serverTable("#materialRequestsTable", {
        url: routes.table,
        params: function () {
            return { status: statusFilter.value };
        },
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, "desc"]],
        columnDefs: [{ targets: [1, 2, 4, 6], orderable: false }],
        language: {
            search: "",
            searchPlaceholder: "Search requests...",
            emptyTable: "No requests.",
            zeroRecords: "Nothing matches.",
        },
    });

    function done(message) {
        show(errorBox, "");
        show(successBox, message);
        table.draw(false);
    }

    statusFilter.addEventListener("change", function () {
        table.draw();
    });

    tableEl.addEventListener("click", function (event) {
        const approve = event.target.closest("[data-request-approve]");

        if (approve && window.confirmDialog) {
            const url = routes.base + "/" + approve.dataset.requestApprove + "/approve";

            window
                .confirmDialog({
                    title: "Approve this request?",
                    body: approve.dataset.requestLabel,
                    detail: "It comes off the shelf today.",
                    label: "Approve",
                    onConfirm: function () {
                        return send(url);
                    },
                })
                .then(function (body) {
                    if (body) {
                        done(body.message);
                    }
                });

            return;
        }

        const reject = event.target.closest("[data-request-reject]");

        if (reject) {
            rejecting = reject.dataset.requestReject;
            rejectForm.reset();
            show(rejectError, "");
            rejectLabel.textContent = reject.dataset.requestLabel;
            window.bootstrap.Modal.getOrCreateInstance(rejectModal).show();
        }
    });

    rejectModal.addEventListener("shown.bs.modal", function () {
        rejectReason.focus();
    });

    rejectForm.addEventListener("submit", function (event) {
        event.preventDefault();

        send(routes.base + "/" + rejecting + "/reject", { reason: rejectReason.value.trim() }).then(function (result) {
            if (!result.ok) {
                show(rejectError, result.body.error || "Unable to save.");

                return;
            }

            window.bootstrap.Modal.getOrCreateInstance(rejectModal).hide();
            done(result.body.message);
        });
    });
});
