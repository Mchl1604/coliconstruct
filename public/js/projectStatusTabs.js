/**
 * The All / Pending / Ongoing / Overdue / Completed / Cancelled tabs above a
 * projects table.
 *
 * Opt in from the markup: put `data-project-status-tabs="<tableId>"` on the
 * tab list, `data-status-filter="<status>"` on each button, and
 * `data-status` / `data-overdue` on every row.
 *
 * Overdue is derived, not a stored status, so it gets its own tab and is taken
 * out of Pending and Ongoing - otherwise a late project would be counted
 * twice.
 */
document.addEventListener("DOMContentLoaded", function () {
    if (!window.jQuery || !window.jQuery.fn.DataTable) {
        return;
    }

    const $ = window.jQuery;

    function matches(rowNode, status) {
        if (status === "all") {
            return true;
        }

        const rowStatus = rowNode && rowNode.getAttribute("data-status");
        const isOverdue = rowNode && rowNode.getAttribute("data-overdue") === "1";

        if (status === "overdue") {
            return isOverdue;
        }

        // A project with no schedule yet is still waiting to start, so it
        // belongs with the pending work rather than nowhere.
        if (status === "pending") {
            return (
                !isOverdue &&
                (rowStatus === "pending" || rowStatus === "unscheduled")
            );
        }

        if (status === "ongoing") {
            return rowStatus === "ongoing" && !isOverdue;
        }

        return rowStatus === status;
    }

    function wire(tabList, tableId, table) {
        const buttons = tabList.querySelectorAll("[data-status-filter]");
        const flag = "_statusFilter_" + tableId;

        buttons.forEach(function (button) {
            button.addEventListener("click", function () {
                buttons.forEach(function (item) {
                    item.classList.remove("active");
                });

                button.classList.add("active");

                const status = button.getAttribute("data-status-filter");

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
            });
        });
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
