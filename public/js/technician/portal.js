/**
 * Shared helpers for the technician portal.
 *
 * The three things every page here needs and would otherwise each reinvent: a
 * DataTable configured the way the Super Admin portal configures its own, a
 * toast, and a JSON request that speaks the same error shape the controllers
 * answer with.
 */
(function (global) {
    "use strict";

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
     * The Super Admin DataTable settings, in one place. `noun` fills the
     * search placeholder and the two empty-state messages so every table
     * reads in its own terms without repeating the whole config.
     */
    function dataTable(selector, noun, options) {
        if (!global.jQuery || !global.jQuery.fn || !global.jQuery.fn.DataTable) {
            return null;
        }

        const target = global.jQuery(selector);

        if (!target.length) {
            return null;
        }

        return target.DataTable(
            Object.assign(
                {
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [10, 25, 50, 100],
                    info: false,
                    language: {
                        search: "",
                        searchPlaceholder: "Search " + noun + "...",
                        emptyTable: "No " + noun + " found.",
                        zeroRecords: "No " + noun + " match your search.",
                    },
                },
                options || {},
            ),
        );
    }

    /**
     * Same look as the flashed session toasts in the layout, raised from JS
     * for anything that happened over AJAX.
     */
    function toast(message, variant) {
        const container = document.querySelector("[data-toast-container]");

        if (!container || !message) {
            return;
        }

        // Warning and information read as dark text on their backgrounds, so
        // the close button has to switch with them.
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

        const instance = new global.bootstrap.Toast(element, {
            autohide: true,
            delay: 4000,
        });

        element.addEventListener("hidden.bs.toast", function () {
            element.remove();
        });

        instance.show();
    }

    /**
     * Every portal endpoint answers with JSON, errors included. Resolves with
     * the parsed body and rejects with an Error carrying the server's message
     * so callers only ever have to handle one failure shape.
     */
    function request(url, options) {
        const config = Object.assign({ headers: {} }, options || {});

        config.headers = Object.assign(
            {
                Accept: "application/json",
                "X-CSRF-TOKEN": csrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            config.headers,
        );

        // FormData sets its own multipart boundary; naming a content type
        // here would corrupt the upload.
        if (config.body && !(config.body instanceof FormData)) {
            config.headers["Content-Type"] = "application/json";
        }

        return fetch(url, config).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (body) {
                    if (response.ok) {
                        return body;
                    }

                    const error = new Error(
                        body.error ||
                            body.message ||
                            "Something went wrong. Please try again.",
                    );

                    error.body = body;
                    error.status = response.status;

                    throw error;
                });
        });
    }

    /**
     * Toggle a button between its label and a spinner, so a slow save reads
     * as busy rather than broken.
     */
    function setBusy(button, isBusy) {
        if (!button) {
            return;
        }

        const spinner = button.querySelector("[data-spinner]");

        button.disabled = isBusy;

        if (spinner) {
            spinner.classList.toggle("d-none", !isBusy);
        }
    }

    function setAlert(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message || "";
        element.classList.toggle("d-none", !message);
    }

    /**
     * Thumbnails for the files sitting in a file input, so an upload can be
     * checked before it is sent.
     */
    function previewImages(input, container) {
        if (!input || !container) {
            return;
        }

        input.addEventListener("change", function () {
            container.innerHTML = "";

            Array.prototype.forEach.call(input.files || [], function (file) {
                if (!file.type.startsWith("image/")) {
                    return;
                }

                const column = document.createElement("div");
                column.className = "col-4 col-md-3";

                const image = document.createElement("img");
                image.className = "img-fluid rounded border";
                image.style.height = "110px";
                image.style.width = "100%";
                image.style.objectFit = "cover";
                image.src = URL.createObjectURL(file);
                image.addEventListener("load", function () {
                    URL.revokeObjectURL(image.src);
                });

                column.appendChild(image);
                container.appendChild(column);
            });
        });
    }

    global.portal = {
        escapeHtml: escapeHtml,
        dataTable: dataTable,
        toast: toast,
        request: request,
        setBusy: setBusy,
        setAlert: setAlert,
        previewImages: previewImages,
    };
})(window);
