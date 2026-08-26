/**
 * My Schedule: a calendar of the lead's bookings on the left, the clicked
 * project and their tasks on it on the right.
 *
 * Clicking an event never reloads the page - it fetches that project's payload
 * and repaints the panel, which is also what happens after a task is
 * completed.
 */
document.addEventListener("DOMContentLoaded", function () {
    const portal = window.portal;
    const calendarEl = document.getElementById("technicianCalendar");
    const panelEl = document.querySelector("[data-details-panel]");

    if (!calendarEl || !panelEl) {
        return;
    }

    const emptyEl = panelEl.querySelector("[data-panel-empty]");
    const loadingEl = panelEl.querySelector("[data-panel-loading]");
    const projectEl = panelEl.querySelector("[data-panel-project]");
    const formerEl = panelEl.querySelector("[data-panel-former]");
    const errorEl = panelEl.querySelector("[data-panel-error]");
    const tasksEl = panelEl.querySelector("[data-panel-tasks]");
    const tasksEmptyEl = panelEl.querySelector("[data-panel-tasks-empty]");
    const taskCountEl = panelEl.querySelector("[data-panel-task-count]");

    let currentProjectId = null;

    function setState(state) {
        emptyEl.classList.toggle("d-none", state !== "empty");
        loadingEl.classList.toggle("d-none", state !== "loading");
        projectEl.classList.toggle("d-none", state !== "project");

        if (formerEl) {
            formerEl.classList.toggle("d-none", state !== "former");
        }
    }

    // A date belonging to a project this technician has been taken off.
    //
    // Written from the calendar event alone - no fetch. The dates stay on the
    // calendar because they record where this person was, but the project
    // stopped being theirs to read the moment they came off the team, and
    // asking the server for it would be asking for exactly what they should no
    // longer see.
    function renderFormer(props) {
        if (!formerEl) {
            return;
        }

        currentProjectId = null;

        const setFormer = function (selector, value) {
            const element = formerEl.querySelector(selector);

            if (element) {
                element.textContent = value;
            }
        };

        setFormer("[data-former-ref]", props.referenceNo || "This project");
        setFormer("[data-former-range]", props.rangeLabel || "");
        setFormer(
            "[data-former-message]",
            props.removedOn
                ? "You were removed from this project on " +
                      props.removedOn +
                      ". These dates stay on your schedule as a record of when you were booked, but the project is no longer assigned to you."
                : "You are no longer assigned to this project. These dates stay on your schedule as a record of when you were booked.",
        );

        errorEl.classList.add("d-none");
        setState("former");
    }

    function setText(selector, value) {
        const element = panelEl.querySelector(selector);

        if (element) {
            element.textContent = value == null || value === "" ? "—" : value;
        }
    }

    function renderProject(project) {
        setText("[data-panel-ref]", project.reference_no);
        setText("[data-panel-name]", project.name);
        setText("[data-panel-id]", project.project_id);
        setText("[data-panel-client]", project.client);
        setText("[data-panel-address]", project.address);
        setText(
            "[data-panel-schedule]",
            project.ranges.length
                ? project.ranges
                      .map(function (range) {
                          return range.label;
                      })
                      .join("; ")
                : "No schedule set",
        );

        panelEl.querySelector("[data-panel-status]").innerHTML =
            '<span class="badge ' +
            project.status_badge_class +
            '">' +
            portal.escapeHtml(project.status_label) +
            "</span>";

        const lead = project.technicians.filter(function (technician) {
            return technician.is_lead;
        });
        const supporting = project.technicians.filter(function (technician) {
            return !technician.is_lead;
        });

        setText(
            "[data-panel-lead]",
            lead.length
                ? lead
                      .map(function (technician) {
                          return technician.name;
                      })
                      .join(", ")
                : "No lead assigned",
        );

        panelEl.querySelector("[data-panel-supporting]").innerHTML =
            supporting.length
                ? supporting
                      .map(function (technician) {
                          return (
                              '<span class="schedule-tech-chip">' +
                              portal.escapeHtml(technician.name) +
                              "</span>"
                          );
                      })
                      .join("")
                : '<span class="text-muted small">No supporting technicians</span>';
    }

    function renderTasks(tasks) {
        taskCountEl.textContent =
            tasks.length + (tasks.length === 1 ? " task" : " tasks");
        taskCountEl.classList.toggle("d-none", tasks.length === 0);
        tasksEmptyEl.classList.toggle("d-none", tasks.length > 0);

        tasksEl.innerHTML = tasks
            .map(function (task) {
                const completeButton = task.can_complete
                    ? '<button type="button" class="btn btn-sm btn-success mt-2" ' +
                      'data-complete-task="' +
                      task.task_id +
                      '" data-task-title="' +
                      portal.escapeHtml(task.title) +
                      '">' +
                      '<i class="bi bi-check-lg me-1"></i>Complete</button>'
                    : "";

                // What was submitted when the task was closed: the note and
                // the photos, not just the note.
                const closedOnBehalf = task.closed_on_behalf
                    ? '<div class="panel-task-onbehalf">Closed by ' +
                      portal.escapeHtml(task.completed_by || "someone else") +
                      " on the technician's behalf.</div>"
                    : "";

                const completion = task.status === "completed"
                    ? '<div class="panel-task-completion">' +
                      '<div class="panel-task-completion-label">Completion details</div>' +
                      closedOnBehalf +
                      '<div class="panel-task-description">' +
                      portal.escapeHtml(
                          task.completion_notes ||
                              (task.closed_on_behalf
                                  ? "No completion details were submitted."
                                  : "No completion description was recorded."),
                      ) +
                      "</div>" +
                      (task.images.length
                          ? '<div class="panel-task-images">' +
                            task.images
                                .map(function (image) {
                                    return (
                                        '<a href="' +
                                        image.url +
                                        '" target="_blank" rel="noopener noreferrer">' +
                                        '<img src="' +
                                        image.url +
                                        '" alt="Completion photo"></a>'
                                    );
                                })
                                .join("") +
                            "</div>"
                          : '<div class="text-muted small mt-1">No image was uploaded.</div>') +
                      "</div>"
                    : "";

                return (
                    '<div class="panel-task-card">' +
                    '<div class="panel-task-range">Due ' +
                    portal.escapeHtml(task.due_date_label) +
                    "</div>" +
                    '<div class="panel-task-title">' +
                    portal.escapeHtml(task.title) +
                    "</div>" +
                    '<div class="panel-task-description">' +
                    portal.escapeHtml(task.description) +
                    "</div>" +
                    completion +
                    '<div class="panel-task-meta">' +
                    '<span class="badge ' +
                    task.status_badge_class +
                    '">' +
                    portal.escapeHtml(task.status_label) +
                    "</span>" +
                    "</div>" +
                    completeButton +
                    "</div>"
                );
            })
            .join("");
    }

    function loadProject(projectId) {
        currentProjectId = projectId;
        portal.setAlert(errorEl, "");
        setState("loading");

        const url = window.portalRoutes.projectDetails.replace("__ID__", projectId);

        portal
            .request(url + "?mine_only=1")
            .then(function (body) {
                renderProject(body.project);
                renderTasks(body.tasks);
                setState("project");
            })
            .catch(function (error) {
                setState("project");
                portal.setAlert(errorEl, error.message);
            });
    }

    tasksEl.addEventListener("click", function (event) {
        const button = event.target.closest("[data-complete-task]");

        if (!button || !window.portalModals || !window.portalModals.completeTask) {
            return;
        }

        window.portalModals.completeTask.open({
            taskId: button.getAttribute("data-complete-task"),
            title: button.getAttribute("data-task-title"),
            onSuccess: function () {
                // Only the panel is refreshed; the calendar is unaffected by a
                // task changing state.
                loadProject(currentProjectId);
            },
        });
    });

    if (!window.FullCalendar) {
        return;
    }

    const calendar = new window.FullCalendar.Calendar(calendarEl, {
        initialView: "dayGridMonth",
        headerToolbar: window.calendarHeader.toolbar(),
        height: "auto",
        dayMaxEvents: true,
        eventDisplay: "block",
        // Partial days arrive as timed events; without this the bar would
        // abbreviate 8:00 AM to "8a".
        eventTimeFormat: {
            hour: "numeric",
            minute: "2-digit",
            meridiem: "short",
        },
        events: window.portalScheduleEvents || [],
        eventDidMount: function (info) {
            const props = info.event.extendedProps;

            info.el.setAttribute(
                "title",
                [
                    info.event.title,
                    props.projectName,
                    props.client,
                    props.rangeLabel,
                    props.statusLabel,
                    // Said on the bar itself, so it is clear before clicking
                    // why an old job is still on the calendar.
                    props.isFormer
                        ? "No longer assigned" +
                          (props.removedOn ? " · removed " + props.removedOn : "")
                        : null,
                ]
                    .filter(Boolean)
                    .join(" · "),
            );
        },
        eventClick: function (info) {
            const props = info.event.extendedProps;

            if (props.isFormer) {
                renderFormer(props);

                return;
            }

            loadProject(props.projectId);
        },
    });

    calendar.render();
    window.calendarHeader.attach(calendar, calendarEl);
});
