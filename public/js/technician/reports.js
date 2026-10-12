/**
 * Reports: the lead's own report log, its viewer, and the Submit Report
 * dialog.
 *
 * The log pages on the server - see TechnicianPortalController::reportsTable()
 * - and the filter bar's four narrowings travel with every request, so a long
 * log costs the same to open as a short one. A report submitted or archived
 * here simply refetches the page somebody is on.
 */
document.addEventListener("DOMContentLoaded", function () {
    const portal = window.portal;

    // The reports on the page on screen, keyed by id - replaced with every
    // page the table fetches.
    let reports = {};

    // ------------------------------------------------------------------
    // Filters
    //
    // The same four narrowings the Super Admin Reports page offers - project,
    // report type, date and a search box - sent with every request and
    // applied in SQL. All four combine, and all four survive paging.
    // ------------------------------------------------------------------

    const filters = {
        project: "all",
        type: "all",
        date: "all",
        from: "",
        to: "",
    };

    const table = portal.serverTable("#portalReportsTable", "reports", {
        url: window.portalRoutes.reportsTable,
        params: function () {
            return {
                project_id: filters.project === "all" ? "" : filters.project,
                report_type: filters.type,
                date_filter: filters.date,
                start_date: filters.date === "custom" ? filters.from : "",
                end_date: filters.date === "custom" ? filters.to : "",
            };
        },
        onLoad: function (json) {
            reports = (json.meta && json.meta.payloads) || {};
        },
        // Date Submitted, newest first.
        order: [[5, "desc"]],
        // "Showing 1 to 10 of 25" under the pages, which the portal's tables
        // otherwise leave off. A report log is the one place the count is the
        // point, and it is what the Super Admin Reports page says above its
        // own pages - so both read the same.
        info: true,
        columnDefs: [
            { targets: 5, className: "text-start" },
            { targets: -1, orderable: false },
        ],
    });

    const customRange = document.querySelectorAll("[data-custom-range]");
    const startInput = document.querySelector("[data-filter-start]");
    const endInput = document.querySelector("[data-filter-end]");
    const searchInput = document.querySelector("[data-filter-search]");
    const resetButton = document.querySelector("[data-filter-reset]");

    /** A new filter makes the page somebody was on meaningless: page one. */
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

    // The search box drives DataTables' own search, which the server matches
    // against every column the table prints - project, title, technician.
    if (searchInput && table) {
        let searchTimer = null;

        searchInput.addEventListener("input", function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(searchInput.value).draw();
            }, 300);
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
    // The confirmation dialog first, then one request. The table refetches the
    // page on success rather than the page being reloaded - the report is off
    // the active list now, here and on the project it belongs to.
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

                    // Off the active list now: the page somebody is on is
                    // fetched again without it.
                    if (table) {
                        table.draw(false);
                    }

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

    const submitButton = document.querySelector("[data-submit-report]");

    if (!submitButton) {
        return;
    }

    submitButton.addEventListener("click", function () {
        window.portalModals.reportForm.open({
            onSuccess: function () {
                // The server draws the new row where it belongs - the page
                // somebody is on is simply fetched again.
                if (table) {
                    table.draw(false);
                }
            },
        });
    });
});
