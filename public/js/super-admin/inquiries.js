/**
 * Configuration > Inquiries.
 *
 * The messages written in from the public Contact page: the working table, the
 * details dialog with its status picker and reply form, and - for a Super
 * Admin - the archive.
 *
 * A file of its own rather than another wing of configuration.js, on the same
 * grounds System Contents and Project Types have theirs: the tab is a feature,
 * not a fragment of User Management.
 *
 * One rule runs through all of it. Every value in an inquiry is a stranger's
 * typing, so it is either escaped into markup or written with textContent -
 * never concatenated into HTML raw.
 */
document.addEventListener("DOMContentLoaded", function () {
    const routes = window.configurationRoutes || {};
    const options = window.configurationOptions || {};

    const body = document.querySelector("[data-inquiry-body]");

    // The tab is rendered for every administrator, but a page that has not
    // drawn it - a future layout, a partial render - must not throw here.
    if (!body || !routes.inquiries) {
        return;
    }

    // ---------------------------------------------------------------
    // Shared helpers
    //
    // Deliberately duplicated from configuration.js rather than exported from
    // it: that file keeps everything inside its own DOMContentLoaded closure,
    // and reaching into it would mean changing how it is written.
    // ---------------------------------------------------------------

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

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    function setAlert(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message || "";
        element.classList.toggle("d-none", !message);
    }

    function setBusy(button, spinner, busy) {
        if (button) {
            button.disabled = busy;
        }

        if (spinner) {
            spinner.classList.toggle("d-none", !busy);
        }
    }

    function setText(selector, value) {
        const element = document.querySelector(selector);

        if (element) {
            // textContent, not innerHTML: this is what keeps a message
            // containing markup a message rather than an element.
            element.textContent = value == null || value === "" ? "—" : value;
        }
    }

    function show(element, visible) {
        if (element) {
            element.classList.toggle("d-none", !visible);
        }
    }

    /**
     * Every request goes through here so a non-JSON error page can never
     * reject as an unhandled parse failure.
     */
    function requestJson(url, config) {
        const settings = Object.assign(
            {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            },
            config || {},
        );

        return fetch(url, settings).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (payload) {
                    return { ok: response.ok, status: response.status, body: payload };
                });
        });
    }

    function sendJson(url, method, payload) {
        const headers = {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": csrfToken(),
        };

        let requestBody;

        if (payload !== undefined) {
            headers["Content-Type"] = "application/json";
            requestBody = JSON.stringify(payload);
        }

        return requestJson(url, {
            method: method,
            headers: headers,
            body: requestBody,
        });
    }

    function bootstrapModal(element) {
        return window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(element)
            : null;
    }

    function statusCell(row) {
        return (
            '<span class="badge ' +
            escapeHtml(row.status_badge_class) +
            '">' +
            escapeHtml(row.status_label) +
            "</span>"
        );
    }

    function actionButton(action, id, icon, title, variant) {
        return (
            '<button type="button" class="btn btn-sm btn-outline-' +
            variant +
            ' py-1 px-2" data-action="' +
            action +
            '" data-inquiry-id="' +
            escapeHtml(id) +
            '" title="' +
            escapeHtml(title) +
            '" aria-label="' +
            escapeHtml(title) +
            '"><i class="bi ' +
            icon +
            '"></i></button>'
        );
    }

    // ---------------------------------------------------------------
    // Tables
    //
    // The same factory configuration.js uses for its own four tables: it owns
    // the request token, the debounce, the paging state and the rendering.
    // ---------------------------------------------------------------

    function createTable(config) {
        const tableBody = document.querySelector(config.bodySelector);
        const loading = document.querySelector(config.loadingSelector);
        const empty = document.querySelector(config.emptySelector);
        const count = document.querySelector(config.countSelector);
        const pagination = document.querySelector(config.paginationSelector);
        const search = document.querySelector(config.searchSelector);
        const errorBox = document.querySelector(config.errorSelector);
        const filters = (config.filterSelectors || []).map(function (selector) {
            return document.querySelector(selector);
        });

        let requestToken = 0;
        let searchTimer = null;
        let page = 1;

        function params() {
            const query = new URLSearchParams();

            query.set("page", String(page));

            if (search && search.value.trim()) {
                query.set("search", search.value.trim());
            }

            filters.forEach(function (filter) {
                if (filter && filter.value) {
                    query.set(filter.dataset.filterKey, filter.value);
                }
            });

            if (config.extraParams) {
                const extra = config.extraParams();

                Object.keys(extra).forEach(function (key) {
                    if (extra[key] !== null && extra[key] !== "") {
                        query.set(key, extra[key]);
                    }
                });
            }

            return query;
        }

        function renderPagination(meta) {
            if (!pagination) {
                return;
            }

            if (!meta || meta.total === 0) {
                pagination.classList.add("d-none");
                pagination.innerHTML = "";

                return;
            }

            pagination.classList.remove("d-none");
            pagination.innerHTML =
                '<span class="config-pagination-summary">Showing ' +
                meta.from +
                "&ndash;" +
                meta.to +
                " of " +
                meta.total +
                '</span><div class="btn-group btn-group-sm">' +
                '<button type="button" class="btn btn-outline-secondary" data-page="' +
                (meta.current_page - 1) +
                '"' +
                (meta.current_page <= 1 ? " disabled" : "") +
                ">Previous</button>" +
                '<button type="button" class="btn btn-outline-secondary" disabled>Page ' +
                meta.current_page +
                " of " +
                meta.last_page +
                "</button>" +
                '<button type="button" class="btn btn-outline-secondary" data-page="' +
                (meta.current_page + 1) +
                '"' +
                (meta.current_page >= meta.last_page ? " disabled" : "") +
                ">Next</button></div>";
        }

        function load() {
            const token = ++requestToken;

            loading.classList.remove("d-none");
            empty.classList.add("d-none");

            requestJson(config.url + "?" + params().toString()).then(function (result) {
                // A slower earlier request must never overwrite a later one.
                if (token !== requestToken) {
                    return;
                }

                loading.classList.add("d-none");

                if (!result.ok) {
                    tableBody.innerHTML = "";
                    setAlert(
                        errorBox,
                        result.body.error || "Could not load " + config.noun + ".",
                    );
                    renderPagination(null);

                    return;
                }

                setAlert(errorBox, "");

                const rows = result.body.rows || [];

                tableBody.innerHTML = rows.map(config.renderRow).join("");
                empty.classList.toggle("d-none", rows.length > 0);

                const meta = result.body.meta || {};

                if (count) {
                    count.textContent =
                        meta.total +
                        " " +
                        (meta.total === 1 ? config.singular : config.noun);
                }

                renderPagination(meta);

                if (config.onLoaded) {
                    config.onLoaded(result.body);
                }
            });
        }

        if (search) {
            // Debounced so a request isn't fired on every keystroke.
            search.addEventListener("input", function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    page = 1;
                    load();
                }, 300);
            });
        }

        filters.forEach(function (filter) {
            if (filter) {
                filter.addEventListener("change", function () {
                    page = 1;
                    load();
                });
            }
        });

        if (pagination) {
            pagination.addEventListener("click", function (event) {
                const button = event.target.closest("[data-page]");

                if (!button || button.disabled) {
                    return;
                }

                page = Number(button.dataset.page);
                load();
            });
        }

        return {
            load: load,
            reload: load,
            reset: function () {
                page = 1;
                load();
            },
            element: tableBody,
        };
    }

    // The filter key each select contributes to the query string.
    [
        ["[data-inquiry-status]", "status"],
        ["[data-archived-inquiry-status]", "status"],
    ].forEach(function (pair) {
        const element = document.querySelector(pair[0]);

        if (element) {
            element.dataset.filterKey = pair[1];
        }
    });

    // ---------------------------------------------------------------
    // The working table
    // ---------------------------------------------------------------

    const pageError = document.querySelector("[data-inquiry-error]");
    const pageSuccess = document.querySelector("[data-inquiry-success]");
    const newCount = document.querySelector("[data-inquiry-new-count]");
    const sortButton = document.querySelector("[data-inquiry-sort]");

    // Newest first, which is how a queue of messages is read.
    let sortDirection = "desc";

    const inquiryTable = createTable({
        url: routes.inquiries,
        bodySelector: "[data-inquiry-body]",
        loadingSelector: "[data-inquiry-loading]",
        emptySelector: "[data-inquiry-empty]",
        countSelector: "[data-inquiry-count]",
        paginationSelector: "[data-inquiry-pagination]",
        searchSelector: "[data-inquiry-search]",
        errorSelector: "[data-inquiry-error]",
        filterSelectors: ["[data-inquiry-status]"],
        noun: "inquiries",
        singular: "inquiry",
        extraParams: function () {
            return { direction: sortDirection };
        },
        renderRow: function (row) {
            return (
                '<tr class="' +
                (row.is_new ? "fw-semibold" : "") +
                '">' +
                '<td class="config-code-cell">' +
                escapeHtml(row.code) +
                "</td>" +
                "<td>" +
                escapeHtml(row.name) +
                "</td>" +
                "<td>" +
                escapeHtml(row.email) +
                "</td>" +
                '<td class="config-inquiry-subject">' +
                escapeHtml(row.subject) +
                "</td>" +
                "<td>" +
                statusCell(row) +
                "</td>" +
                "<td>" +
                escapeHtml(row.submitted_at) +
                "</td>" +
                '<td class="text-center"><div class="d-inline-flex gap-1">' +
                actionButton("view", row.id, "bi-eye", "View inquiry", "primary") +
                actionButton(
                    "archive",
                    row.id,
                    "bi-archive",
                    "Archive inquiry",
                    "danger",
                ) +
                "</div></td>" +
                "</tr>"
            );
        },
        onLoaded: function (payload) {
            if (!newCount) {
                return;
            }

            const unhandled = Number(payload.unhandled || 0);

            newCount.textContent = String(unhandled);
            newCount.classList.toggle("d-none", unhandled === 0);
        },
    });

    // ---------------------------------------------------------------
    // The details dialog
    // ---------------------------------------------------------------

    const detailModalEl = document.querySelector("[data-inquiry-modal]");
    const detailModal = detailModalEl ? bootstrapModal(detailModalEl) : null;
    const detailLoading = document.querySelector("[data-inquiry-detail-loading]");
    const detailBody = document.querySelector("[data-inquiry-detail-body]");
    const detailError = document.querySelector("[data-inquiry-detail-error]");
    const detailSuccess = document.querySelector("[data-inquiry-detail-success]");

    const statusSelect = document.querySelector("[data-inquiry-status-select]");
    const statusSave = document.querySelector("[data-inquiry-status-save]");
    const statusSpinner = document.querySelector("[data-inquiry-status-spinner]");

    const replyForm = document.querySelector("[data-inquiry-reply-form]");
    const replyTo = document.querySelector("[data-inquiry-reply-to]");
    const replyMessage = document.querySelector("[data-inquiry-reply-message]");
    const replySend = document.querySelector("[data-inquiry-reply-send]");
    const replySpinner = document.querySelector("[data-inquiry-reply-spinner]");
    const replyRecord = document.querySelector("[data-inquiry-reply-record]");

    const archivedNote = document.querySelector("[data-inquiry-archived-note]");
    const archiveButton = document.querySelector("[data-inquiry-archive]");

    // The inquiry the dialog is currently showing.
    let current = null;

    function renderDetail(inquiry) {
        current = inquiry;

        setText("[data-inquiry-detail-code]", inquiry.code);
        setText("[data-inquiry-detail-name]", inquiry.name);
        setText("[data-inquiry-detail-email]", inquiry.email);
        setText("[data-inquiry-detail-submitted]", inquiry.submitted_at);
        setText("[data-inquiry-detail-updated]", inquiry.updated_at);
        setText("[data-inquiry-detail-subject]", inquiry.subject);
        setText("[data-inquiry-detail-message]", inquiry.message);

        if (statusSelect) {
            statusSelect.value = inquiry.status;
        }

        if (replyTo) {
            // Shown so the sender knows where it is going; the server reads
            // the address off the inquiry either way, so nothing typed here
            // could redirect a reply.
            replyTo.value = inquiry.email;
        }

        show(replyRecord, inquiry.has_reply);

        if (inquiry.has_reply) {
            setText("[data-inquiry-reply-text]", inquiry.reply_message);
            setText("[data-inquiry-replied-at]", inquiry.replied_at);
            setText("[data-inquiry-replied-by]", inquiry.replied_by);
        }

        // An archived inquiry is a record, not a task: it is read until
        // somebody puts it back on the active list.
        show(archivedNote, inquiry.is_archived);
        show(replyForm, !inquiry.is_archived);

        if (statusSelect) {
            statusSelect.disabled = inquiry.is_archived;
        }

        if (statusSave) {
            statusSave.disabled = inquiry.is_archived;
        }

        if (archiveButton) {
            show(archiveButton, !inquiry.is_archived);
        }

        show(detailBody, true);
    }

    function openInquiry(id) {
        if (!detailModal) {
            return;
        }

        setAlert(detailError, "");
        setAlert(detailSuccess, "");
        show(detailBody, false);
        show(detailLoading, true);

        if (replyMessage) {
            replyMessage.value = "";
        }

        detailModal.show();

        requestJson(routes.inquiryBase + "/" + id).then(function (result) {
            show(detailLoading, false);

            if (!result.ok) {
                setAlert(
                    detailError,
                    result.body.error || "Unable to open inquiry.",
                );

                return;
            }

            renderDetail(result.body.inquiry);
        });
    }

    if (statusSave) {
        statusSave.addEventListener("click", function () {
            if (!current || !statusSelect) {
                return;
            }

            setAlert(detailError, "");
            setAlert(detailSuccess, "");
            setBusy(statusSave, statusSpinner, true);

            sendJson(
                routes.inquiryBase + "/" + current.id + "/status",
                "PUT",
                { status: statusSelect.value },
            ).then(function (result) {
                setBusy(statusSave, statusSpinner, false);

                if (!result.ok) {
                    setAlert(
                        detailError,
                        result.body.error || "Unable to change status.",
                    );
                    // Put the picker back to what the record actually says, so
                    // the screen never shows a status that was refused.
                    statusSelect.value = current.status;

                    return;
                }

                renderDetail(result.body.inquiry);
                setAlert(detailSuccess, result.body.message);
                inquiryTable.reload();
            });
        });
    }

    if (replySend) {
        replySend.addEventListener("click", function () {
            if (!current || !replyMessage) {
                return;
            }

            setAlert(detailError, "");
            setAlert(detailSuccess, "");
            setBusy(replySend, replySpinner, true);

            sendJson(routes.inquiryBase + "/" + current.id + "/reply", "POST", {
                message: replyMessage.value,
            }).then(function (result) {
                setBusy(replySend, replySpinner, false);

                if (!result.ok) {
                    // The reply was not sent, so the inquiry is unchanged and
                    // what was typed stays in the box for another attempt.
                    setAlert(
                        detailError,
                        result.body.error || "Unable to send reply.",
                    );

                    return;
                }

                replyMessage.value = "";
                renderDetail(result.body.inquiry);
                setAlert(detailSuccess, result.body.message);
                inquiryTable.reload();
            });
        });
    }

    if (archiveButton) {
        archiveButton.addEventListener("click", function () {
            if (!current) {
                return;
            }

            archiveInquiry(current.id, detailError, function () {
                setAlert(detailSuccess, "Inquiry archived. Nothing was deleted.");

                if (detailModal) {
                    detailModal.hide();
                }
            });
        });
    }

    /**
     * Archiving, from either the row button or the dialog's own.
     */
    function archiveInquiry(id, errorBox, onDone) {
        setAlert(errorBox, "");

        sendJson(routes.inquiryBase + "/" + id, "DELETE").then(function (result) {
            if (!result.ok) {
                setAlert(
                    errorBox,
                    result.body.error || "Unable to archive inquiry.",
                );

                return;
            }

            inquiryTable.reload();

            if (archivedTable) {
                archivedTable.reload();
            }

            if (onDone) {
                onDone();
            }
        });
    }

    inquiryTable.element.addEventListener("click", function (event) {
        const button = event.target.closest("[data-action]");

        if (!button) {
            return;
        }

        const id = button.dataset.inquiryId;

        if (button.dataset.action === "view") {
            openInquiry(id);

            return;
        }

        if (button.dataset.action === "archive") {
            button.disabled = true;

            archiveInquiry(id, pageError, function () {
                setAlert(pageSuccess, "Inquiry archived. Nothing was deleted.");
            });
        }
    });

    // ---------------------------------------------------------------
    // The archive
    //
    // Super Admin only: the dialog is not rendered for an Admin, so this
    // whole section stands down when it is absent - and the route refuses it
    // either way.
    // ---------------------------------------------------------------

    const archivedModalEl = document.querySelector("[data-archived-inquiry-modal]");
    const archivedError = document.querySelector("[data-archived-inquiry-error]");

    const archivedTable =
        archivedModalEl && routes.archivedInquiries
            ? createTable({
                  url: routes.archivedInquiries,
                  bodySelector: "[data-archived-inquiry-body]",
                  loadingSelector: "[data-archived-inquiry-loading]",
                  emptySelector: "[data-archived-inquiry-empty]",
                  countSelector: "[data-archived-inquiry-count]",
                  paginationSelector: "[data-archived-inquiry-pagination]",
                  searchSelector: "[data-archived-inquiry-search]",
                  errorSelector: "[data-archived-inquiry-error]",
                  filterSelectors: ["[data-archived-inquiry-status]"],
                  noun: "archived inquiries",
                  singular: "archived inquiry",
                  renderRow: function (row) {
                      return (
                          "<tr>" +
                          '<td class="config-code-cell">' +
                          escapeHtml(row.code) +
                          "</td>" +
                          "<td>" +
                          escapeHtml(row.name) +
                          "</td>" +
                          "<td>" +
                          escapeHtml(row.email) +
                          "</td>" +
                          '<td class="config-inquiry-subject">' +
                          escapeHtml(row.subject) +
                          "</td>" +
                          "<td>" +
                          statusCell(row) +
                          "</td>" +
                          "<td>" +
                          escapeHtml(row.submitted_at) +
                          "</td>" +
                          "<td>" +
                          escapeHtml(row.archived_at) +
                          "</td>" +
                          "<td>" +
                          escapeHtml(row.archived_by) +
                          "</td>" +
                          '<td class="text-center"><div class="d-inline-flex gap-1">' +
                          actionButton(
                              "view",
                              row.id,
                              "bi-eye",
                              "View inquiry",
                              "primary",
                          ) +
                          '<button type="button" class="btn btn-sm btn-success py-1 px-2" ' +
                          'data-restore-inquiry="' +
                          escapeHtml(row.id) +
                          '"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button>' +
                          "</div></td>" +
                          "</tr>"
                      );
                  },
              })
            : null;

    if (archivedModalEl && archivedTable) {
        // Loaded on open rather than on page load: most visits never touch
        // the archive.
        archivedModalEl.addEventListener("show.bs.modal", function () {
            setAlert(archivedError, "");
            archivedTable.reset();
        });

        archivedTable.element.addEventListener("click", function (event) {
            const restore = event.target.closest("[data-restore-inquiry]");

            if (restore) {
                restore.disabled = true;
                setAlert(archivedError, "");

                sendJson(
                    routes.inquiryBase + "/" + restore.dataset.restoreInquiry + "/restore",
                    "PUT",
                ).then(function (result) {
                    restore.disabled = false;

                    if (!result.ok) {
                        setAlert(
                            archivedError,
                            result.body.error || "Unable to restore inquiry.",
                        );

                        return;
                    }

                    // It has moved from one list to the other, so both are
                    // redrawn.
                    archivedTable.reload();
                    inquiryTable.reload();
                });

                return;
            }

            const view = event.target.closest('[data-action="view"]');

            if (view) {
                openInquiry(view.dataset.inquiryId);
            }
        });
    }

    // ---------------------------------------------------------------
    // Sorting, and when the table is first read
    // ---------------------------------------------------------------

    if (sortButton) {
        sortButton.addEventListener("click", function () {
            sortDirection = sortDirection === "desc" ? "asc" : "desc";
            sortButton.classList.toggle("is-ascending", sortDirection === "asc");
            inquiryTable.reset();
        });
    }

    // Loaded when the tab is first opened rather than on page load - most
    // visits to Configuration never reach it - unless a notification asked
    // for one inquiry by id, in which case the page opens on it.
    const tab = document.querySelector("#inquiriesTab");
    let loaded = false;

    function loadOnce() {
        if (loaded) {
            return;
        }

        loaded = true;
        inquiryTable.load();
    }

    if (tab) {
        tab.addEventListener("shown.bs.tab", loadOnce);
    }

    if (options.openInquiry) {
        loadOnce();

        if (tab && window.bootstrap) {
            window.bootstrap.Tab.getOrCreateInstance(tab).show();
        }

        openInquiry(options.openInquiry);
    }

    // Arriving from the dashboard's "N Pending Inquiries". The filter is set
    // before the first request rather than changed after it, so the table is
    // fetched already narrowed - one request, and no flash of the whole list.
    if (options.openInquiries) {
        const statusFilter = document.querySelector("[data-inquiry-status]");

        if (statusFilter) {
            statusFilter.value = options.openInquiries;
        }

        loadOnce();

        if (tab && window.bootstrap) {
            window.bootstrap.Tab.getOrCreateInstance(tab).show();
        }
    }
});
