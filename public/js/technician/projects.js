/**
 * My Projects: the assignments table and the completion check beside each row.
 *
 * The eye icon is a plain link to the project details page, so the only thing
 * this file drives is the completion dialog - which asks the server what is
 * still outstanding rather than trusting a value rendered at page load.
 */
document.addEventListener("DOMContentLoaded", function () {
    const portal = window.portal;

    portal.dataTable("#portalProjectsTable", "projects", {
        order: [[0, "desc"]],
        columnDefs: [{ targets: -1, orderable: false }],
    });

    const completeEl = document.querySelector("[data-complete-project-modal]");

    if (!completeEl) {
        return;
    }

    const completeForm = completeEl.querySelector("[data-complete-project-form]");
    const referenceEl = completeEl.querySelector(
        "[data-complete-project-reference]",
    );
    const loadingEl = completeEl.querySelector("[data-complete-project-loading]");
    const blockedEl = completeEl.querySelector("[data-complete-project-blocked]");
    const blockersEl = completeEl.querySelector("[data-complete-project-blockers]");
    const readyEl = completeEl.querySelector("[data-complete-project-ready]");
    const errorEl = completeEl.querySelector("[data-complete-project-error]");
    const submitEl = completeEl.querySelector("[data-complete-project-submit]");

    let projectId = null;

    function showBlockers(blockers) {
        loadingEl.classList.add("d-none");
        blockedEl.classList.toggle("d-none", blockers.length === 0);
        readyEl.classList.toggle("d-none", blockers.length > 0);
        submitEl.disabled = blockers.length > 0;

        blockersEl.innerHTML = blockers
            .map(function (blocker) {
                return "<li>" + portal.escapeHtml(blocker) + "</li>";
            })
            .join("");
    }

    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-complete-project]");

        if (!button) {
            return;
        }

        projectId = button.getAttribute("data-complete-project");
        referenceEl.textContent =
            button.getAttribute("data-project-reference") || "";

        portal.setAlert(errorEl, "");
        completeForm.reset();

        loadingEl.classList.remove("d-none");
        blockedEl.classList.add("d-none");
        readyEl.classList.add("d-none");
        submitEl.disabled = true;

        window.bootstrap.Modal.getOrCreateInstance(completeEl).show();

        portal
            .request(window.portalRoutes.projectDetails.replace("__ID__", projectId))
            .then(function (body) {
                showBlockers(body.completion_blockers || []);
            })
            .catch(function (error) {
                loadingEl.classList.add("d-none");
                portal.setAlert(errorEl, error.message);
            });
    });

    completeForm.addEventListener("submit", function (event) {
        event.preventDefault();
        portal.setAlert(errorEl, "");
        portal.setBusy(submitEl, true);

        portal
            .request(
                window.portalRoutes.completeProject.replace("__ID__", projectId),
                { method: "POST", body: new FormData(completeForm) },
            )
            .then(function (body) {
                portal.toast(body.message || "Project completed.");
                // The row's status and available actions both move at once, so
                // the table is re-rendered from the server.
                window.location.reload();
            })
            .catch(function (error) {
                const blockers = (error.body && error.body.blockers) || [];

                portal.setBusy(submitEl, false);

                // Something changed between opening the dialog and submitting
                // it, so show what it was rather than a bare refusal.
                if (blockers.length) {
                    showBlockers(blockers);
                }

                portal.setAlert(errorEl, error.message);
            });
    });
});
