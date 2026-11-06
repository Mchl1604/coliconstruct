/**
 * My Projects: the assignments table. Its buttons are plain links to the
 * project's page.
 */
document.addEventListener("DOMContentLoaded", function () {
    const portal = window.portal;

    // One page of rows per request, filtered by the open tab on the server -
    // see TechnicianPortalController::projectsTable().
    portal.serverTable("#portalProjectsTable", "projects", {
        url: window.portalRoutes.projectsTable,
        params: function () {
            return {
                tab: window.projectStatusTabs.current("portalProjectsTable"),
            };
        },
        // In the order the tabs are read - see Project::tabRank().
        order: [
            [4, "asc"],
            [0, "desc"],
        ],
        // Phases and the button have no order of their own.
        columnDefs: [{ targets: [5, -1], orderable: false }],
    });
});
