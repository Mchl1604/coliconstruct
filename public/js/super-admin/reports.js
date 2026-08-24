document.addEventListener("DOMContentLoaded", function () {
    const routes = window.reportRoutes || {};

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

    /**
     * Who filed a report: their own picture beside their name. Reports are
     * always filed by internal accounts, so there is a picture - their own or
     * the default avatar - unless the account has since been removed.
     */
    function submitterCell(report) {
        const avatar = report.submitted_by_avatar
            ? '<img class="user-avatar user-avatar-xs" src="' +
              escapeHtml(report.submitted_by_avatar) +
              '" alt="" loading="lazy">'
            : "";

        return (
            '<div class="d-flex align-items-center gap-2">' +
            avatar +
            "<span>" +
            escapeHtml(report.submitted_by) +
            "</span></div>"
        );
    }

    /**
     * The same toast the layout raises for a flashed message, appended from
     * here for an action that happened without a page load. Same container,
     * same markup, so an archived report reads exactly like a saved project.
     */
    function toast(message, variant) {
        const container = document.querySelector("[data-toast-container]");

        if (!container || !message || !window.bootstrap) {
            return;
        }

        const name = variant || "success";
        const isDark = name === "warning" || name === "info";
        const element = document.createElement("div");

        element.className =
            "toast align-items-center border-0 bg-" +
            name +
            (isDark ? " text-dark" : " text-white");
        element.setAttribute("role", "alert");
        element.setAttribute("aria-live", "assertive");
        element.setAttribute("aria-atomic", "true");
        // Inner flex row, matching the flash-toasts component: `d-flex` on the
        // toast itself would beat the rule that keeps it hidden until shown.
        element.innerHTML =
            '<div class="d-flex">' +
            '<div class="toast-body">' +
            escapeHtml(message) +
            "</div>" +
            '<button type="button" class="btn-close ' +
            (isDark ? "" : "btn-close-white ") +
            'me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
            "</div>";

        container.appendChild(element);

        element.addEventListener("hidden.bs.toast", function () {
            element.remove();
        });

        window.bootstrap.Toast.getOrCreateInstance(element, {
            autohide: true,
            delay: 4000,
        }).show();
    }

    function setAlert(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message || "";
        element.classList.toggle("d-none", !message);
    }

    function requestJson(url, options) {
        const config = Object.assign(
            {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            },
            options || {},
        );

        return fetch(url, config).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (body) {
                    return { ok: response.ok, body: body };
                });
        });
    }

    const pesos = new Intl.NumberFormat(undefined, {
        style: "currency",
        currency: "PHP",
        maximumFractionDigits: 0,
    });

    const numbers = new Intl.NumberFormat();

    // ---------------------------------------------------------------
    // Tab 1 - technician reports
    // ---------------------------------------------------------------

    const filters = {
        project: document.querySelector("[data-filter-project]"),
        type: document.querySelector("[data-filter-type]"),
        date: document.querySelector("[data-filter-date]"),
        search: document.querySelector("[data-filter-search]"),
        start: document.querySelector("[data-filter-start]"),
        end: document.querySelector("[data-filter-end]"),
        reset: document.querySelector("[data-filter-reset]"),
    };

    const customRangeCells = document.querySelectorAll("[data-custom-range]");
    const reportsBody = document.querySelector("[data-reports-body]");
    const reportsLoading = document.querySelector("[data-reports-loading]");
    const reportsEmpty = document.querySelector("[data-reports-empty]");
    const reportsCount = document.querySelector("[data-reports-count]");
    const reportsPagination = document.querySelector(
        "[data-reports-pagination]",
    );

    let reportsRequestToken = 0;
    let searchTimer = null;
    // Which page of results is on screen. Paging happens in SQL, so this is
    // the whole of what the browser remembers about it.
    let reportsPage = 1;

    if (window.flatpickr) {
        [filters.start, filters.end].forEach(function (input) {
            if (input) {
                window.flatpickr(input, {
                    dateFormat: "Y-m-d",
                    allowInput: false,
                    onChange: function () {
                        reloadReportsFromFirstPage();
                    },
                });
            }
        });
    }

    function currentReportFilters() {
        const params = new URLSearchParams();

        params.set("page", String(reportsPage));

        if (filters.project && filters.project.value) {
            params.set("project_id", filters.project.value);
        }

        if (filters.type && filters.type.value) {
            params.set("report_type", filters.type.value);
        }

        if (filters.date && filters.date.value) {
            params.set("date_filter", filters.date.value);
        }

        if (filters.date && filters.date.value === "custom") {
            if (filters.start && filters.start.value) {
                params.set("start_date", filters.start.value);
            }

            if (filters.end && filters.end.value) {
                params.set("end_date", filters.end.value);
            }
        }

        if (filters.search && filters.search.value.trim()) {
            params.set("search", filters.search.value.trim());
        }

        return params;
    }

    function renderReports(reports) {
        reportsBody.innerHTML = reports
            .map(function (report) {
                return (
                    "<tr>" +
                    '<td class="report-id-cell">' +
                    escapeHtml(report.display_code) +
                    "</td>" +
                    "<td>" +
                    escapeHtml(report.reference_no) +
                    "</td>" +
                    '<td class="fw-semibold">' +
                    escapeHtml(report.client) +
                    "<div class='small text-muted fw-normal'>" +
                    escapeHtml(report.report_title) +
                    "</div></td>" +
                    "<td><span class='badge " +
                    report.type_badge_class +
                    "'>" +
                    escapeHtml(report.type_label) +
                    "</span></td>" +
                    "<td>" +
                    submitterCell(report) +
                    "</td>" +
                    "<td>" +
                    escapeHtml(report.report_date_label) +
                    "</td>" +
                    '<td class="text-center">' +
                    '<div class="d-inline-flex gap-1">' +
                    '<button type="button" class="btn btn-sm btn-primary py-1 px-2" ' +
                    'data-view-report="' +
                    report.id +
                    '" title="View report"><i class="bi bi-eye"></i></button>' +
                    // Drawn only where the server said this account may
                    // archive this report. The endpoint asks the same policy
                    // again, so a row that answers false here is refused there
                    // too - see TechnicianReportArchiveController.
                    (report.can_archive
                        ? '<button type="button" class="btn btn-sm btn-warning py-1 px-2" ' +
                          'data-archive-report="' +
                          report.id +
                          '" data-report-label="' +
                          escapeHtml(report.display_code) +
                          " - " +
                          escapeHtml(report.report_title) +
                          '" title="Archive report"><i class="bi bi-archive"></i></button>'
                        : "") +
                    "</div>" +
                    "</td>" +
                    "</tr>"
                );
            })
            .join("");

        reportsEmpty.classList.toggle("d-none", reports.length > 0);
    }

    /**
     * Previous / Page X of Y / Next, plus the count of what is on screen.
     *
     * Hidden outright when there is nothing to page through: one page of
     * results needs no controls, and neither does no results at all.
     */
    function renderReportsPagination(meta) {
        if (!reportsPagination) {
            return;
        }

        if (!meta || !meta.total) {
            reportsPagination.classList.add("d-none");
            reportsPagination.innerHTML = "";

            return;
        }

        reportsPagination.classList.remove("d-none");
        reportsPagination.innerHTML =
            '<span class="table-pagination-summary">Showing ' +
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

    function loadReports() {
        const token = ++reportsRequestToken;

        reportsLoading.classList.remove("d-none");
        reportsEmpty.classList.add("d-none");

        requestJson(
            routes.technicianReports + "?" + currentReportFilters().toString(),
        ).then(function (result) {
            if (token !== reportsRequestToken) {
                return;
            }

            reportsLoading.classList.add("d-none");

            if (!result.ok) {
                reportsBody.innerHTML = "";
                reportsEmpty.textContent =
                    result.body.error || "Unable to load reports.";
                reportsEmpty.classList.remove("d-none");
                renderReportsPagination(null);

                return;
            }

            const meta = result.body.meta || {};

            renderReports(result.body.reports || []);

            // The heading counts everything the filters match, not just the
            // page on screen - which is the figure somebody is really after.
            reportsCount.textContent =
                (meta.total || 0) + (meta.total === 1 ? " report" : " reports");

            renderReportsPagination(meta);

            // Deleting the last row of the last page, or narrowing the
            // filters, can leave the request pointing past the end. The
            // server answers with an empty page; step back onto a real one.
            if (
                meta.total > 0 &&
                meta.current_page > meta.last_page &&
                reportsPage !== meta.last_page
            ) {
                reportsPage = meta.last_page;
                loadReports();
            }
        });
    }

    /**
     * Any change to what is being looked for starts again at page one: the
     * page you were on has no meaning once the result set underneath it
     * changes.
     */
    function reloadReportsFromFirstPage() {
        reportsPage = 1;
        loadReports();
    }

    [filters.project, filters.type].forEach(function (control) {
        if (control) {
            control.addEventListener("change", reloadReportsFromFirstPage);
        }
    });

    if (reportsPagination) {
        reportsPagination.addEventListener("click", function (event) {
            const button = event.target.closest("[data-page]");

            if (!button || button.disabled) {
                return;
            }

            reportsPage = Number(button.dataset.page);
            loadReports();
        });
    }

    if (filters.date) {
        filters.date.addEventListener("change", function () {
            const isCustom = filters.date.value === "custom";

            customRangeCells.forEach(function (cell) {
                cell.classList.toggle("d-none", !isCustom);
            });

            // Wait for both ends before querying a custom range.
            if (
                !isCustom ||
                (filters.start.value && filters.end.value)
            ) {
                reloadReportsFromFirstPage();
            }
        });
    }

    if (filters.search) {
        // Debounced so typing doesn't fire a request per keystroke.
        filters.search.addEventListener("input", function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(reloadReportsFromFirstPage, 300);
        });
    }

    if (filters.reset) {
        filters.reset.addEventListener("click", function () {
            filters.project.value = "";
            filters.type.value = "all";
            filters.date.value = "all";
            filters.search.value = "";

            [filters.start, filters.end].forEach(function (input) {
                if (input && input._flatpickr) {
                    input._flatpickr.clear(false);
                } else if (input) {
                    input.value = "";
                }
            });

            customRangeCells.forEach(function (cell) {
                cell.classList.add("d-none");
            });

            reloadReportsFromFirstPage();
        });
    }

    // ---------------------------------------------------------------
    // Report viewer
    // ---------------------------------------------------------------

    const viewModalEl = document.querySelector("[data-view-report-modal]");
    const imageModalEl = document.querySelector("[data-image-modal]");

    function showImage(url) {
        if (!imageModalEl || !window.bootstrap) {
            return;
        }

        imageModalEl.querySelector("[data-image-target]").src = url;
        window.bootstrap.Modal.getOrCreateInstance(imageModalEl).show();
    }

    function initViewer(modal) {
        const titleEl = modal.querySelector("[data-view-title]");
        const eyebrowEl = modal.querySelector("[data-view-type-eyebrow]");
        const linkEl = modal.querySelector("[data-view-project-link]");
        const linkRefEl = modal.querySelector("[data-view-project-ref]");
        const clientEl = modal.querySelector("[data-view-client]");
        const contentEl = modal.querySelector(".modal-content");
        const typeEl = modal.querySelector("[data-view-type]");
        const submittedByEl = modal.querySelector("[data-view-submitted-by]");
        const dateEl = modal.querySelector("[data-view-date]");
        const descriptionEl = modal.querySelector("[data-view-description]");
        const galleryEl = modal.querySelector("[data-view-gallery]");
        const noImagesEl = modal.querySelector("[data-view-no-images]");
        const imageCountEl = modal.querySelector("[data-view-image-count]");
        const errorEl = modal.querySelector("[data-view-error]");

        galleryEl.addEventListener("click", function (event) {
            const button = event.target.closest("[data-gallery-image]");

            if (button) {
                showImage(button.dataset.galleryImage);
            }
        });

        function render(report) {
            titleEl.textContent = report.report_title;
            eyebrowEl.textContent = report.type_label;

            // The viewer is tinted by report type, so which kind of report
            // this is registers before a word of it is read.
            contentEl.classList.remove(
                "report-accent-progress",
                "report-accent-incident",
            );
            contentEl.classList.add(
                report.type_accent_class || "report-accent-progress",
            );

            clientEl.textContent = report.client || "—";
            typeEl.innerHTML =
                '<span class="badge ' +
                report.type_badge_class +
                '">' +
                escapeHtml(report.type_label) +
                "</span>";
            submittedByEl.textContent = report.submitted_by;
            dateEl.textContent = report.report_date_label;
            descriptionEl.textContent = report.description || "—";

            if (report.project_url) {
                linkEl.href = report.project_url;
                linkRefEl.textContent = report.reference_no;
                linkEl.classList.remove("d-none");
            } else {
                linkEl.classList.add("d-none");
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

            noImagesEl.classList.toggle("d-none", images.length > 0);
            imageCountEl.textContent =
                images.length + (images.length === 1 ? " image" : " images");
            imageCountEl.classList.toggle("d-none", images.length === 0);
        }

        return {
            open: function (id) {
                setAlert(errorEl, "");
                titleEl.textContent = "Loading…";
                descriptionEl.textContent = "";
                galleryEl.innerHTML = "";
                noImagesEl.classList.add("d-none");
                imageCountEl.classList.add("d-none");
                linkEl.classList.add("d-none");

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }

                requestJson(routes.reportBase + "/" + id).then(function (result) {
                    if (!result.ok) {
                        titleEl.textContent = "Unavailable";
                        setAlert(
                            errorEl,
                            result.body.error || "Unable to load report.",
                        );

                        return;
                    }

                    render(result.body);
                });
            },
        };
    }

    const viewer = viewModalEl ? initViewer(viewModalEl) : null;

    // ---------------------------------------------------------------
    // Archiving a report
    //
    // The confirmation dialog the rest of the portal uses, then one request.
    // The row is not hidden locally: the table is reloaded, so what is on
    // screen is what the server would answer for these filters - which is the
    // same list Project Details will now show.
    // ---------------------------------------------------------------

    const archiveModalEl = document.querySelector("[data-archive-report-modal]");

    const archiving = archiveModalEl
        ? (function (modal) {
              const labelEl = modal.querySelector("[data-archive-report-label]");
              const errorEl = modal.querySelector("[data-archive-report-error]");
              const confirmEl = modal.querySelector("[data-archive-report-confirm]");
              const spinnerEl = modal.querySelector("[data-archive-report-spinner]");

              let pendingId = null;

              function setBusy(isBusy) {
                  confirmEl.disabled = isBusy;
                  spinnerEl.classList.toggle("d-none", !isBusy);
              }

              confirmEl.addEventListener("click", function () {
                  if (pendingId === null) {
                      return;
                  }

                  setAlert(errorEl, "");
                  setBusy(true);

                  requestJson(
                      routes.archiveBase + "/" + pendingId + "/archive",
                      {
                          method: "POST",
                          headers: { "X-CSRF-TOKEN": csrfToken() },
                      },
                  ).then(function (result) {
                      setBusy(false);

                      if (!result.ok) {
                          setAlert(
                              errorEl,
                              result.body.error || "Unable to archive report.",
                          );

                          return;
                      }

                      if (window.bootstrap) {
                          window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                      }

                      toast(result.body.message || "Report archived successfully.");
                      pendingId = null;
                      loadReports();
                  });
              });

              return {
                  open: function (id, label) {
                      pendingId = id;
                      labelEl.textContent = label || "this report";
                      setAlert(errorEl, "");
                      setBusy(false);

                      if (window.bootstrap) {
                          window.bootstrap.Modal.getOrCreateInstance(modal).show();
                      }
                  },
              };
          })(archiveModalEl)
        : null;

    if (reportsBody) {
        reportsBody.addEventListener("click", function (event) {
            const viewButton = event.target.closest("[data-view-report]");

            if (viewButton && viewer) {
                viewer.open(viewButton.dataset.viewReport);

                return;
            }

            const archiveButton = event.target.closest("[data-archive-report]");

            if (archiveButton && archiving) {
                archiving.open(
                    archiveButton.dataset.archiveReport,
                    archiveButton.dataset.reportLabel,
                );
            }
        });
    }

    // ---------------------------------------------------------------
    // Create report - project first, then the existing form
    // ---------------------------------------------------------------

    const createModalEl = document.querySelector("[data-create-report-modal]");

    if (createModalEl) {
        const form = createModalEl.querySelector("[data-create-report-form]");
        const projectSelect = createModalEl.querySelector("[data-create-project]");
        const fieldsWrap = createModalEl.querySelector("[data-create-fields]");
        const imagesInput = createModalEl.querySelector("[data-create-images]");
        const previewEl = createModalEl.querySelector("[data-create-preview]");
        const submitBtn = createModalEl.querySelector("[data-create-submit]");
        const errorEl = createModalEl.querySelector("[data-create-error]");

        projectSelect.addEventListener("change", function () {
            const projectId = projectSelect.value;

            setAlert(errorEl, "");

            if (!projectId) {
                fieldsWrap.classList.add("d-none");
                submitBtn.disabled = true;
                form.action = "";

                return;
            }

            // Same endpoint the Project Details form posts to. It attributes
            // the report on its own, so the form no longer asks who filed it.
            form.action =
                routes.projectBase + "/" + projectId + "/reports";

            fieldsWrap.classList.remove("d-none");
            submitBtn.disabled = false;
        });

        if (imagesInput) {
            imagesInput.addEventListener("change", function () {
                previewEl.innerHTML = "";

                Array.from(imagesInput.files || []).forEach(function (file) {
                    if (!file.type.startsWith("image/")) {
                        return;
                    }

                    const column = document.createElement("div");
                    column.className = "col-4 col-md-3";

                    const image = document.createElement("img");
                    image.className = "img-fluid rounded border";
                    image.style.height = "90px";
                    image.style.width = "100%";
                    image.style.objectFit = "cover";
                    image.src = URL.createObjectURL(file);
                    image.onload = function () {
                        URL.revokeObjectURL(image.src);
                    };

                    column.appendChild(image);
                    previewEl.appendChild(column);
                });
            });
        }

        form.addEventListener("submit", function (event) {
            if (!projectSelect.value) {
                event.preventDefault();
                setAlert(errorEl, "Select a project first.");
            }
        });

        createModalEl.addEventListener("hidden.bs.modal", function () {
            form.reset();
            form.action = "";
            fieldsWrap.classList.add("d-none");
            previewEl.innerHTML = "";
            submitBtn.disabled = true;
            setAlert(errorEl, "");
        });
    }

    // ---------------------------------------------------------------
    // Tab 2 - system reports
    // ---------------------------------------------------------------

    const systemLoading = document.querySelector("[data-system-loading]");
    const systemError = document.querySelector("[data-system-error]");
    const chartCanvases = document.querySelectorAll("[data-chart]");

    const chartInstances = {};
    let systemRequestToken = 0;
    let systemLoaded = false;

    const palette = [
        "#2563eb",
        "#198754",
        "#fd7e14",
        "#dc3545",
        "#6f42c1",
        "#0dcaf0",
        "#f0ad4e",
        "#20c997",
        "#d63384",
        "#6c757d",
    ];

    /**
     * Every chart arrives either as one series of values or as several named
     * ones. Normalising here is what lets a grouped bar chart and a plain one
     * share the whole of the configuration below.
     */
    function seriesOf(data) {
        if (!data) {
            return [];
        }

        if (Array.isArray(data.datasets) && data.datasets.length) {
            return data.datasets;
        }

        return [{ label: data.label, values: data.values || [], colors: data.colors }];
    }

    function isEmptySeries(data) {
        const series = seriesOf(data);

        if (!series.length) {
            return true;
        }

        return series.every(function (entry) {
            return (entry.values || []).every(function (value) {
                return !value;
            });
        });
    }

    function buildConfig(canvas, data) {
        const type = canvas.dataset.chartType;
        const isMoney = canvas.dataset.chartMoney === "1";
        // Categorical charts colour each bar separately; a time series keeps
        // one colour so the eye reads it as a single trend.
        const categorical = canvas.dataset.chartCategorical === "1";
        const title = canvas.dataset.chartTitle || "";
        const labels = data.labels || [];
        const series = seriesOf(data);
        const values = series[0] ? series[0].values || [] : [];
        // One series is a single trend and reads better without a key; two or
        // more have to be told apart. A chart with a written-out key beside it
        // needs neither.
        const showLegend =
            canvas.dataset.chartLegend !== "list" &&
            (type === "doughnut" || type === "pie" || series.length > 1);

        const valueTick = function (value) {
            return isMoney ? pesos.format(value) : numbers.format(value);
        };

        const tooltipLabel = function (context) {
            const raw = context.parsed.y !== undefined && context.parsed.y !== null
                ? context.parsed.y
                : context.parsed.x !== undefined && context.parsed.x !== null
                  ? context.parsed.x
                  : context.parsed;

            return (
                " " +
                (context.dataset.label || context.label) +
                ": " +
                valueTick(raw)
            );
        };

        if (type === "doughnut" || type === "pie") {
            return {
                type: type,
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: title,
                            data: values,
                            backgroundColor: data.colors || palette,
                            borderWidth: 3,
                            borderColor: "#fff",
                            hoverOffset: 8,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: type === "doughnut" ? "62%" : 0,
                    plugins: {
                        title: { display: false },
                        legend: {
                            display: showLegend,
                            position: "bottom",
                            labels: {
                                usePointStyle: true,
                                pointStyle: "circle",
                                boxWidth: 8,
                                padding: 16,
                            },
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const total = context.dataset.data.reduce(
                                        function (sum, value) {
                                            return sum + (value || 0);
                                        },
                                        0,
                                    );
                                    const share = total
                                        ? Math.round((context.parsed / total) * 100)
                                        : 0;

                                    return (
                                        " " +
                                        context.label +
                                        ": " +
                                        valueTick(context.parsed) +
                                        " (" +
                                        share +
                                        "%)"
                                    );
                                },
                            },
                        },
                    },
                },
            };
        }

        const horizontal = type === "horizontalBar";

        /**
         * A series' own colour where the server named one, a colour per bar for
         * a categorical single series, otherwise its place in the palette.
         */
        function colorFor(entry, index) {
            if (type === "line") {
                return "rgba(37, 99, 235, 0.15)";
            }

            if (entry.color) {
                return entry.color;
            }

            if (entry.colors) {
                return entry.colors;
            }

            if (series.length > 1) {
                return palette[index % palette.length];
            }

            return categorical
                ? labels.map(function (label, barIndex) {
                      return palette[barIndex % palette.length];
                  })
                : "#2563eb";
        }

        return {
            type: horizontal ? "bar" : type,
            data: {
                labels: labels,
                datasets: series.map(function (entry, index) {
                    return {
                        label: entry.label || data.label || title,
                        data: entry.values || [],
                        backgroundColor: colorFor(entry, index),
                        borderColor: type === "line" ? "#2563eb" : "transparent",
                        borderWidth: type === "line" ? 2 : 0,
                        fill: type === "line",
                        tension: 0.3,
                        pointRadius: 3,
                        borderRadius: type === "line" ? 0 : 6,
                        maxBarThickness: horizontal ? 26 : 42,
                    };
                }),
            },
            options: {
                indexAxis: horizontal ? "y" : "x",
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: false },
                    legend: {
                        display: showLegend,
                        position: "bottom",
                        labels: {
                            usePointStyle: true,
                            pointStyle: "circle",
                            boxWidth: 8,
                            padding: 16,
                        },
                    },
                    tooltip: { callbacks: { label: tooltipLabel } },
                },
                scales: {
                    x: {
                        ticks: horizontal
                            ? { callback: valueTick }
                            : { autoSkip: true, maxRotation: 0 },
                        grid: { display: horizontal, color: "rgba(148, 163, 184, 0.18)" },
                        border: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: horizontal ? {} : { callback: valueTick },
                        grid: { display: !horizontal, color: "rgba(148, 163, 184, 0.18)" },
                        border: { display: false },
                    },
                },
            },
        };
    }

    /**
     * The headline figure under a graph - its total, or its average. Written
     * by the service alongside the series it describes, so the two can never
     * be computed from different rules.
     */
    function setChartSummary(key, data) {
        const summaryEl = document.querySelector(
            '[data-chart-summary="' + key + '"]',
        );

        if (!summaryEl) {
            return;
        }

        const summary = data && data.summary ? data.summary : "";

        summaryEl.textContent = summary;
        summaryEl.classList.toggle("d-none", !summary);
    }

    /**
     * The key beside a full-width pie, as real markup rather than Chart.js's
     * own legend: it can carry the count and the share for each slice, which
     * is most of what somebody opens this chart to read.
     */
    function renderChartLegend(key, data) {
        const listEl = document.querySelector(
            '[data-chart-legend-list="' + key + '"]',
        );

        if (!listEl) {
            return;
        }

        const labels = (data && data.labels) || [];
        const values = (data && data.values) || [];
        const colors = (data && data.colors) || palette;
        const total = values.reduce(function (sum, value) {
            return sum + (value || 0);
        }, 0);

        listEl.innerHTML = labels
            .map(function (label, index) {
                const value = values[index] || 0;
                const share = total ? Math.round((value / total) * 100) : 0;

                return (
                    "<li>" +
                    '<span class="report-legend-dot" style="background:' +
                    escapeHtml(colors[index % colors.length]) +
                    '"></span>' +
                    '<span class="report-legend-label">' +
                    escapeHtml(label) +
                    "</span>" +
                    '<span class="report-legend-value">' +
                    numbers.format(value) +
                    '<small>' +
                    share +
                    "%</small></span>" +
                    "</li>"
                );
            })
            .join("");
    }

    function renderChart(key, data) {
        const canvas = document.querySelector('[data-chart="' + key + '"]');

        if (!canvas) {
            return;
        }

        const emptyEl = document.querySelector('[data-chart-empty="' + key + '"]');

        if (chartInstances[key]) {
            chartInstances[key].destroy();
            delete chartInstances[key];
        }

        const empty = isEmptySeries(data);

        setChartSummary(key, empty ? null : data);
        renderChartLegend(key, empty ? null : data);

        if (emptyEl) {
            emptyEl.classList.toggle("d-none", !empty);
        }

        // The wrap reserves the chart's height, so hiding the canvas alone
        // would leave the empty state sitting under a blank panel.
        const wrap = canvas.closest(".report-chart-wrap") || canvas;
        const split = canvas.closest(".report-chart-split");

        wrap.classList.toggle("d-none", empty);

        if (split) {
            split.classList.toggle("d-none", empty);
        }

        if (empty || !window.Chart) {
            return;
        }

        chartInstances[key] = new window.Chart(canvas, buildConfig(canvas, data));
    }

    function renderCharts(charts) {
        chartCanvases.forEach(function (canvas) {
            renderChart(canvas.dataset.chart, (charts || {})[canvas.dataset.chart]);
        });
    }

    function setChartLoading(key, loading) {
        const spinner = document.querySelector('[data-chart-loading="' + key + '"]');

        if (spinner) {
            spinner.classList.toggle("d-none", !loading);
        }
    }

    /**
     * The state of every chart's own toggles, sent with the page-level request
     * and with each single-chart refresh so a reload never loses a selection.
     */
    const chartGranularities = {};
    let quotationStatus = "all";

    function chartParams(key) {
        const params = new URLSearchParams();

        params.set("chart", key);

        if (chartGranularities[key]) {
            params.set("granularity", chartGranularities[key]);
        }

        params.set("quotation_status", quotationStatus);

        return params;
    }

    // Each chart tracks its own in-flight request, so a slow one can never
    // overwrite a newer render of a different chart.
    const chartTokens = {};

    function loadChart(key) {
        const token = (chartTokens[key] = (chartTokens[key] || 0) + 1);

        setChartLoading(key, true);
        setAlert(systemError, "");

        requestJson(routes.systemChart + "?" + chartParams(key).toString()).then(
            function (result) {
                if (token !== chartTokens[key]) {
                    return;
                }

                setChartLoading(key, false);

                if (!result.ok) {
                    setAlert(
                        systemError,
                        result.body.error || "Unable to load chart.",
                    );

                    return;
                }

                renderChart(key, result.body.data);
            },
        );
    }

    function loadSystemReports() {
        const token = ++systemRequestToken;

        systemLoading.classList.remove("d-none");
        setAlert(systemError, "");

        const params = new URLSearchParams();
        params.set("quotation_status", quotationStatus);

        Object.keys(chartGranularities).forEach(function (key) {
            params.set("granularities[" + key + "]", chartGranularities[key]);
        });

        requestJson(routes.systemReports + "?" + params.toString()).then(
            function (result) {
                if (token !== systemRequestToken) {
                    return;
                }

                systemLoading.classList.add("d-none");

                if (!result.ok) {
                    setAlert(
                        systemError,
                        result.body.error || "Unable to load dashboard.",
                    );

                    return;
                }

                renderCharts(result.body.charts);
                systemLoaded = true;
            },
        );
    }

    // Per-chart Monthly/Yearly toggles.
    document
        .querySelectorAll("[data-chart-granularity]")
        .forEach(function (button) {
            const key = button.dataset.chartTarget;

            if (button.classList.contains("active")) {
                chartGranularities[key] = button.dataset.chartGranularity;
            }

            button.addEventListener("click", function () {
                if (chartGranularities[key] === button.dataset.chartGranularity) {
                    return;
                }

                document
                    .querySelectorAll(
                        '[data-chart-granularity][data-chart-target="' + key + '"]',
                    )
                    .forEach(function (sibling) {
                        sibling.classList.remove("active");
                    });

                button.classList.add("active");
                chartGranularities[key] = button.dataset.chartGranularity;
                loadChart(key);
            });
        });

    // The Total Quotation status filter.
    document.querySelectorAll("[data-chart-status]").forEach(function (select) {
        const key = select.dataset.chartStatus;

        quotationStatus = select.value;

        select.addEventListener("change", function () {
            quotationStatus = select.value;
            loadChart(key);
        });
    });

    // Charts can only be measured once their tab is on screen.
    const systemTabBtn = document.getElementById("systemReportsTab");

    if (systemTabBtn) {
        systemTabBtn.addEventListener("shown.bs.tab", function () {
            if (!systemLoaded) {
                loadSystemReports();

                return;
            }

            Object.keys(chartInstances).forEach(function (key) {
                chartInstances[key].resize();
            });
        });
    }

    // ---------------------------------------------------------------
    // Export
    // ---------------------------------------------------------------

    const exportModalEl = document.querySelector("[data-export-modal]");

    if (exportModalEl) {
        const typeSelect = exportModalEl.querySelector("[data-export-type]");
        const periodSelect = exportModalEl.querySelector("[data-export-period]");
        const monthWrap = exportModalEl.querySelector("[data-export-month-wrap]");
        const monthSelect = exportModalEl.querySelector("[data-export-month]");
        const yearSelect = exportModalEl.querySelector("[data-export-year]");
        const statusSelect = exportModalEl.querySelector("[data-export-status]");
        const scopeSelect = exportModalEl.querySelector(
            "[data-export-technician-scope]",
        );
        const technicianWrap = exportModalEl.querySelector(
            "[data-export-technician-wrap]",
        );
        const technicianSelect = exportModalEl.querySelector(
            "[data-export-technician]",
        );
        const kindSelect = exportModalEl.querySelector("[data-export-kind]");
        const formatSelect = exportModalEl.querySelector("[data-export-format]");
        const submitBtn = exportModalEl.querySelector("[data-export-submit]");
        const spinner = exportModalEl.querySelector("[data-export-spinner]");
        const errorEl = exportModalEl.querySelector("[data-export-error]");

        /**
         * Show the filters the chosen report uses and reset the rest.
         *
         * Resetting matters as much as hiding: picking Completed on a Project
         * Report and then switching to a Schedule Report must not leave that
         * choice behind, where it would be sent with a request that has no
         * business carrying it.
         */
        function applyReportType() {
            const type = typeSelect.value;

            exportModalEl
                .querySelectorAll("[data-export-field]")
                .forEach(function (field) {
                    const applies = field.dataset.exportField === type;

                    field.classList.toggle("d-none", !applies);

                    if (!applies) {
                        field.querySelectorAll("select").forEach(function (select) {
                            select.selectedIndex = 0;
                        });
                    }
                });

            applyTechnicianScope();
            setAlert(errorEl, "");
        }

        function applyTechnicianScope() {
            const showsTechnician =
                typeSelect.value === "technician" &&
                scopeSelect.value === "specific";

            technicianWrap.classList.toggle("d-none", !showsTechnician);
        }

        function applyPeriod() {
            // A yearly report names a year and nothing narrower.
            monthWrap.classList.toggle(
                "d-none",
                periodSelect.value !== "monthly",
            );
        }

        typeSelect.addEventListener("change", applyReportType);
        scopeSelect.addEventListener("change", applyTechnicianScope);
        periodSelect.addEventListener("change", function () {
            applyPeriod();
            setAlert(errorEl, "");
        });

        applyReportType();
        applyPeriod();

        submitBtn.addEventListener("click", function () {
            setAlert(errorEl, "");
            submitBtn.disabled = true;
            spinner.classList.remove("d-none");

            const type = typeSelect.value;
            const payload = new FormData();

            payload.set("_token", csrfToken());
            payload.set("report_type", type);
            payload.set("period", periodSelect.value);
            payload.set("year", yearSelect.value);
            payload.set("format", formatSelect.value);

            if (periodSelect.value === "monthly") {
                payload.set("month", monthSelect.value);
            }

            // Only the filters this report owns are sent; the server refuses
            // the rest rather than ignoring them.
            if (type === "project") {
                payload.set("project_status", statusSelect.value);
            }

            if (type === "technician") {
                payload.set("technician_scope", scopeSelect.value);
                payload.set("technician_kind", kindSelect.value);

                if (scopeSelect.value === "specific") {
                    payload.set("technician_id", technicianSelect.value);
                }
            }

            fetch(routes.export, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: payload,
            })
                .then(function (response) {
                    if (!response.ok) {
                        return response
                            .json()
                            .catch(function () {
                                return {};
                            })
                            .then(function (body) {
                                throw new Error(
                                    body.error || "Unable to generate report.",
                                );
                            });
                    }

                    const disposition =
                        response.headers.get("content-disposition") || "";
                    const match = disposition.match(/filename="?([^"]+)"?/);

                    return response.blob().then(function (blob) {
                        return {
                            blob: blob,
                            name: match ? match[1] : "system-report.pdf",
                        };
                    });
                })
                .then(function (file) {
                    const url = URL.createObjectURL(file.blob);
                    const link = document.createElement("a");

                    link.href = url;
                    link.download = file.name;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    URL.revokeObjectURL(url);

                    spinner.classList.add("d-none");
                    submitBtn.disabled = false;

                    if (window.bootstrap) {
                        window.bootstrap.Modal.getOrCreateInstance(
                            exportModalEl,
                        ).hide();
                    }
                })
                .catch(function (error) {
                    spinner.classList.add("d-none");
                    submitBtn.disabled = false;
                    setAlert(errorEl, error.message);
                });
        });
    }

    // Initial load for the visible tab.
    if (reportsBody) {
        loadReports();
    }
});
