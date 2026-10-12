/**
 * A DataTable that asks the server for one page at a time.
 *
 * Every list in both portals used to be rendered in full - every row, and
 * every dialog every row could open - and handed to DataTables to page through
 * in the browser. That is fine at forty rows and is what made the deployed
 * pages slow once the lists grew: the page cost grew with the whole table, not
 * with the ten rows anybody was looking at.
 *
 * This is DataTables' own server-side mode with two additions that keep the
 * markup in Blade, where it already was:
 *
 *   - The server answers with finished `<tr>` rows (see App\Support\ServerTable)
 *     rather than bare cell values. They are taken apart here into the cells
 *     DataTables wants, and every attribute the row and its cells carried -
 *     classes, data-* hooks, titles - is put back on the row DataTables draws.
 *     So a row reads exactly as it did when Blade wrote the whole table.
 *
 *   - Whatever else a page of rows needs - the dialogs its buttons open - comes
 *     back beside the rows as `extras` and replaces the previous page's in the
 *     container named by `extras`. Only the dialogs for the rows on screen are
 *     ever in the document.
 *
 * Usage:
 *
 *     window.serverTable("#projectsTable", {
 *         url: "/super-admin/projects/table",   // or data-server-table-url
 *         extras: "[data-projects-dialogs]",     // optional
 *         params: function () { return { tab: currentTab }; }, // optional
 *         onLoad: function (json) { ... },       // optional, after every page
 *         // ...any other DataTables option
 *     });
 *
 * `table.draw()` refetches from page one - after a filter changes, say - and
 * `table.draw(false)` refetches the page somebody is on.
 */
(function (global) {
    "use strict";

    /**
     * Copy every attribute of one element onto another. Classes are added to
     * rather than replaced, so the ones DataTables puts on a cell for its own
     * alignment survive.
     */
    function copyAttributes(source, target) {
        Array.prototype.forEach.call(source.attributes, function (attribute) {
            if (attribute.name === "class") {
                attribute.value
                    .split(/\s+/)
                    .filter(Boolean)
                    .forEach(function (name) {
                        target.classList.add(name);
                    });

                return;
            }

            target.setAttribute(attribute.name, attribute.value);
        });
    }

    /**
     * One server-rendered `<tr>` as the array of cell markup DataTables draws,
     * carrying the parsed row along for createdRow() to copy attributes from.
     */
    function rowData(html) {
        const template = document.createElement("template");

        template.innerHTML =
            "<table><tbody>" + String(html || "").trim() + "</tbody></table>";

        const row = template.content.querySelector("tr");

        if (!row) {
            return null;
        }

        const cells = Array.prototype.map.call(row.cells, function (cell) {
            return cell.innerHTML;
        });

        // An array with a property: DataTables keeps the very object it is
        // handed as the row's data, so createdRow() is given this back.
        cells.serverRow = row;

        return cells;
    }

    function container(target) {
        if (!target) {
            return null;
        }

        return typeof target === "string"
            ? document.querySelector(target)
            : target;
    }

    /**
     * The query string for one draw: DataTables' paging, search and order, in
     * the shape ServerTable reads, plus whatever filters the page adds.
     */
    function query(data, params) {
        const search = new URLSearchParams();

        search.set("draw", data.draw);
        search.set("start", data.start);
        search.set("length", data.length);
        search.set("search", (data.search && data.search.value) || "");

        (data.order || []).forEach(function (order, index) {
            search.set("order[" + index + "][column]", order.column);
            search.set("order[" + index + "][dir]", order.dir);
        });

        Object.keys(params || {}).forEach(function (key) {
            const value = params[key];

            if (value === null || value === undefined || value === "") {
                return;
            }

            search.set(key, value);
        });

        return search.toString();
    }

    function serverTable(selector, options) {
        const $ = global.jQuery;

        if (!$ || !$.fn || !$.fn.DataTable) {
            return null;
        }

        const tableEl =
            typeof selector === "string"
                ? document.querySelector(selector)
                : selector;

        if (!tableEl) {
            return null;
        }

        const settings = Object.assign({}, options || {});
        const url = settings.url || tableEl.getAttribute("data-server-table-url");
        const extrasEl = container(settings.extras);
        const paramsFn = settings.params;
        const onLoad = settings.onLoad;
        const userCreatedRow = settings.createdRow;

        delete settings.url;
        delete settings.extras;
        delete settings.params;
        delete settings.onLoad;
        delete settings.createdRow;

        const failed = "Couldn't load this list. Try again.";

        // The table's own empty messages, put back after a failed load
        // replaced them with the failure.
        let messages = null;

        // The request in flight, so a quicker second request (a fast typist,
        // a double click on Next) is not overwritten by a slower first.
        let inFlight = null;

        settings.serverSide = true;
        settings.processing = true;
        settings.searchDelay = settings.searchDelay || 400;

        settings.ajax = function (data, callback, dtSettings) {
            const language = dtSettings.oLanguage;

            if (!messages) {
                messages = {
                    empty: language.sEmptyTable,
                    zero: language.sZeroRecords,
                };
            }

            if (inFlight) {
                inFlight.abort();
            }

            const controller =
                typeof AbortController === "function"
                    ? new AbortController()
                    : null;

            inFlight = controller;

            const params = typeof paramsFn === "function" ? paramsFn() : {};

            fetch(url + (url.indexOf("?") === -1 ? "?" : "&") + query(data, params), {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                signal: controller ? controller.signal : undefined,
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error("HTTP " + response.status);
                    }

                    return response.json();
                })
                .then(function (json) {
                    if (inFlight === controller) {
                        inFlight = null;
                    }

                    language.sEmptyTable = messages.empty;
                    language.sZeroRecords = messages.zero;

                    if (extrasEl) {
                        extrasEl.innerHTML = json.extras || "";
                    }

                    callback({
                        draw: json.draw,
                        recordsTotal: json.recordsTotal,
                        recordsFiltered: json.recordsFiltered,
                        data: (json.rows || []).map(rowData).filter(Boolean),
                    });

                    if (typeof onLoad === "function") {
                        onLoad(json);
                    }
                })
                .catch(function (error) {
                    if (error && error.name === "AbortError") {
                        return;
                    }

                    if (inFlight === controller) {
                        inFlight = null;
                    }

                    // Said in the table rather than left as an empty list,
                    // which would read as "there is nothing here".
                    language.sEmptyTable = failed;
                    language.sZeroRecords = failed;

                    callback({
                        draw: data.draw,
                        recordsTotal: 0,
                        recordsFiltered: 0,
                        data: [],
                    });

                    if (global.console) {
                        global.console.error("Table load failed:", error);
                    }
                });
        };

        settings.createdRow = function (row, data, dataIndex, cells) {
            const source = data && data.serverRow;

            if (source) {
                copyAttributes(source, row);

                Array.prototype.forEach.call(source.cells, function (cell, index) {
                    if (cells[index]) {
                        copyAttributes(cell, cells[index]);
                    }
                });
            }

            if (typeof userCreatedRow === "function") {
                userCreatedRow.apply(this, arguments);
            }
        };

        return $(tableEl).DataTable(settings);
    }

    global.serverTable = serverTable;
})(window);
