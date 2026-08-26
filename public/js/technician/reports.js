/**
 * Reports: the lead's own report log, its viewer, and the Submit Report
 * dialog.
 *
 * A newly submitted report is added straight into the DataTable rather than
 * costing a page load - the server hands back the finished row.
 */
document.addEventListener("DOMContentLoaded", function () {
    const portal = window.portal;
    const reports = window.portalReports || {};

    const table = portal.dataTable("#portalReportsTable", "reports", {
        // Date Submitted, now the sixth column.
        order: [[5, "desc"]],
        // "Showing 1 to 10 of 25" under the pages, which the portal's tables
        // otherwise leave off. A report log is the one place the count is the
        // point, and it is what the Super Admin Reports page says above its
        // own pages - so both read the same.
        info: true,
        columnDefs: [
            // Date Submitted carries a `data-order` timestamp, which
            // DataTables reads as numeric and then right-aligns on its own.
            { targets: 5, className: "text-start" },
            { targets: -1, orderable: false },
        ],
    });

    // ------------------------------------------------------------------
    // Filters
    //
    // The same four narrowings the Super Admin Reports page offers - project,
    // report type, date and a search box - applied to the rows already on the
    // page: a lead's own log is short, so a round trip per keystroke would buy
    // nothing. All four combine, and all four survive paging.
    // ------------------------------------------------------------------

    const filters = {
        project: "all",
        type: "all",
        date: "all",
        from: "",
        to: "",
    };

    function isoDate(date) {
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");

        return date.getFullYear() + "-" + month + "-" + day;
    }

    /**
     * The [from, to] the Date select stands for, as ISO strings, or null when
     * every date qualifies. Weeks run Monday to Sunday and months are calendar
     * months, matching how the server reads the same options.
     */
    function dateBounds() {
        const today = new Date();

        if (filters.date === "today") {
            return [isoDate(today), isoDate(today)];
        }

        if (filters.date === "week") {
            const start = new Date(today);
            // getDay() is 0 on Sunday, which belongs to the week that started
            // six days earlier rather than to the one starting tomorrow.
            start.setDate(today.getDate() - ((today.getDay() + 6) % 7));

            const end = new Date(start);
            end.setDate(start.getDate() + 6);

            return [isoDate(start), isoDate(end)];
        }

        if (filters.date === "month") {
            const start = new Date(today.getFullYear(), today.getMonth(), 1);
            const end = new Date(today.getFullYear(), today.getMonth() + 1, 0);

            return [isoDate(start), isoDate(end)];
        }

        if (filters.date === "custom" && (filters.from || filters.to)) {
            return [filters.from || "0000-01-01", filters.to || "9999-12-31"];
        }

        return null;
    }

    function matchesRow(row) {
        if (!row) {
            return true;
        }

        const bounds = dateBounds();

        if (bounds) {
            const reportDate = row.getAttribute("data-report-date") || "";

            if (!reportDate || reportDate < bounds[0] || reportDate > bounds[1]) {
                return false;
            }
        }

        if (
            filters.project !== "all" &&
            row.getAttribute("data-project-id") !== filters.project
        ) {
            return false;
        }

        if (
            filters.type !== "all" &&
            row.getAttribute("data-report-type") !== filters.type
        ) {
            return false;
        }

        return true;
    }

    if (table && window.jQuery) {
        const search = window.jQuery.fn.dataTable.ext.search;

        search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== "portalReportsTable") {
                return true;
            }

            return matchesRow(table.row(dataIndex).node());
        });
    }

    const customRange = document.querySelectorAll("[data-custom-range]");
    const startInput = document.querySelector("[data-filter-start]");
    const endInput = document.querySelector("[data-filter-end]");
    const searchInput = document.querySelector("[data-filter-search]");
    const resetButton = document.querySelector("[data-filter-reset]");

    function redraw() {
        if (table) {
            table.draw();
        }
    }

    [
        ["[data-filter-project]", "project"],
        ["[data-filter-type]", "type"],
        ["[data-filter-date]", "date"],
    ].forEach(function (pair) {
        const element = document.querySelector(pair[0]);

        if (!element) {
            return;
        }

        element.value = "all";

        element.addEventListener("change", function () {
            filters[pair[1]] = element.value;

            if (pair[1] === "date") {
                const isCustom = element.value === "custom";

                customRange.forEach(function (wrapper) {
                    wrapper.classList.toggle("d-none", !isCustom);
                });
            }

            redraw();
        });
    });

    [startInput, endInput].forEach(function (input) {
        if (!input) {
            return;
        }

        input.addEventListener("change", function () {
            filters.from = startInput ? startInput.value : "";
            filters.to = endInput ? endInput.value : "";
            redraw();
        });
    });

    // The search box drives DataTables' own search, so it matches on every
    // visible column - project, title, technician - rather than on a list of
    // fields this file would have to keep in step with the table.
    if (searchInput && table) {
        let searchTimer = null;

        searchInput.addEventListener("input", function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(searchInput.value).draw();
            }, 250);
        });
    }

    if (resetButton) {
        resetButton.addEventListener("click", function () {
            filters.project = "all";
            filters.type = "all";
            filters.date = "all";
            filters.from = "";
            filters.to = "";

            [
                "[data-filter-project]",
                "[data-filter-type]",
                "[data-filter-date]",
            ].forEach(function (selector) {
                const element = document.querySelector(selector);

                if (element) {
                    element.value = "all";
                }
            });

            [startInput, endInput, searchInput].forEach(function (input) {
                if (input) {
                    input.value = "";
                }
            });

            customRange.forEach(function (wrapper) {
                wrapper.classList.add("d-none");
            });

            if (table) {
                table.search("").draw();
            }
        });
    }

    // ------------------------------------------------------------------
    // Viewer
    // ------------------------------------------------------------------

    const viewEl = document.querySelector("[data-view-report-modal]");
    const imagesEl = viewEl.querySelector("[data-report-view-images]");
    const imagesHeadingEl = viewEl.querySelector(
        "[data-report-view-images-heading]",
    );

    function show(report) {
        const content = viewEl.querySelector(".modal-content");

        // Tinted by report type, matching the Super Admin viewer.
        content.classList.remove(
            "report-accent-progress",
            "report-accent-incident",
        );
        content.classList.add(
            report.type_accent_class || "report-accent-progress",
        );

        viewEl.querySelector("[data-report-view-type-eyebrow]").textContent =
            report.type_label;
        viewEl.querySelector("[data-report-view-title]").textContent =
            report.title;
        viewEl.querySelector("[data-report-view-project]").textContent =
            report.reference_no;
        viewEl.querySelector("[data-report-view-client]").textContent =
            report.client || "—";
        viewEl.querySelector("[data-report-view-type]").innerHTML =
            '<span class="badge ' +
            report.type_badge_class +
            '">' +
            portal.escapeHtml(report.type_label) +
            "</span>";
        viewEl.querySelector("[data-report-view-submitted-by]").textContent =
            report.submitted_by || "—";
        viewEl.querySelector("[data-report-view-date]").textContent =
            report.date_label;
        viewEl.querySelector("[data-report-view-description]").textContent =
            report.description;

        const images = report.images || [];

        imagesHeadingEl.classList.toggle("d-none", images.length === 0);
        imagesEl.innerHTML = images
            .map(function (image) {
                return (
                    '<div class="col-6 col-md-4">' +
                    '<a href="' +
                    image.url +
                    '" target="_blank" rel="noopener noreferrer">' +
                    '<img src="' +
                    image.url +
                    '" class="img-fluid rounded border" ' +
                    'style="height:150px;width:100%;object-fit:cover;" alt="Report attachment">' +
                    "</a></div>"
                );
            })
            .join("");

        window.bootstrap.Modal.getOrCreateInstance(viewEl).show();
    }

    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-view-report]");

        if (!button) {
            return;
        }

        const report = reports[button.getAttribute("data-view-report")];

        if (report) {
            show(report);
        }
    });

    // ------------------------------------------------------------------
    // Archiving
    //
    // The confirmation dialog first, then one request. The row is dropped from
    // the table on success rather than the page being reloaded - it is off the
    // active list now, here and on the project it belongs to.
    // ------------------------------------------------------------------

    const archiveEl = document.querySelector("[data-archive-report-modal]");

    if (archiveEl) {
        const labelEl = archiveEl.querySelector("[data-archive-report-label]");
        const errorEl = archiveEl.querySelector("[data-archive-report-error]");
        const confirmEl = archiveEl.querySelector(
            "[data-archive-report-confirm]",
        );

        let pending = null;

        function setArchiveError(message) {
            errorEl.textContent = message || "";
            errorEl.classList.toggle("d-none", !message);
        }

        document.addEventListener("click", function (event) {
            const button = event.target.closest("[data-archive-report]");

            if (!button) {
                return;
            }

            pending = {
                id: button.getAttribute("data-archive-report"),
                row: button.closest("tr"),
            };

            labelEl.textContent =
                button.getAttribute("data-report-label") || "this report";
            setArchiveError("");
            window.bootstrap.Modal.getOrCreateInstance(archiveEl).show();
        });

        confirmEl.addEventListener("click", function () {
            if (!pending) {
                return;
            }

            const target = pending;

            setArchiveError("");
            portal.setBusy(confirmEl, true);

            portal
                .request(
                    (window.portalRoutes.archiveReport || "").replace(
                        "__ID__",
                        target.id,
                    ),
                    { method: "POST" },
                )
                .then(function (body) {
                    window.bootstrap.Modal.getOrCreateInstance(archiveEl).hide();

                    if (table && target.row) {
                        table.row(target.row).remove().draw(false);
                    }

                    delete reports[target.id];
                    pending = null;

                    portal.toast(
                        body.message || "Report archived successfully.",
                        "success",
                    );
                })
                .catch(function (error) {
                    setArchiveError(error.message);
                })
                .finally(function () {
                    portal.setBusy(confirmEl, false);
                });
        });
    }

    // ------------------------------------------------------------------
    // Submitting
    // ------------------------------------------------------------------

    /**
     * A real <tr>, matching the Blade-rendered rows cell for cell - including
     * the data-order on the date.
     *
     * Deliberately not an array of cell strings. The Blade rows carry
     * data-order, which makes DataTables treat that column as an orthogonal
     * source ({_: "3.display", sort: "3.@data-order"}); a flat array has no
     * such shape, so adding one raises "Requested unknown parameter
     * '[object Object]'" and the new row sorts by nothing. Handing DataTables
     * a node lets it read the attribute exactly as it does for the rest.
     */
    function rowFor(report) {
        const row = document.createElement("tr");

        // The same attributes the Blade rows carry, so a report submitted
        // without a reload is filterable the moment it lands.
        row.setAttribute("data-report-date", report.date || "");
        row.setAttribute("data-project-id", report.project_id || "");
        row.setAttribute("data-report-type", report.type || "");

        row.innerHTML =
            "<td>" +
            portal.escapeHtml(report.display_code) +
            "</td>" +
            "<td>" +
            portal.escapeHtml(report.reference_no) +
            "</td>" +
            '<td class="fw-semibold">' +
            portal.escapeHtml(report.client) +
            '<div class="small text-muted fw-normal">' +
            portal.escapeHtml(report.title) +
            "</div></td>" +
            '<td><span class="badge ' +
            report.type_badge_class +
            '">' +
            portal.escapeHtml(report.type_label) +
            "</span></td>" +
            '<td><div class="d-flex align-items-center gap-2">' +
            (report.submitted_by_avatar
                ? '<img class="user-avatar user-avatar-xs" src="' +
                  portal.escapeHtml(report.submitted_by_avatar) +
                  '" alt="" loading="lazy">'
                : "") +
            "<span>" +
            portal.escapeHtml(report.submitted_by) +
            "</span></div></td>" +
            '<td data-order="' +
            portal.escapeHtml(report.date_order) +
            '">' +
            portal.escapeHtml(report.date_label) +
            "</td>" +
            '<td class="text-center">' +
            '<div class="d-inline-flex gap-1">' +
            '<button type="button" class="btn btn-sm btn-primary py-1 px-2" data-view-report="' +
            portal.escapeHtml(report.id) +
            '" title="View report"><i class="bi bi-eye"></i></button>' +
            // Drawn from the server's own answer, so a row added without a
            // reload offers exactly what a reloaded one would.
            (report.can_archive
                ? '<button type="button" class="btn btn-sm btn-dark py-1 px-2" data-archive-report="' +
                  portal.escapeHtml(report.id) +
                  '" data-report-label="' +
                  portal.escapeHtml(report.display_code + " - " + report.title) +
                  '" title="Archive report"><i class="bi bi-archive"></i></button>'
                : "") +
            "</div>" +
            "</td>";

        return row;
    }

    const submitButton = document.querySelector("[data-submit-report]");

    if (!submitButton) {
        return;
    }

    submitButton.addEventListener("click", function () {
        window.portalModals.reportForm.open({
            onSuccess: function (report) {
                reports[report.id] = report;

                if (table) {
                    table.row.add(rowFor(report)).draw(false);
                }
            },
        });
    });
});
