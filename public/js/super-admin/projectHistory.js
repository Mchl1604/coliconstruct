/**
 * The two history buttons on the project details page.
 *
 * The panels on that page show how a project stands now - who is on the team,
 * which ranges it holds - and a state says nothing about how it got there.
 * These answer the other half: what changed, when, and who changed it.
 *
 * One dialog serves both sections. The team's also lists the membership spans
 * behind those actions, because "Juan was on this from 3 July to 27 August" is
 * a different fact from "Michael removed 2 technicians on 27 August", and the
 * project details page could show neither.
 */
document.addEventListener("DOMContentLoaded", function () {
    const modalEl = document.querySelector("[data-project-history-modal]");

    if (!modalEl || !window.bootstrap) {
        return;
    }

    const eyebrowEl = modalEl.querySelector("[data-history-eyebrow]");
    const titleEl = modalEl.querySelector("[data-history-title]");
    const loadingEl = modalEl.querySelector("[data-history-loading]");
    const errorEl = modalEl.querySelector("[data-history-error]");
    const emptyEl = modalEl.querySelector("[data-history-empty]");
    const membershipsWrap = modalEl.querySelector("[data-history-memberships-wrap]");
    const membershipsEl = modalEl.querySelector("[data-history-memberships]");
    const entriesWrap = modalEl.querySelector("[data-history-entries-wrap]");
    const entriesEl = modalEl.querySelector("[data-history-entries]");

    const TITLES = {
        team: "Assigned Team History",
        schedule: "Project Schedule History",
    };

    // Each click supersedes the one before it: a reader who opens Team, closes
    // it and opens Schedule must not have the first response land in the
    // second panel. The token is compared on arrival and a stale one is
    // dropped.
    let token = 0;

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

    function show(element, visible) {
        if (element) {
            element.classList.toggle("d-none", !visible);
        }
    }

    function membershipMarkup(member) {
        // Both ends are labelled rather than printed as a bare range. "Added
        // 31 Jul" and "Added 31 Jul, removed 27 Aug" say what the dates ARE;
        // "31 Jul - 27 Aug" on its own could as easily be the dates they
        // worked, which is a different fact and one this row is not about.
        const span = member.removed_on
            ? "Added " +
              escapeHtml(member.joined_on || "?") +
              " &middot; Removed " +
              escapeHtml(member.removed_on)
            : "Added " + escapeHtml(member.joined_on || "?");

        const credits = [];

        if (member.added_by) {
            credits.push("Added by " + escapeHtml(member.added_by));
        }

        if (member.removed_by) {
            credits.push("Removed by " + escapeHtml(member.removed_by));
        }

        const by = credits.length
            ? '<div class="project-history-sub">' +
              credits.join(" &middot; ") +
              "</div>"
            : "";

        return (
            '<div class="project-history-member' +
            (member.is_current ? "" : " is-former") +
            '">' +
            '<div class="project-history-member-main">' +
            '<span class="project-history-name">' +
            escapeHtml(member.name) +
            "</span>" +
            (member.is_lead
                ? '<span class="project-history-tag">Lead</span>'
                : "") +
            (member.is_current
                ? ""
                : '<span class="project-history-tag is-former">No longer assigned</span>') +
            "</div>" +
            '<div class="project-history-span">' +
            span +
            "</div>" +
            by +
            "</div>"
        );
    }

    // added / removed / changed - see ProjectController::ENTRY_KINDS. Three
    // meanings rather than one colour per action name, so the list can be
    // scanned for the shape of a change without reading it.
    function entryMarkup(entry) {
        const kind = entry.kind || "changed";

        return (
            '<div class="project-history-entry is-' +
            escapeHtml(kind) +
            '">' +
            '<div class="project-history-entry-head">' +
            '<span class="project-history-action">' +
            escapeHtml(entry.action) +
            "</span>" +
            '<span class="project-history-at">' +
            escapeHtml(entry.at) +
            "</span>" +
            "</div>" +
            '<div class="project-history-desc">' +
            escapeHtml(entry.description) +
            "</div>" +
            '<div class="project-history-sub">by ' +
            escapeHtml(entry.actor) +
            "</div>" +
            "</div>"
        );
    }

    function render(section, body) {
        const memberships = body.memberships || [];
        const entries = body.entries || [];

        membershipsEl.innerHTML = memberships.map(membershipMarkup).join("");
        entriesEl.innerHTML = entries.map(entryMarkup).join("");

        show(membershipsWrap, memberships.length > 0);
        show(entriesWrap, entries.length > 0);
        show(emptyEl, memberships.length === 0 && entries.length === 0);
    }

    function open(section) {
        const current = ++token;

        titleEl.textContent = TITLES[section] || "Change History";
        eyebrowEl.textContent =
            section === "team" ? "Assigned Team" : "Project Schedule";

        show(loadingEl, true);
        show(errorEl, false);
        show(emptyEl, false);
        show(membershipsWrap, false);
        show(entriesWrap, false);

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();

        // The route is built server-side with a placeholder, so the URL shape
        // stays Laravel's business rather than being assembled here.
        const url = (window.projectHistoryUrl || "").replace(
            "__SECTION__",
            section,
        );

        fetch(url, { headers: { Accept: "application/json" } })
            .then(function (response) {
                return response.json().then(function (body) {
                    return { ok: response.ok, body: body };
                });
            })
            .then(function (result) {
                if (current !== token) {
                    return;
                }

                show(loadingEl, false);

                if (!result.ok) {
                    errorEl.textContent =
                        result.body.error || "Unable to load history.";
                    show(errorEl, true);

                    return;
                }

                render(section, result.body);
            })
            .catch(function () {
                if (current !== token) {
                    return;
                }

                show(loadingEl, false);
                errorEl.textContent = "Unable to load history.";
                show(errorEl, true);
            });
    }

    document.querySelectorAll("[data-project-history]").forEach(function (button) {
        button.addEventListener("click", function () {
            open(button.getAttribute("data-project-history"));
        });
    });
});
