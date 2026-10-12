/**
 * Archived Reports: the table, and the viewer that reads one in full.
 *
 * The rows arrive a page at a time from ReportController::archivedTable(),
 * with each report on the page in full beside them. Restoring is an ordinary
 * form post inside a confirmation dialog, so the outcome arrives as the
 * flashed toast every other archive action in the portal uses - there is
 * nothing to do for it here.
 *
 * What is left is reading a report, and that is served from the page of
 * reports the server already sent, images included, rather than fetched
 * again.
 */
document.addEventListener("DOMContentLoaded", function () {
    // The reports on the page on screen, in full, keyed by id - replaced with
    // every page the table fetches.
    let reports = {};

    function escapeHtml(value) {
        return String(value == null ? "" : value).replace(
            /[&<>"']/g,
            function (character) {
                return {
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                }[character];
            },
        );
    }

    window.serverTable("#archivedReportsTable", {
        url: window.archivedReportsTable,
        extras: "[data-archived-report-dialogs]",
        onLoad: function (json) {
            reports = (json.meta && json.meta.payloads) || {};
        },
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        info: false,
        // Most recently archived first.
        order: [[6, "desc"]],
        columnDefs: [
            { targets: -1, orderable: false },
            // A report ID is a label, not a quantity.
            { targets: 0, className: "dt-left" },
            { targets: [5, 6], className: "text-start" },
        ],
        language: {
            search: "",
            searchPlaceholder: "Search archived reports...",
            emptyTable: "No archived reports.",
            zeroRecords: "No archived reports match your search.",
        },
    });

    // ------------------------------------------------------------------
    // Viewer
    // ------------------------------------------------------------------

    const modal = document.querySelector("[data-view-report-modal]");
    const imageModal = document.querySelector("[data-image-modal]");

    if (!modal) {
        return;
    }

    const galleryEl = modal.querySelector("[data-view-gallery]");

    galleryEl.addEventListener("click", function (event) {
        const button = event.target.closest("[data-gallery-image]");

        if (!button || !imageModal || !window.bootstrap) {
            return;
        }

        imageModal.querySelector("[data-image-target]").src =
            button.dataset.galleryImage;
        window.bootstrap.Modal.getOrCreateInstance(imageModal).show();
    });

    function render(report) {
        const content = modal.querySelector(".modal-content");

        // Tinted by report type, matching the Reports page viewer.
        content.classList.remove(
            "report-accent-progress",
            "report-accent-incident",
        );
        content.classList.add(
            report.type_accent_class || "report-accent-progress",
        );

        modal.querySelector("[data-view-type-eyebrow]").textContent =
            report.type_label;
        modal.querySelector("[data-view-title]").textContent =
            report.report_title;
        modal.querySelector("[data-view-client]").textContent =
            report.client || "—";
        modal.querySelector("[data-view-type]").innerHTML =
            '<span class="badge ' +
            report.type_badge_class +
            '">' +
            escapeHtml(report.type_label) +
            "</span>";
        modal.querySelector("[data-view-submitted-by]").textContent =
            report.submitted_by;
        modal.querySelector("[data-view-date]").textContent =
            report.report_date_label;
        modal.querySelector("[data-view-description]").textContent =
            report.description || "—";
        modal.querySelector("[data-view-archived-at]").textContent =
            report.archived_at_label;
        modal.querySelector("[data-view-archived-by]").textContent =
            report.archived_by;

        const link = modal.querySelector("[data-view-project-link]");

        if (report.project_url) {
            link.href = report.project_url;
            modal.querySelector("[data-view-project-ref]").textContent =
                report.reference_no;
            link.classList.remove("d-none");
        } else {
            link.classList.add("d-none");
        }

        const images = report.images || [];

        galleryEl.innerHTML = images
            .map(function (image) {
                return (
                    '<button type="button" class="report-gallery-item" data-gallery-image="' +
                    escapeHtml(image.url) +
                    '">' +
                    '<img src="' +
                    escapeHtml(image.url) +
                    '" alt="Report image" loading="lazy">' +
                    "</button>"
                );
            })
            .join("");

        modal
            .querySelector("[data-view-no-images]")
            .classList.toggle("d-none", images.length > 0);

        const countEl = modal.querySelector("[data-view-image-count]");

        countEl.textContent =
            images.length + (images.length === 1 ? " image" : " images");
        countEl.classList.toggle("d-none", images.length === 0);
    }

    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-view-report]");

        if (!button) {
            return;
        }

        const report = reports[button.dataset.viewReport];

        if (!report) {
            return;
        }

        render(report);

        if (window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    });
});
