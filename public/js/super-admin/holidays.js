/**
 * Configuration -> Project Settings -> Holidays: the days bookings skip.
 *
 * The table is built the first time its panel is shown: a DataTable built while
 * hidden measures its columns as zero wide.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const panel = document.querySelector("[data-holidays-panel]");
    const routes = window.holidayRoutes || {};

    if (!panel || !routes.base) {
        return;
    }

    const form = panel.querySelector("[data-holiday-form]");
    const errorBox = panel.querySelector("[data-holiday-error]");
    const successBox = panel.querySelector("[data-holiday-success]");
    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";

    let table = null;

    function show(box, message) {
        box.textContent = message || "";
        box.classList.toggle("d-none", !message);
    }

    function send(url, method, payload) {
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

    function startTable() {
        if (table || panel.hidden) {
            return;
        }

        table = window.serverTable("#holidaysTable", {
            url: routes.base,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50],
            order: [[0, "asc"]],
            columnDefs: [{ targets: [2], orderable: false }],
            language: {
                search: "",
                searchPlaceholder: "Search non-working days...",
                emptyTable: "No non-working days yet.",
                zeroRecords: "Nothing matches.",
            },
        });
    }

    new MutationObserver(startTable).observe(panel, { attributes: true, attributeFilter: ["hidden"] });
    startTable();

    form.addEventListener("submit", function (event) {
        event.preventDefault();

        send(routes.base, "POST", {
            holiday_date: form.querySelector('[name="holiday_date"]').value,
            name: form.querySelector('[name="name"]').value.trim(),
        }).then(function (result) {
            if (!result.ok) {
                show(successBox, "");
                show(errorBox, result.body.error || "Unable to save.");

                return;
            }

            const dateInput = form.querySelector('[name="holiday_date"]');

            if (dateInput._flatpickr) {
                dateInput._flatpickr.clear();
            }

            form.reset();
            show(errorBox, "");
            show(successBox, result.body.message);
            table?.draw(false);
        });
    });

    panel.addEventListener("click", function (event) {
        const remove = event.target.closest("[data-holiday-remove]");

        if (!remove || !window.confirmDialog) {
            return;
        }

        const url = routes.base + "/" + remove.dataset.holidayRemove;

        window
            .confirmDialog({
                title: 'Remove "' + remove.dataset.holidayName + '"?',
                body: "Bookings already made stay as they are.",
                label: "Remove",
                onConfirm: function () {
                    return send(url, "DELETE");
                },
            })
            .then(function (body) {
                if (body) {
                    show(errorBox, "");
                    show(successBox, body.message);
                    table?.draw(false);
                }
            });
    });
});
