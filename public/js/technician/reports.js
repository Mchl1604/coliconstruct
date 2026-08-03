/**
 * Reports: the lead's own report log, its viewer, and the Submit Report
 * dialog.
 *
 * A newly submitted report is added straight into the DataTable rather than
 * costing a page load - the server hands back the finished row.
 */
document.addEventListener("DOMContentLoaded", function () {
    const portal = window.portal;
    const reports = window.leadReports || {};

    const table = portal.dataTable("#leadReportsTable", "reports", {
        order: [[3, "desc"]],
        columnDefs: [
            // Date Submitted carries a `data-order` timestamp, which
            // DataTables reads as numeric and then right-aligns on its own.
            { targets: 3, className: "text-start" },
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
        viewEl.querySelector("[data-report-view-project]").textContent =
            report.reference_no + " — " + report.project_name;
        viewEl.querySelector("[data-report-view-title]").textContent =
            report.title;
        viewEl.querySelector("[data-report-view-meta]").textContent =
            report.type_label + " · " + report.date_label;
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
     * Same cells, in the same order, as the Blade-rendered rows above.
     */
    function rowFor(report) {
        const download = report.images.length
            ? '<a class="btn btn-sm btn-outline-secondary py-1 px-2" href="' +
              report.images[0].url +
              '" download title="Download attachment"><i class="bi bi-download"></i></a>'
            : "";

        return [
            "#" + report.id,
            '<div class="fw-semibold">' +
                portal.escapeHtml(report.project_name) +
                '</div><small class="text-muted">' +
                portal.escapeHtml(report.reference_no) +
                "</small>",
            portal.escapeHtml(report.title),
            portal.escapeHtml(report.date_label),
            '<span class="badge ' +
                report.type_badge_class +
                '">' +
                portal.escapeHtml(report.type_label) +
                "</span>",
            '<div class="d-flex justify-content-center gap-2">' +
                '<button type="button" class="btn btn-sm btn-primary py-1 px-2" data-view-report="' +
                report.id +
                '" title="View report"><i class="bi bi-eye"></i></button>' +
                download +
                "</div>",
        ];
    }

    const submitButton = document.querySelector("[data-submit-report]");

    if (!submitButton) {
        return;
    }

    submitButton.addEventListener("click", function () {
        window.leadModals.reportForm.open({
            onSuccess: function (report) {
                reports[report.id] = report;

                if (table) {
                    table.row.add(rowFor(report)).draw(false);
                }
            },
        });
    });
});
