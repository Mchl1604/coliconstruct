/**
 * The All / Pending / Ongoing / Overdue / On Hold / Completed / Cancelled tabs
 * above a projects table.
 *
 * Opt in from the markup: put `data-project-status-tabs="<tableId>"` on the tab
 * list, `data-status-filter="<status>"` on each button, and `data-tab` on every
 * row - the value Project::tabKey() gives that project.
 *
 * A tab is not always one stored status, which is why the row carries the
 * answer rather than this file working it out. Paused work files under On Hold
 * and nowhere else, overdue work is taken out of Pending and Ongoing so a late
 * project is not counted twice, unscheduled work reads as Pending, and work
 * awaiting client confirmation reads as Completed. All of that is decided once,
 * on the model, so the counts beside the tabs and the rows the tabs reveal can
 * never disagree.
 *
 * `data-status` / `data-overdue` are still honoured for any table that has not
 * been given `data-tab`.
 *
 * The chosen tab is remembered per table for the rest of the browsing session,
 * so opening a project from Ongoing and coming back lands on Ongoing rather
 * than resetting to All.
 */
document.addEventListener("DOMContentLoaded", function () {
    if (!window.jQuery || !window.jQuery.fn.DataTable) {
        return;
    }

    const $ = window.jQuery;

    function storageKey(tableId) {
        return "projectStatusTab:" + tableId;
    }

    function remember(tableId, status) {
        try {
            window.sessionStorage.setItem(storageKey(tableId), status);
        } catch (error) {
            // Private browsing, or storage that is full. Losing the memory of
            // which tab was open is not worth breaking the page over.
        }
    }

    function recall(tableId) {
        try {
            return window.sessionStorage.getItem(storageKey(tableId));
        } catch (error) {
            return null;
        }
    }

    /**
     * The tab a row belongs under. The server's answer when the row carries
     * one; otherwise derived here, as it always was.
     */
    function tabOf(rowNode) {
        if (!rowNode) {
            return null;
        }

        const given = rowNode.getAttribute("data-tab");

        if (given) {
            return given;
        }

        const rowStatus = rowNode.getAttribute("data-status");

        if (rowNode.getAttribute("data-on-hold") === "1") {
            return "on_hold";
        }

        if (rowNode.getAttribute("data-overdue") === "1") {
            return "overdue";
        }

        if (rowStatus === "unscheduled" || rowStatus === "pending") {
            return "pending";
        }

        if (
            rowStatus === "completed" ||
            rowStatus === "awaiting_client_confirmation"
        ) {
            return "completed";
        }

        return rowStatus;
    }

    function matches(rowNode, status) {
        return status === "all" || tabOf(rowNode) === status;
    }

    function wire(tabList, tableId, table) {
        const buttons = tabList.querySelectorAll("[data-status-filter]");
        const flag = "_statusFilter_" + tableId;

        function apply(status, save) {
            buttons.forEach(function (item) {
                item.classList.toggle(
                    "active",
                    item.getAttribute("data-status-filter") === status,
                );
            });

            // Drop this table's previous filter, leaving any other
            // table's alone.
            $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(
                function (filterFn) {
                    return !filterFn[flag];
                },
            );

            const filterFn = function (settings, data, dataIndex) {
                if (settings.nTable.id !== tableId) {
                    return true;
                }

                return matches(table.row(dataIndex).node(), status);
            };

            filterFn[flag] = true;
            $.fn.dataTable.ext.search.push(filterFn);
            table.draw();

            if (save) {
                remember(tableId, status);
            }
        }

        buttons.forEach(function (button) {
            button.addEventListener("click", function () {
                apply(button.getAttribute("data-status-filter"), true);
            });
        });

        /**
         * Which tab to open on.
         *
         * A `?status=` in the address wins - the dashboard and the summary
         * cards link here asking for one particular tab, and that is a
         * deliberate request rather than a return to where somebody was. Next
         * comes the tab they last chose on this table, which is what makes
         * coming back from a project land where they left. Failing both, All.
         */
        function initialStatus() {
            const requested = new URLSearchParams(window.location.search).get(
                "status",
            );
            const remembered = recall(tableId);

            return [requested, remembered].find(function (candidate) {
                return (
                    candidate &&
                    tabList.querySelector(
                        '[data-status-filter="' + candidate + '"]',
                    )
                );
            });
        }

        const opening = initialStatus();

        if (opening) {
            // Saved as well as applied: a tab arrived at by link becomes the
            // one this table returns to, which is what somebody following a
            // dashboard figure into a project expects on the way back.
            apply(opening, true);
        }
    }

    /**
     * Hand back the table's DataTable once it exists.
     *
     * The page builds its own table with its own options, and whether that has
     * happened by the time this file runs depends on script order. Calling
     * `.DataTable()` too early would quietly build one with default options,
     * and the page's real init would then fail with "Cannot reinitialise
     * DataTable" - so wait for its init event rather than racing it.
     */
    function whenTableReady(tableEl, callback) {
        if ($.fn.dataTable.isDataTable(tableEl)) {
            callback($(tableEl).DataTable());

            return;
        }

        $(tableEl).one("init.dt", function () {
            callback($(tableEl).DataTable());
        });
    }

    document
        .querySelectorAll("[data-project-status-tabs]")
        .forEach(function (tabList) {
            const tableId = tabList.getAttribute("data-project-status-tabs");
            const tableEl = document.getElementById(tableId);

            if (!tableEl) {
                return;
            }

            whenTableReady(tableEl, function (table) {
                wire(tabList, tableId, table);
            });
        });
});
