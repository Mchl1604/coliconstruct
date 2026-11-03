/**
 * The create wizard's Materials step: what the job needs, checked against the
 * warehouse as it is typed.
 *
 * Each picked material becomes a line with its quantity and its stock figures
 * - available is on hand less what other live projects have reserved - and a
 * badge saying whether the warehouse can cover it. A shortage is a warning:
 * the line still posts, the project is still booked, and Purchasing is told
 * once it is saved.
 *
 * The lines post with the wizard's own form as materials[n][material_id] and
 * materials[n][quantity]. A failed save comes back with them in
 * window.projectWizardMaterials.rows, read again with today's figures.
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
    const shortAlert = step.querySelector("[data-wizard-material-short]");
    const review = document.querySelector("[data-wizard-material-review]");

    /** @type {Array<{material: Object, quantity: string}>} */
    const lines = (settings.rows || []).map(function (row) {
        return { material: row, quantity: row.quantity !== undefined ? String(row.quantity) : "" };
    });

    function formatQty(value) {
        const number = Number(value);

        if (!isFinite(number)) {
            return "0";
        }

        return number.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    /**
     * How much more the line needs than is available; zero when it is
     * covered.
     */
    function shortBy(line) {
        const needed = Number(line.quantity);
        const available = Number(line.material.available || 0);

        if (!isFinite(needed) || needed <= 0) {
            return 0;
        }

        return Math.max(0, Math.round((needed - available) * 100) / 100);
    }

    function badgeFor(line) {
        const badge = document.createElement("span");
        const short = shortBy(line);

        badge.className = "stock-status " + (short > 0 ? "is-out_of_stock" : "is-in_stock");
        badge.textContent = short > 0 ? "Short " + formatQty(short) : "In Stock";

        return badge;
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
            qty.min = material.allows_fractions ? "0.01" : "1";
            qty.step = material.allows_fractions ? "0.01" : "1";
            qty.required = true;
            qty.value = line.quantity;
            qty.setAttribute("aria-label", "Quantity of " + material.label);
            qty.dataset.wizardMaterialQty = String(index);
            unit.className = "input-group-text";
            unit.textContent = material.unit;
            group.append(qty, unit);
            qtyCell.appendChild(group);

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
        });

        shortAlert.classList.toggle(
            "d-none",
            !lines.some(function (line) {
                return shortBy(line) > 0;
            }),
        );

        if (!review) {
            return;
        }

        review.replaceChildren();

        if (!lines.length) {
            review.textContent = "None";

            return;
        }

        lines.forEach(function (line, index) {
            const short = shortBy(line);

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
                        (short > 0 ? " (short " + formatQty(short) + ")" : ""),
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
            lines.push({ material: material, quantity: material.allows_fractions ? "" : "1" });
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
