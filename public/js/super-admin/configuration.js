document.addEventListener("DOMContentLoaded", function () {
    const routes = window.configurationRoutes || {};
    const options = window.configurationOptions || {};
    const technicianRoles = options.technicianRoles || [];

    // ---------------------------------------------------------------
    // Shared helpers
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

    /**
     * A refused role change, printed as the two sentences it came with and a
     * compact list of the projects standing in the way.
     *
     * Each project links to its Assigned Team, which is where the block is
     * lifted: a role change is never allowed to pick a lead, so somebody has
     * to open the project and settle it by hand. Built as nodes rather than
     * markup so a project name is text and stays text.
     */
    function setRoleChangeAlert(element, details) {
        if (!element) {
            return;
        }

        element.textContent = "";
        element.classList.remove("d-none");

        const headline = document.createElement("div");
        headline.className = "fw-semibold";
        headline.textContent = details.message || "";
        element.appendChild(headline);

        if (details.action) {
            const action = document.createElement("div");
            action.textContent = details.action;
            element.appendChild(action);
        }

        const projects = details.projects || [];

        if (!projects.length) {
            return;
        }

        const list = document.createElement("ul");
        list.className = "list-unstyled small mb-0 mt-2";

        projects.forEach(function (project) {
            const item = document.createElement("li");
            item.className =
                "d-flex justify-content-between align-items-center gap-3 py-1 border-top";

            const name = document.createElement("span");
            name.className = "text-truncate";
            name.textContent = project.name || "Untitled project";
            item.appendChild(name);

            const link = document.createElement("a");
            link.className = "fw-semibold text-decoration-underline flex-shrink-0";
            link.href = project.url;
            // A new tab: the account being edited is still open behind this,
            // half-saved, and closing it to go and look would lose the edit.
            link.target = "_blank";
            link.rel = "noopener";
            link.textContent = "View";
            item.appendChild(link);

            list.appendChild(item);
        });

        element.appendChild(list);
    }

    function setBusy(button, spinner, busy) {
        if (button) {
            button.disabled = busy;
        }

        if (spinner) {
            spinner.classList.toggle("d-none", !busy);
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
                .then(function (body) {
                    return { ok: response.ok, status: response.status, body: body };
                });
        });
    }

    /**
     * POST/PUT/DELETE with the CSRF token attached. FormData is sent as-is so
     * the profile picture rides along; anything else goes as JSON.
     */
    function sendJson(url, method, payload) {
        const headers = {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": csrfToken(),
        };

        let body = payload;

        if (payload instanceof FormData) {
            payload.set("_token", csrfToken());
        } else if (payload !== undefined) {
            headers["Content-Type"] = "application/json";
            body = JSON.stringify(payload);
        }

        return requestJson(url, { method: method, headers: headers, body: body });
    }

    function bootstrapModal(element) {
        return window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(element)
            : null;
    }

    /**
     * The Clipboard API needs a secure context, which a plain http:// host is
     * not, so fall back to selecting the field and letting execCommand copy.
     */
    function copyToClipboard(input, button) {
        const done = function () {
            if (!button) {
                return;
            }

            const icon = button.querySelector("i");

            if (!icon) {
                return;
            }

            icon.className = "bi bi-check-lg";
            window.setTimeout(function () {
                icon.className = "bi bi-clipboard";
            }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done, function () {
                legacyCopy(input);
                done();
            });

            return;
        }

        legacyCopy(input);
        done();
    }

    function legacyCopy(input) {
        input.removeAttribute("readonly");
        input.select();
        input.setSelectionRange(0, input.value.length);
        document.execCommand("copy");
        input.setAttribute("readonly", "readonly");
        input.blur();
    }

    /**
     * A row's person: their picture where they have one, their initials where
     * they never will. Registered Users carry no picture anywhere in the system, so the
     * server sends avatar_url as null for them and this shows the initials
     * instead.
     */
    function avatarCell(row) {
        const avatar = row.avatar_url
            ? '<img class="config-avatar" src="' +
              escapeHtml(row.avatar_url) +
              '" alt="">'
            : '<span class="config-avatar">' +
              escapeHtml(row.initials) +
              "</span>";

        return (
            '<div class="config-user-cell">' +
            avatar +
            '<span class="fw-semibold">' +
            escapeHtml(row.full_name) +
            "</span></div>"
        );
    }

    function statusCell(row) {
        return (
            '<span class="badge ' +
            row.status_badge_class +
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
            '" data-user-id="' +
            id +
            '" title="' +
            escapeHtml(title) +
            '" aria-label="' +
            escapeHtml(title) +
            '"><i class="bi ' +
            icon +
            '"></i></button>'
        );
    }

    /**
     * Archiving belongs to the Super Admin. An Admin manages accounts right up
     * to deactivating them; taking one out of the system is not theirs, so the
     * button is not drawn - and the route refuses it either way.
     */
    function archiveButton(id) {
        if (!options.canArchive) {
            return "";
        }

        return actionButton("archive", id, "bi-trash", "Archive account", "danger");
    }

    /**
     * What an Admin sees beside a Super Admin's row.
     *
     * The account stays listed - the system's owner is not hidden from the
     * people they manage - but none of the actions are drawn, because every
     * one of them is refused server-side. A short reason reads better than a
     * blank cell somebody would otherwise report as a missing button.
     */
    function outranksNote() {
        return '<span class="text-muted small">Super Admin only</span>';
    }

    const pageError = document.querySelector("[data-user-error]");

    // ---------------------------------------------------------------
    // Tables
    // ---------------------------------------------------------------

    /**
     * Both tables behave identically apart from their columns and filters, so
     * one factory drives them: it owns the request token, the debounce, the
     * paging state and the rendering.
     */
    function createTable(config) {
        const body = document.querySelector(config.bodySelector);
        const loading = document.querySelector(config.loadingSelector);
        const empty = document.querySelector(config.emptySelector);
        const count = document.querySelector(config.countSelector);
        const pagination = document.querySelector(config.paginationSelector);
        const search = document.querySelector(config.searchSelector);
        // A table inside a dialog needs its own error line: the page-level one
        // sits behind the backdrop where nobody would read it.
        const errorBox = config.errorSelector
            ? document.querySelector(config.errorSelector)
            : pageError;
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

            requestJson(config.url + "?" + params().toString()).then(
                function (result) {
                    if (token !== requestToken) {
                        return;
                    }

                    loading.classList.add("d-none");

                    if (!result.ok) {
                        body.innerHTML = "";
                        setAlert(
                            errorBox,
                            result.body.error ||
                                "Could not load " + (config.noun || "accounts") + ".",
                        );
                        renderPagination(null);

                        return;
                    }

                    setAlert(errorBox, "");

                    const rows = result.body.rows || [];

                    body.innerHTML = rows.map(config.renderRow).join("");
                    empty.classList.toggle("d-none", rows.length > 0);

                    const meta = result.body.meta || {};

                    if (count) {
                        const noun = config.noun || "accounts";

                        count.textContent =
                            meta.total +
                            " " +
                            (meta.total === 1 ? (config.singular || "account") : noun);
                    }

                    renderPagination(meta);
                },
            );
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
            reload: function () {
                load();
            },
            reset: function () {
                page = 1;
                load();
            },
            element: body,
        };
    }

    const employeeTable = createTable({
        url: routes.employees,
        bodySelector: "[data-employee-body]",
        loadingSelector: "[data-employee-loading]",
        emptySelector: "[data-employee-empty]",
        countSelector: "[data-employee-count]",
        paginationSelector: "[data-employee-pagination]",
        searchSelector: "[data-employee-search]",
        filterSelectors: ["[data-employee-role]", "[data-employee-status]"],
        renderRow: function (row) {
            return (
                "<tr>" +
                '<td class="config-code-cell">' +
                escapeHtml(row.user_code) +
                "</td>" +
                "<td>" +
                avatarCell(row) +
                "</td>" +
                "<td>" +
                escapeHtml(row.role_label) +
                "</td>" +
                "<td>" +
                escapeHtml(row.email) +
                "</td>" +
                "<td>" +
                statusCell(row) +
                "</td>" +
                '<td class="text-center"><div class="config-row-actions">' +
                (row.manageable === false
                    ? outranksNote()
                    : actionButton(
                          "edit",
                          row.id,
                          "bi-pencil",
                          "Edit employee",
                          "primary",
                      ) +
                      actionButton(
                          "reset-password",
                          row.id,
                          "bi-key",
                          "Reset password",
                          "secondary",
                      ) +
                      (row.is_active
                          ? actionButton(
                                "deactivate",
                                row.id,
                                "bi-lock",
                                "Deactivate account",
                                "warning",
                            )
                          : actionButton(
                                "activate",
                                row.id,
                                "bi-unlock",
                                "Activate account",
                                "success",
                            )) +
                      archiveButton(row.id)) +
                "</div></td>" +
                "</tr>"
            );
        },
    });

    const clientTable = createTable({
        url: routes.clients,
        bodySelector: "[data-client-body]",
        loadingSelector: "[data-client-loading]",
        emptySelector: "[data-client-empty]",
        countSelector: "[data-client-count]",
        paginationSelector: "[data-client-pagination]",
        searchSelector: "[data-client-search]",
        filterSelectors: ["[data-client-status]"],
        renderRow: function (row) {
            return (
                "<tr>" +
                '<td class="config-code-cell">' +
                escapeHtml(row.user_code) +
                "</td>" +
                "<td>" +
                avatarCell(row) +
                "</td>" +
                "<td>" +
                escapeHtml(row.email) +
                "</td>" +
                "<td>" +
                statusCell(row) +
                "</td>" +
                '<td class="text-center"><div class="config-row-actions">' +
                actionButton(
                    "edit",
                    row.id,
                    "bi-pencil",
                    "Edit registered user",
                    "primary",
                ) +
                actionButton(
                    "view-projects",
                    row.id,
                    "bi-folder2-open",
                    "View projects",
                    "info",
                ) +
                actionButton(
                    "reset-password",
                    row.id,
                    "bi-key",
                    "Reset password",
                    "secondary",
                ) +
                (row.is_active
                    ? actionButton(
                          "deactivate",
                          row.id,
                          "bi-lock",
                          "Deactivate account",
                          "warning",
                      )
                    : actionButton(
                          "activate",
                          row.id,
                          "bi-unlock",
                          "Activate account",
                          "success",
                      )) +
                archiveButton(row.id) +
                "</div></td>" +
                "</tr>"
            );
        },
    });

    // ---------------------------------------------------------------
    // Archived Accounts
    //
    // The other end of archiving, and Super Admin only for the same reason:
    // the dialog is not rendered for an Admin, so this whole section stands
    // down when it is absent.
    // ---------------------------------------------------------------

    const archivedModalEl = document.querySelector("[data-archived-modal]");
    const archivedError = document.querySelector("[data-archived-error]");

    const archivedTable = archivedModalEl
        ? createTable({
              url: routes.archivedAccounts,
              bodySelector: "[data-archived-body]",
              loadingSelector: "[data-archived-loading]",
              emptySelector: "[data-archived-empty]",
              countSelector: "[data-archived-count]",
              paginationSelector: "[data-archived-pagination]",
              searchSelector: "[data-archived-search]",
              errorSelector: "[data-archived-error]",
              filterSelectors: ["[data-archived-role]"],
              noun: "archived accounts",
              singular: "archived account",
              renderRow: function (row) {
                  return (
                      "<tr>" +
                      '<td class="config-code-cell">' +
                      escapeHtml(row.user_code) +
                      "</td>" +
                      "<td>" +
                      escapeHtml(row.full_name) +
                      "</td>" +
                      "<td>" +
                      escapeHtml(row.email) +
                      "</td>" +
                      "<td>" +
                      escapeHtml(row.role_label) +
                      "</td>" +
                      '<td><span class="badge ' +
                      escapeHtml(row.status_badge_class) +
                      '">' +
                      escapeHtml(row.status_label) +
                      "</span></td>" +
                      "<td>" +
                      escapeHtml(row.archived_at) +
                      "</td>" +
                      "<td>" +
                      escapeHtml(row.archived_by) +
                      "</td>" +
                      '<td class="text-center">' +
                      '<button type="button" class="btn btn-sm btn-success py-1 px-2" ' +
                      'data-restore-user="' +
                      row.id +
                      '"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button>' +
                      "</td>" +
                      "</tr>"
                  );
              },
          })
        : null;

    if (archivedModalEl && archivedTable) {
        // Loaded on open rather than on page load: most visits to
        // Configuration never touch the archive.
        archivedModalEl.addEventListener("show.bs.modal", function () {
            setAlert(archivedError, "");
            archivedTable.reset();
        });

        archivedTable.element.addEventListener("click", function (event) {
            const button = event.target.closest("[data-restore-user]");

            if (!button) {
                return;
            }

            const id = button.dataset.restoreUser;

            button.disabled = true;
            setAlert(archivedError, "");

            sendJson(routes.userBase + "/" + id + "/restore", "PUT").then(
                function (result) {
                    button.disabled = false;

                    if (!result.ok) {
                        setAlert(
                            archivedError,
                            result.body.error || "Unable to restore account.",
                        );

                        return;
                    }

                    // The account has moved from one list to the other, so
                    // both have to be redrawn.
                    archivedTable.reload();
                    refreshTables();
                },
            );
        });
    }

    // The filter key each select contributes to the query string.
    [
        ["[data-employee-role]", "role"],
        ["[data-employee-status]", "status"],
        ["[data-client-status]", "status"],
        ["[data-archived-role]", "role"],
        ["[data-log-role]", "role"],
        ["[data-log-module]", "module"],
        ["[data-log-range]", "range"],
    ].forEach(function (pair) {
        const element = document.querySelector(pair[0]);

        if (element) {
            element.dataset.filterKey = pair[1];
        }
    });

    // ---------------------------------------------------------------
    // Activity Logs
    // ---------------------------------------------------------------

    const logRange = document.querySelector("[data-log-range]");
    const logCustom = document.querySelector("[data-log-custom-range]");
    const logFrom = document.querySelector("[data-log-from]");
    const logTo = document.querySelector("[data-log-to]");
    const logSorts = document.querySelectorAll("[data-log-sort]");

    // Newest first, which is what an audit trail is read in.
    let logSort = "date";
    let logDirection = "desc";

    const activityLogTable = document.querySelector("[data-log-body]")
        ? createTable({
              url: routes.activityLogs,
              bodySelector: "[data-log-body]",
              loadingSelector: "[data-log-loading]",
              emptySelector: "[data-log-empty]",
              countSelector: "[data-log-count]",
              paginationSelector: "[data-log-pagination]",
              searchSelector: "[data-log-search]",
              noun: "entries",
              singular: "entry",
              filterSelectors: [
                  "[data-log-role]",
                  "[data-log-module]",
                  "[data-log-range]",
              ],
              extraParams: function () {
                  const extra = { sort: logSort, direction: logDirection };

                  // Only meaningful for a custom window; sending them
                  // otherwise would just be noise in the query string.
                  if (logRange && logRange.value === "custom") {
                      extra.from = logFrom ? logFrom.value : "";
                      extra.to = logTo ? logTo.value : "";
                  }

                  return extra;
              },
              renderRow: function (row) {
                  return (
                      "<tr>" +
                      '<td class="config-code-cell">#' +
                      row.id +
                      "</td>" +
                      "<td>" +
                      escapeHtml(row.logged_at) +
                      "</td>" +
                      '<td class="fw-semibold">' +
                      escapeHtml(row.actor_name) +
                      "</td>" +
                      '<td><span class="badge ' +
                      row.role_badge_class +
                      '">' +
                      escapeHtml(row.role_label) +
                      "</span></td>" +
                      "<td>" +
                      escapeHtml(row.module) +
                      "</td>" +
                      "<td>" +
                      escapeHtml(row.action) +
                      "</td>" +
                      '<td class="config-log-details">' +
                      escapeHtml(row.description) +
                      "</td>" +
                      "</tr>"
                  );
              },
          })
        : null;

    if (activityLogTable) {
        // The date inputs are part of the filter, but only once the range is
        // set to Custom.
        if (logRange && logCustom) {
            logRange.addEventListener("change", function () {
                const custom = logRange.value === "custom";

                logCustom.classList.toggle("d-none", !custom);

                // A half-filled custom range would return everything, so the
                // reload waits until both ends are given.
                if (custom && (!logFrom.value || !logTo.value)) {
                    return;
                }

                activityLogTable.reset();
            });
        }

        [logFrom, logTo].forEach(function (input) {
            if (!input) {
                return;
            }

            input.addEventListener("change", function () {
                if (logFrom.value && logTo.value) {
                    activityLogTable.reset();
                }
            });
        });

        logSorts.forEach(function (button) {
            button.addEventListener("click", function () {
                const column = button.dataset.logSort;

                // Clicking the active column flips it; a new column starts
                // descending, which is the useful default for all of them.
                logDirection =
                    logSort === column && logDirection === "desc" ? "asc" : "desc";
                logSort = column;

                logSorts.forEach(function (other) {
                    other.classList.toggle("is-active", other === button);
                    other.classList.toggle(
                        "is-ascending",
                        other === button && logDirection === "asc",
                    );
                });

                activityLogTable.reset();
            });
        });

        // -----------------------------------------------------------
        // Export
        // -----------------------------------------------------------
        // The dialog owns its own date range and user. Everything else the
        // table is currently narrowed by is copied into hidden fields as the
        // dialog opens, so the file matches the list that was being looked at.
        //
        // Nothing here decides what may be exported. The endpoint validates
        // every field again and narrows the rows to what this account may
        // read, so a hidden input edited in the console buys nothing.
        const exportForm = document.querySelector("[data-log-export-form]");
        const exportModalEl = document.querySelector("#exportLogsModal");

        if (exportForm && exportModalEl) {
            const exportFrom = exportForm.querySelector("[data-log-export-from]");
            const exportTo = exportForm.querySelector("[data-log-export-to]");

            // -------------------------------------------------------
            // The user filter: a search box, not a list
            // -------------------------------------------------------
            // Every account that has ever done anything can appear here, and
            // that is a list nobody scrolls. So it is typed into and the
            // matches drop down beneath, the way every other search on this
            // page works.
            //
            // The hidden field is what is submitted, and only picking somebody
            // sets it - a half-typed name is not a filter. Empty means every
            // user, which is why nothing has to be chosen for the common case.
            const actorSearch = exportForm.querySelector(
                "[data-log-export-user-search]",
            );
            const actorId = exportForm.querySelector("[data-log-export-user-id]");
            const actorResults = exportForm.querySelector(
                "[data-log-export-user-results]",
            );
            const actorClear = exportForm.querySelector(
                "[data-log-export-user-clear]",
            );

            // Already narrowed server-side to the accounts this administrator
            // may read entries for, so the search has no rules of its own.
            const actors = Array.isArray(
                window.configurationOptions && window.configurationOptions.logActors,
            )
                ? window.configurationOptions.logActors
                : [];

            const ACTOR_RESULT_LIMIT = 8;

            function actorLabel(actor) {
                return actor.name + " — " + actor.role;
            }

            function closeActorResults() {
                if (!actorResults) {
                    return;
                }

                actorResults.classList.add("d-none");
                actorResults.innerHTML = "";

                if (actorSearch) {
                    actorSearch.setAttribute("aria-expanded", "false");
                }
            }

            function chooseActor(actor) {
                if (actorId) {
                    actorId.value = String(actor.id);
                }

                if (actorSearch) {
                    actorSearch.value = actorLabel(actor);
                }

                if (actorClear) {
                    actorClear.classList.remove("d-none");
                }

                closeActorResults();
            }

            function clearActor(refocus) {
                if (actorId) {
                    actorId.value = "";
                }

                if (actorSearch) {
                    actorSearch.value = "";
                }

                if (actorClear) {
                    actorClear.classList.add("d-none");
                }

                closeActorResults();

                if (refocus && actorSearch) {
                    actorSearch.focus();
                }
            }

            function renderActorResults(term) {
                if (!actorResults) {
                    return;
                }

                const needle = term.trim().toLowerCase();

                if (!needle) {
                    closeActorResults();

                    return;
                }

                // Name, account code and address all match, because all three
                // are things somebody knows an account by.
                const matches = actors
                    .filter(function (actor) {
                        return [actor.name, actor.code, actor.email, actor.role].some(
                            function (field) {
                                return (
                                    field &&
                                    String(field).toLowerCase().indexOf(needle) !== -1
                                );
                            },
                        );
                    })
                    .slice(0, ACTOR_RESULT_LIMIT);

                if (!matches.length) {
                    actorResults.innerHTML =
                        '<li class="config-actor-empty">No matching user. Leave it empty for all users.</li>';
                    actorResults.classList.remove("d-none");
                    actorSearch.setAttribute("aria-expanded", "true");

                    return;
                }

                actorResults.innerHTML = matches
                    .map(function (actor) {
                        return (
                            '<li><button type="button" class="config-actor-option" data-actor-id="' +
                            escapeHtml(String(actor.id)) +
                            '" role="option">' +
                            '<span class="config-actor-name">' +
                            escapeHtml(actor.name) +
                            "</span>" +
                            '<span class="config-actor-meta">' +
                            escapeHtml(actor.role) +
                            (actor.code ? " · " + escapeHtml(actor.code) : "") +
                            "</span>" +
                            "</button></li>"
                        );
                    })
                    .join("");

                actorResults.classList.remove("d-none");
                actorSearch.setAttribute("aria-expanded", "true");
            }

            if (actorSearch) {
                actorSearch.addEventListener("input", function () {
                    // Editing after picking somebody drops the pick: the box
                    // must never show one name and submit another.
                    if (actorId && actorId.value) {
                        actorId.value = "";

                        if (actorClear) {
                            actorClear.classList.add("d-none");
                        }
                    }

                    renderActorResults(actorSearch.value);
                });

                actorSearch.addEventListener("keydown", function (event) {
                    if (event.key === "Escape") {
                        closeActorResults();
                    }

                    // Enter in the search box picks the first match rather
                    // than submitting the form half-filled.
                    if (event.key === "Enter" && actorResults) {
                        const first = actorResults.querySelector(
                            "[data-actor-id]",
                        );

                        if (first) {
                            event.preventDefault();
                            first.click();
                        }
                    }
                });
            }

            if (actorResults) {
                actorResults.addEventListener("click", function (event) {
                    const option = event.target.closest("[data-actor-id]");

                    if (!option) {
                        return;
                    }

                    const chosen = actors.find(function (actor) {
                        return String(actor.id) === option.dataset.actorId;
                    });

                    if (chosen) {
                        chooseActor(chosen);
                    }
                });
            }

            if (actorClear) {
                actorClear.addEventListener("click", function () {
                    clearActor(true);
                });
            }

            // A name typed but never picked is not a filter, and it must not
            // look like one on the way out either.
            exportForm.addEventListener("submit", function () {
                if (actorId && !actorId.value && actorSearch) {
                    actorSearch.value = "";
                }
            });

            document.addEventListener("click", function (event) {
                if (!exportForm.contains(event.target)) {
                    closeActorResults();
                }
            });

            // The table's own date filter is deliberately not among these:
            // the dialog's two date fields are required and are what decide
            // the exported period.
            const carried = [
                ["[data-log-export-search]", "[data-log-search]"],
                ["[data-log-export-role]", "[data-log-role]"],
                ["[data-log-export-module]", "[data-log-module]"],
            ];

            exportModalEl.addEventListener("show.bs.modal", function () {
                carried.forEach(function (pair) {
                    const target = exportForm.querySelector(pair[0]);
                    const source = document.querySelector(pair[1]);

                    if (target) {
                        target.value = source ? source.value : "";
                    }
                });

                const sortField = exportForm.querySelector(
                    "[data-log-export-sort]",
                );
                const directionField = exportForm.querySelector(
                    "[data-log-export-direction]",
                );

                if (sortField) {
                    sortField.value = logSort;
                }

                if (directionField) {
                    directionField.value = logDirection;
                }

                // The table's own custom window is where the dialog starts,
                // so the obvious "export what I am looking at" needs no
                // retyping. Both fields are required, so anything else the
                // table is showing leaves them to be filled in.
                if (logRange && logRange.value === "custom") {
                    if (exportFrom && logFrom) {
                        exportFrom.value = logFrom.value;
                    }

                    if (exportTo && logTo) {
                        exportTo.value = logTo.value;
                    }
                }

                // A dialog reopened is a fresh question, and nothing on the
                // table picks a user.
                clearActor(false);
            });

        }

        // Loaded when the tab is first opened rather than on page load: the
        // audit trail is the second tab, and most visits never reach it.
        const logsTab = document.querySelector("#activityLogsTab");
        let logsLoaded = false;

        if (logsTab) {
            logsTab.addEventListener("shown.bs.tab", function () {
                if (logsLoaded) {
                    return;
                }

                logsLoaded = true;
                activityLogTable.load();
            });
        }
    }

    // ---------------------------------------------------------------
    // Add / edit user form
    // ---------------------------------------------------------------

    const userModalEl = document.querySelector("[data-user-modal]");
    const userForm = document.querySelector("[data-user-form]");
    const typeStep = document.querySelector("[data-account-type-step]");
    const typeInputs = document.querySelectorAll("[data-account-type]");
    const fieldsWrap = document.querySelector("[data-user-fields]");
    const employeeOnly = document.querySelectorAll("[data-employee-only]");
    const clientOnly = document.querySelectorAll("[data-client-only]");
    const emailField = document.querySelector("[data-email-field]");
    const emailInput = userForm.querySelector('[name="email"]');
    const emailLockedNote = document.querySelector("[data-email-locked-note]");
    const roleSelect = document.querySelector("[data-role-select]");
    const specialtiesSection = document.querySelector("[data-specialties-section]");
    const specialtySelect = document.querySelector("[data-specialty-select]");
    const specialtyAdd = document.querySelector("[data-specialty-add]");
    const specialtyList = document.querySelector("[data-specialty-list]");
    const specialtyEmpty = document.querySelector("[data-specialty-empty]");
    const passwordBlock = document.querySelector("[data-password-block]");
    const passwordDisplay = document.querySelector("[data-password-display]");
    const passwordCopy = document.querySelector("[data-password-copy]");
    const passwordRegenerate = document.querySelector("[data-password-regenerate]");
    const accountHistory = document.querySelector("[data-account-history]");
    const userSubmit = document.querySelector("[data-user-submit]");
    const userSubmitLabel = document.querySelector("[data-user-submit-label]");
    const userSpinner = document.querySelector("[data-user-spinner]");
    const userFormError = document.querySelector("[data-user-form-error]");
    const userModalTitle = document.querySelector("[data-user-modal-title]");

    // The account being edited, or null while creating.
    let editing = null;
    let accountType = null;
    let selectedSkills = [];

    function toggleAll(elements, visible) {
        elements.forEach(function (element) {
            element.classList.toggle("d-none", !visible);
        });
    }

    function renderSpecialties() {
        specialtyList.innerHTML = selectedSkills
            .map(function (skill) {
                return (
                    '<span class="config-chip">' +
                    escapeHtml(skill.name) +
                    '<button type="button" data-specialty-remove="' +
                    skill.id +
                    '" aria-label="Remove ' +
                    escapeHtml(skill.name) +
                    '">&times;</button></span>'
                );
            })
            .join("");

        specialtyEmpty.classList.toggle("d-none", selectedSkills.length > 0);
    }

    function refreshSpecialtyOptions() {
        // An already-assigned specialty is removed from the picker, so a
        // duplicate cannot be chosen in the first place.
        Array.from(specialtySelect.options).forEach(function (option) {
            if (!option.value) {
                return;
            }

            option.hidden = selectedSkills.some(function (skill) {
                return String(skill.id) === option.value;
            });
        });

        specialtySelect.value = "";
    }

    function applyRoleVisibility() {
        const needsSpecialties =
            accountType === "employee" &&
            technicianRoles.indexOf(roleSelect.value) !== -1;

        specialtiesSection.classList.toggle("d-none", !needsSpecialties);
    }

    /**
     * Super Admin is not on offer in the picker, so an account that already
     * holds it gets a locked, read-only entry rather than an empty select
     * that would quietly demote them on save.
     */
    function lockRoleSelect(label) {
        const option = document.createElement("option");

        option.value = "super_admin";
        option.textContent = label;
        option.dataset.lockedRole = "1";

        roleSelect.appendChild(option);
        roleSelect.value = "super_admin";
        roleSelect.disabled = true;
    }

    function restoreRoleSelect() {
        const locked = roleSelect.querySelector("[data-locked-role]");

        if (locked) {
            locked.remove();
        }

        roleSelect.disabled = false;
    }

    function setAccountType(type) {
        accountType = type;

        toggleAll(employeeOnly, type === "employee");
        toggleAll(clientOnly, type === "client");

        fieldsWrap.classList.remove("d-none");
        userSubmit.disabled = false;

        applyRoleVisibility();
    }

    function loadGeneratedPassword() {
        return requestJson(routes.generatePassword).then(function (result) {
            if (result.ok && result.body.password) {
                passwordDisplay.value = result.body.password;
            }
        });
    }

    function resetForm() {
        userForm.reset();
        editing = null;
        accountType = null;
        selectedSkills = [];

        fieldsWrap.classList.add("d-none");
        typeStep.classList.remove("d-none");
        passwordBlock.classList.remove("d-none");
        accountHistory.classList.add("d-none");
        emailField.classList.remove("d-none");
        emailInput.disabled = false;
        emailLockedNote.classList.add("d-none");
        specialtiesSection.classList.add("d-none");
        restoreRoleSelect();

        passwordDisplay.value = "";
        userSubmit.disabled = true;
        userModalTitle.textContent = "Add New User";
        userSubmitLabel.textContent = "Create Account";

        typeInputs.forEach(function (input) {
            input.checked = false;
        });

        renderSpecialties();
        refreshSpecialtyOptions();
        setAlert(userFormError, "");
    }

    function openCreate() {
        resetForm();
        loadGeneratedPassword();
        bootstrapModal(userModalEl)?.show();
    }

    function openEdit(account) {
        resetForm();

        editing = account;
        typeStep.classList.add("d-none");
        // A generated password belongs to account creation only; an existing
        // account changes its password through the reset workflow.
        passwordBlock.classList.add("d-none");
        accountHistory.classList.remove("d-none");

        userModalTitle.textContent = account.is_client
            ? "Edit Registered User Account"
            : "Edit Employee Account";
        userSubmitLabel.textContent = "Save Changes";

        document.querySelector("[data-history-registered]").textContent =
            account.registered_at || "—";
        document.querySelector("[data-history-creator]").textContent =
            account.created_by || "—";
        document.querySelector("[data-history-login]").textContent =
            account.last_login_at || "Never";

        setAccountType(account.is_client ? "client" : "employee");

        userForm.querySelector('[name="contact_number"]').value =
            account.contact_number || "";
        // Empty for an account opened before birthdates were collected; the
        // server treats it as optional on an edit for exactly that reason.
        userForm.querySelector('[name="birthdate"]').value = account.birthdate || "";
        emailInput.value = account.email || "";

        if (account.is_client) {
            userForm.querySelector('[name="full_name"]').value = account.full_name || "";

            // The address is the client's login credential, so it moves only
            // through the Change Email workflow.
            emailInput.disabled = true;
            emailLockedNote.classList.remove("d-none");
        } else {
            userForm.querySelector('[name="first_name"]').value =
                account.first_name || "";
            userForm.querySelector('[name="middle_name"]').value =
                account.middle_name || "";
            userForm.querySelector('[name="last_name"]').value = account.last_name || "";

            if (account.role === "super_admin") {
                lockRoleSelect(account.role_label);
            } else {
                roleSelect.value = account.role || "";
            }

            selectedSkills = (account.skill_ids || []).map(function (id, index) {
                return { id: id, name: (account.skill_names || [])[index] || "Specialty" };
            });

            renderSpecialties();
            refreshSpecialtyOptions();
            applyRoleVisibility();
        }

        bootstrapModal(userModalEl)?.show();
    }

    typeInputs.forEach(function (input) {
        input.addEventListener("change", function () {
            setAccountType(input.value);
        });
    });

    roleSelect.addEventListener("change", applyRoleVisibility);

    specialtyAdd.addEventListener("click", function () {
        const id = Number(specialtySelect.value);

        if (!id) {
            return;
        }

        // Belt and braces: the option is hidden once chosen, but a duplicate
        // still cannot get into the list from here.
        if (
            selectedSkills.some(function (skill) {
                return skill.id === id;
            })
        ) {
            return;
        }

        selectedSkills.push({
            id: id,
            name: specialtySelect.options[specialtySelect.selectedIndex].text,
        });

        renderSpecialties();
        refreshSpecialtyOptions();
    });

    specialtyList.addEventListener("click", function (event) {
        const button = event.target.closest("[data-specialty-remove]");

        if (!button) {
            return;
        }

        const id = Number(button.dataset.specialtyRemove);

        selectedSkills = selectedSkills.filter(function (skill) {
            return skill.id !== id;
        });

        renderSpecialties();
        refreshSpecialtyOptions();
    });

    passwordCopy.addEventListener("click", function () {
        copyToClipboard(passwordDisplay, passwordCopy);
    });

    passwordRegenerate.addEventListener("click", function () {
        loadGeneratedPassword();
    });

    userForm.addEventListener("submit", function (event) {
        event.preventDefault();

        if (!accountType) {
            setAlert(userFormError, "Choose an account type first.");

            return;
        }

        setAlert(userFormError, "");
        setBusy(userSubmit, userSpinner, true);

        const payload = new FormData();

        payload.set("contact_number", userForm.querySelector('[name="contact_number"]').value);
        payload.set("birthdate", userForm.querySelector('[name="birthdate"]').value);

        if (accountType === "client") {
            payload.set("full_name", userForm.querySelector('[name="full_name"]').value);

            // An existing client's email address is fixed.
            if (!editing) {
                payload.set("email", emailInput.value);
            }
        } else {
            payload.set("first_name", userForm.querySelector('[name="first_name"]').value);
            payload.set("middle_name", userForm.querySelector('[name="middle_name"]').value);
            payload.set("last_name", userForm.querySelector('[name="last_name"]').value);
            payload.set("email", emailInput.value);

            // A locked Super Admin role is never sent; the server keeps it.
            if (!roleSelect.disabled) {
                payload.set("role", roleSelect.value);
            }

            selectedSkills.forEach(function (skill) {
                payload.append("skill_ids[]", String(skill.id));
            });
        }

        // The password is only ever set when the account is created.
        if (!editing && passwordDisplay.value.trim()) {
            payload.set("password", passwordDisplay.value.trim());
        }

        // No profile picture is sent: an account's owner sets their own from
        // their Profile page, and a client never has one.
        const url = editing
            ? routes.userBase +
              "/" +
              editing.id +
              (accountType === "client" ? "/client" : "/employee")
            : accountType === "client"
              ? routes.storeClient
              : routes.storeEmployee;

        sendJson(url, "POST", payload).then(function (result) {
            setBusy(userSubmit, userSpinner, false);

            if (!result.ok) {
                if (result.body.role_change) {
                    setRoleChangeAlert(userFormError, result.body.role_change);
                } else {
                    setAlert(
                        userFormError,
                        result.body.error || "Unable to save account.",
                    );
                }

                return;
            }

            bootstrapModal(userModalEl)?.hide();
            refreshTables();

            if (result.body.password) {
                showCredentials(
                    result.body.account,
                    result.body.password,
                    result.body.emailed,
                    false,
                );
            }
        });
    });

    userModalEl.addEventListener("hidden.bs.modal", resetForm);

    document.querySelector("[data-add-user-open]").addEventListener("click", openCreate);

    // ---------------------------------------------------------------
    // Credentials result
    // ---------------------------------------------------------------

    const credentialsModalEl = document.querySelector("[data-credentials-modal]");
    const credentialsCopy = document.querySelector("[data-credentials-copy]");
    const credentialsPassword = document.querySelector("[data-credentials-password]");

    function showCredentials(account, password, emailed, isReset) {
        document.querySelector("[data-credentials-title]").textContent = isReset
            ? "Password Reset"
            : "Account Created";

        document.querySelector("[data-credentials-intro]").textContent = isReset
            ? "A new temporary password has been issued for " + account.full_name + "."
            : account.full_name + " can sign in with these details.";

        document.querySelector("[data-credentials-code]").value = account.user_code;
        document.querySelector("[data-credentials-email]").value = account.email;
        credentialsPassword.value = password;

        document.querySelector("[data-credentials-note]").textContent = emailed
            ? "Emailed to the account. Shown only once."
            : "Email is not configured - hand this over directly. Shown only once.";

        bootstrapModal(credentialsModalEl)?.show();
    }

    credentialsCopy.addEventListener("click", function () {
        copyToClipboard(credentialsPassword, credentialsCopy);
    });

    // ---------------------------------------------------------------
    // Confirmations: activate, deactivate, archive, reset password
    // ---------------------------------------------------------------

    const confirmModalEl = document.querySelector("[data-confirm-modal]");
    const confirmTitle = document.querySelector("[data-confirm-title]");
    const confirmBody = document.querySelector("[data-confirm-body]");
    const confirmLabel = document.querySelector("[data-confirm-label]");
    const confirmSubmit = document.querySelector("[data-confirm-submit]");
    const confirmSpinner = document.querySelector("[data-confirm-spinner]");
    const confirmError = document.querySelector("[data-confirm-error]");

    let confirmHandler = null;

    function askConfirmation(settings) {
        confirmTitle.textContent = settings.title;
        confirmBody.textContent = settings.body;
        confirmLabel.textContent = settings.label;
        // Only the classes change; the spinner and label stay as they are.
        confirmSubmit.className = "btn " + (settings.variant || "btn-primary");
        confirmHandler = settings.onConfirm;

        setAlert(confirmError, "");
        bootstrapModal(confirmModalEl)?.show();
    }

    confirmSubmit.addEventListener("click", function () {
        if (!confirmHandler) {
            return;
        }

        setAlert(confirmError, "");
        setBusy(confirmSubmit, confirmSpinner, true);

        confirmHandler().then(function (result) {
            setBusy(confirmSubmit, confirmSpinner, false);

            if (!result.ok) {
                setAlert(
                    confirmError,
                    result.body.error || "Unable to complete that action.",
                );

                return;
            }

            bootstrapModal(confirmModalEl)?.hide();
            refreshTables();

            if (result.body.password && result.body.account) {
                showCredentials(
                    result.body.account,
                    result.body.password,
                    result.body.emailed,
                    true,
                );
            }
        });
    });

    // The confirm button is rebuilt with a new class each time, which would
    // otherwise strip the spinner it contains.
    confirmModalEl.addEventListener("hidden.bs.modal", function () {
        confirmHandler = null;
    });

    // ---------------------------------------------------------------
    // A Registered User's projects
    //
    // The reverse of the assignment edited on a project's own page: the same
    // link read from the account's end. Read-only, so there is no table
    // machinery here - one request, one render, and every value written in
    // with textContent so a project name can never become markup.
    // ---------------------------------------------------------------

    const userProjectsModalEl = document.querySelector("[data-user-projects-modal]");
    const userProjectsBody = document.querySelector("[data-user-projects-body]");
    const userProjectsSubtitle = document.querySelector(
        "[data-user-projects-subtitle]",
    );
    const userProjectsLoading = document.querySelector(
        "[data-user-projects-loading]",
    );
    const userProjectsEmpty = document.querySelector("[data-user-projects-empty]");
    const userProjectsError = document.querySelector("[data-user-projects-error]");

    function projectCell(text) {
        const cell = document.createElement("td");

        cell.textContent = text || "—";

        return cell;
    }

    function renderUserProjects(rows) {
        userProjectsBody.replaceChildren();

        rows.forEach(function (row) {
            const tr = document.createElement("tr");

            tr.appendChild(projectCell(row.code));
            tr.appendChild(projectCell(row.reference_no));

            const nameCell = document.createElement("td");
            const name = document.createElement("div");

            name.className = "fw-semibold";
            name.textContent = row.name || "—";
            nameCell.appendChild(name);

            if (row.address) {
                const address = document.createElement("div");

                address.className = "text-secondary small";
                address.textContent = row.address;
                nameCell.appendChild(address);
            }

            tr.appendChild(nameCell);

            const typesCell = document.createElement("td");

            if (row.types && row.types.length) {
                row.types.forEach(function (type) {
                    const chip = document.createElement("span");

                    chip.className = "project-type-chip";
                    chip.textContent = type;
                    typesCell.appendChild(chip);
                });
            } else {
                typesCell.textContent = "—";
            }

            tr.appendChild(typesCell);
            tr.appendChild(projectCell(row.dates));

            const statusCellEl = document.createElement("td");
            const badge = document.createElement("span");

            badge.className = "badge " + (row.status_badge_class || "bg-secondary");
            badge.textContent = row.status_label || "—";
            statusCellEl.appendChild(badge);
            tr.appendChild(statusCellEl);

            const actionCell = document.createElement("td");

            actionCell.className = "text-center";

            const link = document.createElement("a");

            link.className = "btn btn-sm btn-outline-primary py-1 px-2";
            link.href = row.url;
            link.title = "Open project";
            link.setAttribute("aria-label", "Open project");
            link.innerHTML = '<i class="bi bi-box-arrow-up-right"></i>';
            actionCell.appendChild(link);
            tr.appendChild(actionCell);

            userProjectsBody.appendChild(tr);
        });
    }

    function openUserProjects(account) {
        if (!userProjectsModalEl) {
            return;
        }

        userProjectsBody.replaceChildren();
        setAlert(userProjectsError, "");
        userProjectsEmpty.classList.add("d-none");
        userProjectsLoading.classList.remove("d-none");
        userProjectsSubtitle.textContent =
            account.full_name + " · " + account.email;

        bootstrapModal(userProjectsModalEl)?.show();

        requestJson(routes.userBase + "/" + account.id + "/projects").then(
            function (result) {
                userProjectsLoading.classList.add("d-none");

                if (!result.ok) {
                    setAlert(
                        userProjectsError,
                        result.body.error || "Unable to load projects.",
                    );

                    return;
                }

                const rows = result.body.rows || [];

                if (!rows.length) {
                    userProjectsEmpty.classList.remove("d-none");

                    return;
                }

                renderUserProjects(rows);
            },
        );
    }

    // ---------------------------------------------------------------
    // Row actions
    // ---------------------------------------------------------------

    function fetchAccount(id) {
        return requestJson(routes.userBase + "/" + id).then(function (result) {
            if (!result.ok) {
                setAlert(pageError, result.body.error || "Unable to load account.");

                return null;
            }

            return result.body.account;
        });
    }

    function setStatus(account, active) {
        return sendJson(routes.userBase + "/" + account.id + "/status", "PUT", {
            status: active ? "active" : "deactivated",
        });
    }

    function handleAction(action, id) {
        fetchAccount(id).then(function (account) {
            if (!account) {
                return;
            }

            if (action === "edit") {
                openEdit(account);

                return;
            }

            if (action === "view-projects") {
                openUserProjects(account);

                return;
            }

            if (action === "reset-password") {
                askConfirmation({
                    title: "Reset Password",
                    body:
                        "Issue a new temporary password for " +
                        account.full_name +
                        "? Their current password stops working immediately, and they will have to choose a new one at next sign-in.",
                    label: "Reset Password",
                    variant: "btn-warning",
                    onConfirm: function () {
                        return sendJson(
                            routes.userBase + "/" + account.id + "/password",
                            "PUT",
                        ).then(function (result) {
                            if (result.ok) {
                                result.body.account = account;
                            }

                            return result;
                        });
                    },
                });

                return;
            }

            if (action === "activate" || action === "deactivate") {
                const activating = action === "activate";

                askConfirmation({
                    title: activating ? "Activate Account" : "Deactivate Account",
                    body: activating
                        ? "Allow " + account.full_name + " to sign in again?"
                        : "Deactivate " +
                          account.full_name +
                          "? They can no longer sign in. Nothing is deleted.",
                    label: activating ? "Activate" : "Deactivate",
                    variant: activating ? "btn-success" : "btn-warning",
                    onConfirm: function () {
                        return setStatus(account, activating);
                    },
                });

                return;
            }

            if (action === "archive") {
                askConfirmation({
                    title: "Archive Account",
                    body:
                        "Archive " +
                        account.full_name +
                        "? They can no longer sign in. Nothing is deleted, and the account can be restored.",
                    label: "Archive",
                    variant: "btn-danger",
                    onConfirm: function () {
                        return sendJson(routes.userBase + "/" + account.id, "DELETE");
                    },
                });
            }
        });
    }

    [employeeTable.element, clientTable.element].forEach(function (body) {
        body.addEventListener("click", function (event) {
            const button = event.target.closest("[data-action]");

            if (button) {
                handleAction(button.dataset.action, button.dataset.userId);
            }
        });
    });

    function refreshTables() {
        employeeTable.reload();
        clientTable.reload();
    }

    // ---------------------------------------------------------------
    // Initial load
    // ---------------------------------------------------------------

    employeeTable.load();
    clientTable.load();
});
