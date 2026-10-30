/**
 * The incident report form: the sections that only fit some incidents, and
 * the People Involved rows.
 *
 * An injury's fields show for an injury, a refrigerant's for a refrigerant
 * leak; the server keeps them only for that type either way. Each person row
 * posts as persons[n][technician_id|name|person_type|involvement], numbered
 * again on every change so a removed row leaves no gap.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const form = document.querySelector("[data-incident-form]");

    if (!form) {
        return;
    }

    const type = form.querySelector('[name="incident_type"]');
    const holder = form.querySelector("[data-incident-persons]");
    const empty = form.querySelector("[data-incident-persons-empty]");
    const template = document.querySelector("[data-incident-person-template]");

    function showSections() {
        form.querySelectorAll("[data-incident-for]").forEach(function (section) {
            section.classList.toggle("d-none", section.dataset.incidentFor !== type.value);
        });
    }

    function renumber() {
        const rows = holder.querySelectorAll("[data-incident-person]");

        rows.forEach(function (row, index) {
            row.querySelectorAll("[data-person-field]").forEach(function (field) {
                field.name = "persons[" + index + "][" + field.dataset.personField + "]";
            });
        });

        empty.classList.toggle("d-none", rows.length > 0);
    }

    /**
     * A team member is named from the team, so the name box and "who" are
     * not theirs to fill.
     */
    function syncRow(row) {
        const member = row.querySelector('[data-person-field="technician_id"]');
        const name = row.querySelector('[data-person-field="name"]');
        const who = row.querySelector('[data-person-field="person_type"]');
        const onTeam = member.value !== "";

        name.disabled = onTeam;
        name.required = !onTeam;

        if (onTeam) {
            name.value = "";
            who.value = "team";
        } else if (who.value === "team") {
            who.value = "client";
        }

        who.disabled = onTeam;

        // A disabled select posts nothing, so the team member's "who" goes
        // with a hidden copy.
        let hidden = row.querySelector("[data-person-type-hidden]");

        if (onTeam && !hidden) {
            hidden = document.createElement("input");
            hidden.type = "hidden";
            hidden.value = "team";
            hidden.dataset.personTypeHidden = "";
            hidden.dataset.personField = "person_type";
            row.appendChild(hidden);
        } else if (!onTeam && hidden) {
            hidden.remove();
        }

        renumber();
    }

    form.querySelector("[data-incident-person-add]").addEventListener("click", function () {
        holder.appendChild(template.content.cloneNode(true));

        const row = holder.lastElementChild;

        syncRow(row);
    });

    holder.addEventListener("change", function (event) {
        const row = event.target.closest("[data-incident-person]");

        if (row && event.target.matches('[data-person-field="technician_id"]')) {
            syncRow(row);
        }
    });

    holder.addEventListener("click", function (event) {
        const remove = event.target.closest("[data-incident-person-remove]");

        if (remove) {
            remove.closest("[data-incident-person]").remove();
            renumber();
        }
    });

    type.addEventListener("change", showSections);

    showSections();
    renumber();
});
