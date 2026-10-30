/**
 * A project's description: one project-details-editor section per type.
 *
 * Shared by the create wizard's Project Details step and the Edit Details
 * dialog on Project Details. Adds a unit from the section's template, removes
 * one (never the last), and keeps the "Unit N" titles in order. A section that
 * is switched off has its inputs disabled, so they are neither checked nor
 * submitted - and come back as they were if it is switched on again.
 */
(function (global) {
    'use strict';

    function rows(section) {
        return Array.from(section.querySelectorAll('[data-units-rows] > [data-detail-row]'));
    }

    function renumber(section) {
        const list = rows(section);

        list.forEach(function (row, index) {
            const title = row.querySelector('[data-detail-row-title]');

            if (title) {
                title.textContent = 'Unit ' + (index + 1);
            }

            const remove = row.querySelector('[data-detail-row-remove]');

            if (remove) {
                remove.classList.toggle('d-none', list.length < 2);
            }
        });
    }

    function addRow(section) {
        const template = section.querySelector('[data-units-template]');

        if (!template) {
            return;
        }

        const index = Number(section.dataset.unitsNext || rows(section).length);
        section.dataset.unitsNext = String(index + 1);

        const holder = document.createElement('div');
        holder.innerHTML = template.innerHTML.replace(/__ROW__/g, String(index));

        const row = holder.firstElementChild;
        section.querySelector('[data-units-rows]').appendChild(row);
        renumber(section);

        row.querySelector('[data-detail-input]')?.focus();
    }

    function setEnabled(section, enabled) {
        section.hidden = !enabled;
        section.querySelectorAll('input, select, textarea').forEach(function (input) {
            input.disabled = !enabled;
        });
    }

    function init(root) {
        root.querySelectorAll('[data-details-type]').forEach(renumber);

        root.addEventListener('click', function (event) {
            const add = event.target.closest('[data-units-add]');

            if (add) {
                addRow(add.closest('[data-details-type]'));

                return;
            }

            const remove = event.target.closest('[data-detail-row-remove]');

            if (remove) {
                const section = remove.closest('[data-details-type]');

                if (rows(section).length > 1) {
                    remove.closest('[data-detail-row]').remove();
                    renumber(section);
                }
            }
        });
    }

    function filledValues(container) {
        const parts = [];

        container.querySelectorAll('[data-detail-input]').forEach(function (input) {
            if (parts.length >= 4) {
                return;
            }

            if ((input.type === 'checkbox' && !input.checked) || !input.value || !input.value.trim()) {
                return;
            }

            parts.push(input.value.trim());
        });

        return parts.join(' · ');
    }

    /**
     * For the wizard's review: a short line of the project fields, and one per
     * unit. A unit with nothing filled in yet reads as an empty string, so the
     * count still matches the units on screen.
     */
    function summarize(section) {
        const projectFields = section.querySelector(':scope > [data-detail-fields]');

        return {
            project: projectFields ? filledValues(projectFields) : '',
            units: rows(section).map(filledValues),
            hasUnits: !!section.querySelector('[data-units-rows]'),
        };
    }

    global.projectDetailsForm = {
        init: init,
        setEnabled: setEnabled,
        summarize: summarize,
    };
})(window);
