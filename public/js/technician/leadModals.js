/**
 * The two dialogs the lead technician portal shares.
 *
 * Complete Task and the report form each appear on more than one page, so the
 * markup is included once per page as a partial and driven from here. Callers
 * pass what they know and a callback; the dialog handles the request, the
 * spinner, the errors and the toast.
 *
 * Creating a task is not here: the task board renders that dialog itself and
 * posts it as a plain form (see taskCreate.js).
 */
(function (global) {
    "use strict";

    const portal = global.portal;

    function route(name, id) {
        const template = (global.leadRoutes || {})[name];

        return template ? template.replace("__ID__", id) : null;
    }

    function modalFor(element) {
        return element ? global.bootstrap.Modal.getOrCreateInstance(element) : null;
    }

    // ------------------------------------------------------------------
    // Complete Task
    // ------------------------------------------------------------------

    function completeTaskDialog() {
        const root = document.querySelector("[data-complete-task-modal]");

        if (!root) {
            return null;
        }

        const form = root.querySelector("[data-complete-task-form]");
        const titleEl = root.querySelector("[data-complete-task-title]");
        const notesEl = form.querySelector('[name="completion_notes"]');
        const imagesEl = root.querySelector("[data-complete-task-images]");
        const previewEl = root.querySelector("[data-complete-task-preview]");
        const errorEl = root.querySelector("[data-complete-task-error]");
        const submitEl = root.querySelector("[data-complete-task-submit]");

        let taskId = null;
        let onSuccess = null;

        portal.previewImages(imagesEl, previewEl);

        root.addEventListener("hidden.bs.modal", function () {
            form.reset();
            previewEl.innerHTML = "";
            portal.setAlert(errorEl, "");
            taskId = null;
            onSuccess = null;
        });

        form.addEventListener("submit", function (event) {
            event.preventDefault();
            portal.setAlert(errorEl, "");

            if (!notesEl.value.trim()) {
                portal.setAlert(
                    errorEl,
                    "Describe what was done before completing the task.",
                );

                return;
            }

            portal.setBusy(submitEl, true);

            portal
                .request(route("completeTask", taskId), {
                    method: "POST",
                    body: new FormData(form),
                })
                .then(function (body) {
                    modalFor(root).hide();
                    portal.toast(body.message || "Task completed.");

                    if (onSuccess) {
                        onSuccess(body.task);
                    }
                })
                .catch(function (error) {
                    portal.setAlert(errorEl, error.message);
                })
                .finally(function () {
                    portal.setBusy(submitEl, false);
                });
        });

        return {
            open: function (options) {
                taskId = options.taskId;
                onSuccess = options.onSuccess || null;
                titleEl.textContent = options.title || "";
                modalFor(root).show();
            },
        };
    }

    // ------------------------------------------------------------------
    // Submit report
    // ------------------------------------------------------------------

    function reportFormDialog() {
        const root = document.querySelector("[data-report-form-modal]");

        if (!root) {
            return null;
        }

        const form = root.querySelector("[data-report-form]");
        const projectWrapEl = root.querySelector("[data-report-form-project-wrap]");
        const projectSelectEl = root.querySelector("[data-report-form-project]");
        const fixedProjectEl = root.querySelector("[data-report-form-fixed-project]");
        const imagesEl = root.querySelector("[data-report-form-images]");
        const previewEl = root.querySelector("[data-report-form-preview]");
        const errorEl = root.querySelector("[data-report-form-error]");
        const submitEl = root.querySelector("[data-report-form-submit]");

        let fixedProjectId = null;
        let onSuccess = null;

        portal.previewImages(imagesEl, previewEl);

        root.addEventListener("hidden.bs.modal", function () {
            form.reset();
            previewEl.innerHTML = "";
            portal.setAlert(errorEl, "");
            fixedProjectId = null;
            onSuccess = null;
        });

        form.addEventListener("submit", function (event) {
            event.preventDefault();
            portal.setAlert(errorEl, "");

            const projectId = fixedProjectId || projectSelectEl.value;

            if (!projectId) {
                portal.setAlert(errorEl, "Pick the project this report is about.");

                return;
            }

            portal.setBusy(submitEl, true);

            portal
                .request(route("storeReport", projectId), {
                    method: "POST",
                    body: new FormData(form),
                })
                .then(function (body) {
                    modalFor(root).hide();
                    portal.toast(body.message || "Report submitted.");

                    if (onSuccess) {
                        onSuccess(body.report);
                    }
                })
                .catch(function (error) {
                    portal.setAlert(errorEl, error.message);
                })
                .finally(function () {
                    portal.setBusy(submitEl, false);
                });
        });

        return {
            open: function (options) {
                const settings = options || {};

                fixedProjectId = settings.projectId || null;
                onSuccess = settings.onSuccess || null;

                projectWrapEl.classList.toggle("d-none", Boolean(fixedProjectId));

                if (projectSelectEl) {
                    projectSelectEl.required = !fixedProjectId;
                }

                fixedProjectEl.classList.toggle("d-none", !fixedProjectId);
                fixedProjectEl.textContent = fixedProjectId
                    ? "Filing against " + (settings.projectLabel || "")
                    : "";

                modalFor(root).show();
            },
        };
    }

    document.addEventListener("DOMContentLoaded", function () {
        global.leadModals = {
            completeTask: completeTaskDialog(),
            reportForm: reportFormDialog(),
        };

        document.dispatchEvent(new CustomEvent("lead-modals:ready"));
    });
})(window);
