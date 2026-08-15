/**
 * The Add Task dialog, project first.
 *
 * Both portals render the same markup and answer the same JSON shape from
 * their own form-data endpoint, so this one script drives both. The form then
 * posts normally - each portal's store action redirects back with a flash.
 */
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.querySelector("[data-task-create-modal]");

    if (!modal) {
        return;
    }

    const form = modal.querySelector("[data-task-create-form]");
    const projectSelect = modal.querySelector("[data-task-create-project]");
    const fields = modal.querySelector("[data-task-create-fields]");
    const technicians = modal.querySelector("[data-task-create-technicians]");
    const rangesHint = modal.querySelector("[data-task-create-ranges]");
    const errorEl = modal.querySelector("[data-task-create-error]");
    const submit = modal.querySelector("[data-task-create-submit]");
    const startInput = form.querySelector("[data-task-start]");
    const dueInput = form.querySelector("[data-task-due]");

    const formDataUrl = modal.dataset.formDataUrl;
    const storeUrl = modal.dataset.storeUrl;

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

    function setError(message) {
        errorEl.textContent = message || "";
        errorEl.classList.toggle("d-none", !message);
    }

    function reset() {
        form.reset();
        fields.classList.add("d-none");
        technicians.innerHTML = "";
        rangesHint.textContent = "";
        submit.disabled = true;
        form.action = "";
        setError("");
    }

    modal.addEventListener("hidden.bs.modal", reset);

    projectSelect.addEventListener("change", function () {
        const projectId = projectSelect.value;

        fields.classList.add("d-none");
        technicians.innerHTML = "";
        submit.disabled = true;
        setError("");

        if (!projectId) {
            return;
        }

        form.action = storeUrl.replace("__ID__", projectId);

        fetch(formDataUrl.replace("__ID__", projectId), {
            headers: { Accept: "application/json" },
        })
            .then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok) {
                        throw new Error(
                            body.error || "Unable to load that project.",
                        );
                    }

                    return body;
                });
            })
            .then(function (data) {
                const ranges = data.ranges || [];

                if (window.taskDatePickers) {
                    window.taskDatePickers.applyScheduleRanges(
                        startInput,
                        dueInput,
                        ranges,
                    );
                }

                rangesHint.textContent = ranges.length
                    ? window.taskDatePickers.describeSelectable(ranges)
                    : "";

                if (!data.technicians.length) {
                    setError("This project has no assigned technicians yet.");

                    return;
                }

                technicians.innerHTML = data.technicians
                    .map(function (technician) {
                        const count = technician.active_task_count;

                        return (
                            "<label>" +
                            '<input type="radio" class="btn-check" name="technician_id" value="' +
                            technician.technician_id +
                            '" required>' +
                            '<div class="task-assign-card">' +
                            // Same markup the Blade-rendered assign cards
                            // produce, so a person looks the same wherever
                            // they are picked from.
                            '<img class="user-avatar user-avatar-lg task-assign-avatar" src="' +
                            escapeHtml(
                                technician.avatar_url ||
                                    "/img/default-avatar.svg",
                            ) +
                            '" alt="' +
                            escapeHtml(technician.name) +
                            '" loading="lazy" decoding="async">' +
                            '<div class="task-assign-name">' +
                            escapeHtml(technician.name) +
                            "</div>" +
                            '<div class="task-assign-count">' +
                            count +
                            " Active Task" +
                            (count === 1 ? "" : "s") +
                            "</div>" +
                            (technician.role === "lead_technician" ||
                            technician.is_lead
                                ? '<span class="badge bg-primary task-assign-lead">Lead</span>'
                                : "") +
                            "</div>" +
                            "</label>"
                        );
                    })
                    .join("");

                fields.classList.remove("d-none");
                submit.disabled = false;
            })
            .catch(function (error) {
                setError(error.message);
            });
    });
});
