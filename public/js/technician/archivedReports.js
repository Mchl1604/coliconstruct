/**
 * Archived Reports: the table, and the viewer that reads one in full.
 *
 * Restoring is an ordinary form post inside a confirmation dialog, so its
 * outcome arrives as the flashed toast the layout already raises - there is
 * nothing for this file to do about it. What is left is reading a report, and
 * that comes from the payload the page was handed rather than from a request:
 * the server already sent every archived report in full, images included.
 */
document.addEventListener("DOMContentLoaded", function () {
    const portal = window.portal;
    const reports = window.portalReports || {};

    portal.dataTable("#portalArchivedReportsTable", "archived reports", {
        // Archived Date, most recent first.
        order: [[5, "desc"]],
        info: true,
        columnDefs: [
            // Both date columns carry a `data-order` timestamp, which
            // DataTables reads as numeric and then right-aligns on its own.
            { targets: [4, 5], className: "text-start" },
            { targets: -1, orderable: false },
        ],
    });

    const viewEl = document.querySelector("[data-view-report-modal]");

    if (!viewEl) {
        return;
    }

    const imagesEl = viewEl.querySelector("[data-report-view-images]");
    const imagesHeadingEl = viewEl.querySelector(
        "[data-report-view-images-heading]",
    );

    function show(report) {
        const content = viewEl.querySelector(".modal-content");

        // Tinted by report type, matching the active Reports viewer.
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
        viewEl.querySelector("[data-report-view-archived-at]").textContent =
            report.archived_at_label;

        const images = report.images || [];

        imagesHeadingEl.classList.toggle("d-none", images.length === 0);
        imagesEl.innerHTML = images
            .map(function (image) {
                return (
                    '<div class="col-6 col-md-4">' +
                    '<a href="' +
                    portal.escapeHtml(image.url) +
                    '" target="_blank" rel="noopener noreferrer">' +
                    '<img src="' +
                    portal.escapeHtml(image.url) +
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
});
