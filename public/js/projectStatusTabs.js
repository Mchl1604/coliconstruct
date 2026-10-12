/**
 * The All / Pending / Ongoing / Needs Rescheduling / On Hold / Completed /
 * Cancelled tabs above a projects table.
 *
 * Opt in from the markup: put `data-project-status-tabs="<tableId>"` on the tab
 * list and `data-status-filter="<status>"` on each button. The table pages on
 * the server (see serverTable.js), and the chosen tab is sent with every
 * request as `tab`:
 *
 *     window.serverTable("#projectsTable", {
 *         params: function () {
 *             return { tab: window.projectStatusTabs.current("projectsTable") };
 *         },
 *     });
 *
 * Which tab a project files under is decided on the server, by
 * Project::tabKey() and Project::attentionTabKeys() - the same methods the
 * counts beside the tabs are taken from - so a badge and the rows its tab
 * shows can never disagree. Nothing about it is worked out here.
 *
 * The attention tabs (No Technicians, Inactive Crew) overlap the status tabs:
 * one project can appear under both. The dashboard's Urgent Actions link
 * straight at them with `?status=`, which is what opens the list already
 * filtered.
 *
 * The chosen tab is remembered per table for the rest of the browsing session,
 * so opening a project from Ongoing and coming back lands on Ongoing rather
 * than resetting to All.
 */
(function (global) {
    "use strict";

    // tableId => the tab it is showing.
    const chosen = {};

    function storageKey(tableId) {
        return "projectStatusTab:" + tableId;
    }

    function remember(tableId, status) {
        try {
            global.sessionStorage.setItem(storageKey(tableId), status);
        } catch (error) {
            // Private browsing, or storage that is full. Losing the memory of
            // which tab was open is not worth breaking the page over.
        }
    }

    function recall(tableId) {
        try {
            return global.sessionStorage.getItem(storageKey(tableId));
        } catch (error) {
            return null;
        }
    }

    function tabListFor(tableId) {
        return document.querySelector(
            '[data-project-status-tabs="' + tableId + '"]',
        );
    }

    function hasTab(tabList, status) {
        return Boolean(
            status &&
                tabList &&
                Array.prototype.some.call(
                    tabList.querySelectorAll("[data-status-filter]"),
                    function (button) {
                        return button.getAttribute("data-status-filter") === status;
                    },
                ),
        );
    }

    /**
     * Which tab to open on.
     *
     * A `?status=` in the address wins - the dashboard and the summary cards
     * link here asking for one particular tab, and that is a deliberate
     * request rather than a return to where somebody was. Next comes the tab
     * they last chose on this table, which is what makes coming back from a
     * project land where they left. Failing both, All.
     */
    function initialStatus(tableId) {
        const tabList = tabListFor(tableId);
        const requested = new URLSearchParams(global.location.search).get("status");
        const remembered = recall(tableId);

        return (
            [requested, remembered].find(function (candidate) {
                return hasTab(tabList, candidate);
            }) || "all"
        );
    }

    function markActive(tableId, status) {
        const tabList = tabListFor(tableId);

        if (!tabList) {
            return;
        }

        tabList.querySelectorAll("[data-status-filter]").forEach(function (item) {
            item.classList.toggle(
                "active",
                item.getAttribute("data-status-filter") === status,
            );
        });
    }

    /**
     * The tab a table is showing - resolved the first time it is asked, which
     * is the table's own first request, so the very first page fetched is
     * already the right tab's.
     */
    function current(tableId) {
        if (!chosen[tableId]) {
            chosen[tableId] = initialStatus(tableId);

            // Saved as well as applied: a tab arrived at by link becomes the
            // one this table returns to, which is what somebody following a
            // dashboard figure into a project expects on the way back.
            remember(tableId, chosen[tableId]);
            markActive(tableId, chosen[tableId]);
        }

        return chosen[tableId];
    }

    global.projectStatusTabs = { current: current };

    document.addEventListener("DOMContentLoaded", function () {
        document
            .querySelectorAll("[data-project-status-tabs]")
            .forEach(function (tabList) {
                const tableId = tabList.getAttribute("data-project-status-tabs");
                const tableEl = document.getElementById(tableId);

                markActive(tableId, current(tableId));

                tabList
                    .querySelectorAll("[data-status-filter]")
                    .forEach(function (button) {
                        button.addEventListener("click", function () {
                            const status = button.getAttribute("data-status-filter");

                            chosen[tableId] = status;
                            remember(tableId, status);
                            markActive(tableId, status);

                            const $ = global.jQuery;

                            if (
                                tableEl &&
                                $ &&
                                $.fn.dataTable &&
                                $.fn.dataTable.isDataTable(tableEl)
                            ) {
                                // A full draw: back to page one, fetched again
                                // with the new tab.
                                $(tableEl).DataTable().draw();
                            }
                        });
                    });
            });
    });
})(window);
