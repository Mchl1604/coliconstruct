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
        columnDefs: [
            // Date Submitted carries a `data-order` timestamp, which
            // DataTables reads as numeric and then right-aligns on its own.
            { targets: 5, className: "text-start" },
            { targets: -1, orderable: false },
        ],
    });

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

        row.innerHTML =
            "<td>#" +
            portal.escapeHtml(report.id) +
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
            "<td>" +
            portal.escapeHtml(report.submitted_by) +
            "</td>" +
            '<td data-order="' +
            portal.escapeHtml(report.date_order) +
            '">' +
            portal.escapeHtml(report.date_label) +
            "</td>" +
            '<td class="text-center">' +
            '<button type="button" class="btn btn-sm btn-primary py-1 px-2" data-view-report="' +
            portal.escapeHtml(report.id) +
            '" title="View report"><i class="bi bi-eye"></i></button>' +
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
