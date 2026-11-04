/**
 * The create wizard's Materials step: what the job needs, checked against the
 * warehouse as it is typed.
 *
 * Each picked material becomes a line with its quantity and its stock figures
 * - available is on hand less what other live projects have reserved. A line
 * cannot take more than is available: a larger number falls back to the most
 * it can have, and a checkbox offers to request the rest from Purchasing.
 *
 * The lines post with the wizard's own form as materials[n][material_id],
 * materials[n][quantity] and, when ticked, materials[n][request_remaining]. A
 * line with nothing available and nothing requested does not post at all. A
 * failed save comes back with them in window.projectWizardMaterials.rows, read
 * again with today's figures. ProjectMaterialPlan::limitToAvailable() holds the
 * server to the same limit.
 *
 * Every value from the server is written with textContent.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const step = document.querySelector("[data-wizard-materials]");
    const settings = window.projectWizardMaterials || {};

    if (!step || !settings.url || !window.materialPicker) {
        return;
    }

    const search = step.querySelector("[data-wizard-material-search]");
    const rowsBody = step.querySelector("[data-wizard-material-rows]");
    const empty = step.querySelector("[data-wizard-material-empty]");
    const review = document.querySelector("[data-wizard-material-review]");

    /** @type {Array<{material: Object, quantity: string, remaining: number, requested: boolean}>} */
    const lines = (settings.rows || []).map(function (row) {
        const remaining = Number(row.request_remaining || 0);

        return {
            material: row,
            quantity: row.quantity !== undefined ? String(row.quantity) : "",
            remaining: remaining,
            requested: remaining > 0,
        };
    });

    function formatQty(value) {
        const number = Number(value);

        if (!isFinite(number)) {
            return "0";
        }

        return number.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function availableFor(line) {
        return Number(line.material.available || 0);
    }

    /**
     * How much of what was asked for is over the limit; zero when it fits.
     */
    function overBy(needed, line) {
        return isFinite(needed) && needed > 0 ? Math.max(0, Math.round((needed - availableFor(line)) * 100) / 100) : 0;
    }

    /**
     * A number over the limit falls back to the limit, and the rest is
     * remembered for the checkbox to offer.
     */
    function applyLimit(line) {
        const needed = Number(line.quantity);
        const over = overBy(needed, line);

        line.remaining = over;
        line.requested = over > 0 && line.requested;

        if (over > 0) {
            line.quantity = String(availableFor(line));
        }
    }

    function badgeFor(line) {
        const badge = document.createElement("span");
        const available = availableFor(line);
        const limited = line.remaining > 0 || overBy(Number(line.quantity), line) > 0;

        if (available <= 0) {
            badge.className = "stock-status is-out_of_stock";
            badge.textContent = "Unavailable";
        } else if (limited) {
            badge.className = "stock-status is-low_stock";
            badge.textContent = "Limited";
        } else {
            badge.className = "stock-status is-in_stock";
            badge.textContent = "In Stock";
        }

        return badge;
    }

    /**
     * "Only 50 available", and the offer to request the rest.
     */
    function noteFor(line, index) {
        const note = document.createDocumentFragment();
        const available = availableFor(line);
        const over = overBy(Number(line.quantity), line);

        if (line.remaining <= 0 && over <= 0 && available > 0) {
            return note;
        }

        const message = document.createElement("div");

        message.className = "small text-danger mt-1";
        message.textContent = available > 0 ? "Only " + formatQty(available) + " " + line.material.unit + " available." : "Unavailable.";
        note.appendChild(message);

        if (line.remaining > 0) {
            const check = document.createElement("div");
            const box = document.createElement("input");
            const label = document.createElement("label");

            check.className = "form-check small mt-1";
            box.type = "checkbox";
            box.className = "form-check-input";
            box.id = "wizardMaterialRequest" + index;
            box.name = "materials[" + index + "][request_remaining]";
            box.value = String(line.remaining);
            box.checked = line.requested;
            box.dataset.wizardMaterialRequest = String(index);
            label.className = "form-check-label";
            label.htmlFor = box.id;
            label.textContent = "Request remaining " + formatQty(line.remaining) + " " + line.material.unit;
            check.append(box, label);
            note.appendChild(check);
        }

        return note;
    }

    /**
     * A line with nothing available and nothing requested is not part of the
     * plan, so it posts nothing.
     */
    function syncPosting(index) {
        const line = lines[index];
        const id = rowsBody.querySelector('[data-wizard-material-id="' + index + '"]');
        const qty = rowsBody.querySelector('[data-wizard-material-qty="' + index + '"]');
        const posts = Number(line.quantity) > 0 || line.requested || availableFor(line) > 0;

        if (id && qty) {
            id.name = posts ? "materials[" + index + "][material_id]" : "";
            qty.name = posts ? "materials[" + index + "][quantity]" : "";
        }
    }

    function render() {
        rowsBody.replaceChildren();

        lines.forEach(function (line, index) {
            const material = line.material;
            const row = document.createElement("tr");

            // Material
            const nameCell = document.createElement("td");
            const name = document.createElement("span");
            const id = document.createElement("input");

            name.className = "material-name";
            name.textContent = material.name;
            nameCell.appendChild(name);

            if (material.brand) {
                const brand = document.createElement("span");

                brand.className = "material-brand";
                brand.textContent = material.brand;
                nameCell.appendChild(brand);
            }

            const meta = document.createElement("div");

            meta.className = "material-meta";
            meta.textContent = [material.type, material.subtype].filter(Boolean).join(" · ");
            nameCell.appendChild(meta);

            id.type = "hidden";
            id.name = "materials[" + index + "][material_id]";
            id.value = String(material.material_id);
            id.dataset.wizardMaterialId = String(index);
            nameCell.appendChild(id);

            // Needed
            const qtyCell = document.createElement("td");
            const group = document.createElement("div");
            const qty = document.createElement("input");
            const unit = document.createElement("span");

            group.className = "input-group input-group-sm";
            qty.type = "number";
            qty.className = "form-control";
            qty.name = "materials[" + index + "][quantity]";
            qty.min = availableFor(line) <= 0 ? "0" : material.allows_fractions ? "0.01" : "1";
            qty.step = material.allows_fractions ? "0.01" : "1";
            qty.required = true;
            qty.value = line.quantity;
            qty.setAttribute("aria-label", "Quantity of " + material.label);
            qty.dataset.wizardMaterialQty = String(index);
            unit.className = "input-group-text";
            unit.textContent = material.unit;
            group.append(qty, unit);
            qtyCell.appendChild(group);

            const note = document.createElement("div");

            note.dataset.wizardMaterialNote = String(index);
            note.appendChild(noteFor(line, index));
            qtyCell.appendChild(note);

            // Available
            const availableCell = document.createElement("td");
            const available = document.createElement("div");
            const onHand = document.createElement("div");

            availableCell.className = "text-nowrap";
            available.className = "material-qty fw-semibold";
            available.textContent = formatQty(material.available) + " " + material.unit;
            onHand.className = "material-meta";
            onHand.textContent = "On hand " + formatQty(material.on_hand) + " · Reserved " + formatQty(material.reserved);
            availableCell.append(available, onHand);

            // Stock
            const stockCell = document.createElement("td");

            stockCell.dataset.wizardMaterialBadge = String(index);
            stockCell.appendChild(badgeFor(line));

            // Remove
            const removeCell = document.createElement("td");
            const remove = document.createElement("button");

            removeCell.className = "text-center";
            remove.type = "button";
            remove.className = "btn btn-sm btn-outline-danger py-1 px-2";
            remove.title = "Remove";
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            remove.dataset.wizardMaterialRemove = String(index);
            removeCell.appendChild(remove);

            row.append(nameCell, qtyCell, availableCell, stockCell, removeCell);
            rowsBody.appendChild(row);
        });

        refresh();
    }

    /**
     * The parts that follow a quantity being typed: the badges, the warning
     * and the review step - without redrawing the inputs being typed in.
     */
    function refresh() {
        empty.classList.toggle("d-none", lines.length > 0);

        lines.forEach(function (line, index) {
            const cell = rowsBody.querySelector('[data-wizard-material-badge="' + index + '"]');

            if (cell) {
                cell.replaceChildren(badgeFor(line));
            }

            syncPosting(index);
        });

        if (!review) {
            return;
        }

        review.replaceChildren();

        if (!lines.length) {
            review.textContent = "None";

            return;
        }

        lines.forEach(function (line, index) {
            if (index > 0) {
                review.appendChild(document.createElement("br"));
            }

            review.appendChild(
                document.createTextNode(
                    line.material.label +
                        " - " +
                        (line.quantity ? formatQty(line.quantity) : "?") +
                        " " +
                        line.material.unit +
                        (line.requested ? " (request " + formatQty(line.remaining) + " more)" : ""),
                ),
            );
        });
    }

    window.materialPicker.attach(search, {
        url: settings.url,
        exclude: function () {
            return lines.map(function (line) {
                return line.material.material_id;
            });
        },
        onPick: function (material) {
            lines.push({
                material: material,
                quantity: Number(material.available || 0) <= 0 ? "0" : material.allows_fractions ? "" : "1",
                remaining: 0,
                requested: false,
            });
            search.value = "";
            render();

            const added = rowsBody.querySelector('[data-wizard-material-qty="' + (lines.length - 1) + '"]');

            if (added) {
                added.focus();
                added.select();
            }
        },
    });

    rowsBody.addEventListener("input", function (event) {
        const index = event.target.dataset.wizardMaterialQty;

        if (index === undefined || !lines[Number(index)]) {
            return;
        }

        lines[Number(index)].quantity = event.target.value;
        refresh();
    });

    // Once the number is finished: falls back to the limit if it is over.
    rowsBody.addEventListener("change", function (event) {
        const requestIndex = event.target.dataset.wizardMaterialRequest;

        if (requestIndex !== undefined && lines[Number(requestIndex)]) {
            lines[Number(requestIndex)].requested = event.target.checked;
            refresh();

            return;
        }

        const index = event.target.dataset.wizardMaterialQty;

        if (index === undefined || !lines[Number(index)]) {
            return;
        }

        const line = lines[Number(index)];

        applyLimit(line);
        event.target.value = line.quantity;
        rowsBody.querySelector('[data-wizard-material-note="' + index + '"]').replaceChildren(noteFor(line, Number(index)));
        refresh();
    });

    rowsBody.addEventListener("click", function (event) {
        const remove = event.target.closest("[data-wizard-material-remove]");

        if (!remove) {
            return;
        }

        lines.splice(Number(remove.dataset.wizardMaterialRemove), 1);
        render();
    });

    render();
});
