/**
 * The quotation lines: a material from the list with its Inventory price, or a
 * typed Labor or Other line. A quantity may go past what is in stock; the line
 * only says so. The Materials step is what holds to stock.
 *
 * Every change is announced as "quotation-lines:changed" with the quoted
 * materials, which is how the wizard's Materials step fills itself in without
 * anything being typed twice.
 *
 * Every value from the server is written with textContent or as a value.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    function money(value) {
        return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function amountOf(line) {
        const amount = Number(line.quantity) * Number(line.unit_price);

        return isFinite(amount) ? Math.round(amount * 100) / 100 : 0;
    }

    function setUp(root) {
        const rowsBody = root.querySelector("[data-quotation-rows]");
        const empty = root.querySelector("[data-quotation-empty]");
        const totalText = root.querySelector("[data-quotation-total]");
        const search = root.querySelector("[data-quotation-material-search]");
        const lines = JSON.parse(root.dataset.rows || "[]");

        // Every job has labor, so a new quotation starts with the line for it.
        // It can be removed if this job has none.
        if (!lines.length) {
            lines.push({ kind: "labor", description: "Labor cost", unit: "lot", quantity: "1", unit_price: "", material: null });
        }

        function total() {
            return lines.reduce(function (sum, line) {
                return sum + amountOf(line);
            }, 0);
        }

        function stockNote(line) {
            const material = line.material;

            if (!material || material.available === undefined || Number(line.quantity) <= Number(material.available)) {
                return "";
            }

            return "Only " + Number(material.available).toLocaleString() + " " + material.unit + " available.";
        }

        function announce() {
            totalText.textContent = money(total());
            empty.classList.toggle("d-none", lines.length > 0);

            root.dispatchEvent(
                new CustomEvent("quotation-lines:changed", {
                    bubbles: true,
                    detail: {
                        total: total(),
                        lines: lines.slice(),
                        materials: lines
                            .filter(function (line) {
                                return line.material;
                            })
                            .map(function (line) {
                                return { material: line.material, quantity: Number(line.quantity) || 0 };
                            }),
                    },
                }),
            );
        }

        function input(type, name, value, attributes) {
            const field = document.createElement("input");

            field.type = type;
            field.name = name;
            field.value = value === null || value === undefined ? "" : String(value);
            Object.assign(field, attributes || {});

            return field;
        }

        function render() {
            rowsBody.replaceChildren();

            lines.forEach(function (line, index) {
                const base = "quotation_items[" + index + "]";
                const row = document.createElement("tr");
                const nameCell = document.createElement("td");

                nameCell.appendChild(input("hidden", base + "[kind]", line.kind));

                if (line.material) {
                    const name = document.createElement("div");

                    name.className = "fw-semibold";
                    name.textContent = line.material.label;
                    nameCell.append(name, input("hidden", base + "[material_id]", line.material.material_id));
                } else {
                    const badge = document.createElement("div");

                    badge.className = "small text-secondary mb-1";
                    badge.textContent = line.kind === "labor" ? "Labor" : "Other";
                    nameCell.append(
                        badge,
                        input("text", base + "[description]", line.description, {
                            className: "form-control form-control-sm",
                            maxLength: 255,
                            required: true,
                            placeholder: "Description",
                        }),
                    );
                    nameCell.lastChild.dataset.quotationField = "description";
                    nameCell.lastChild.dataset.quotationIndex = String(index);
                }

                const note = document.createElement("div");

                note.className = "small text-warning-emphasis mt-1";
                note.dataset.quotationNote = String(index);
                note.textContent = stockNote(line);
                nameCell.appendChild(note);

                const qtyCell = document.createElement("td");
                const qty = input("number", base + "[quantity]", line.quantity, {
                    className: "form-control form-control-sm",
                    required: true,
                    min: line.material && !line.material.allows_fractions ? "1" : "0.01",
                    step: line.material && !line.material.allows_fractions ? "1" : "0.01",
                });

                qty.dataset.quotationField = "quantity";
                qty.dataset.quotationIndex = String(index);
                qty.setAttribute("aria-label", "Quantity");
                qtyCell.appendChild(qty);

                const unitCell = document.createElement("td");

                if (line.material) {
                    unitCell.textContent = line.material.unit;
                } else {
                    const unit = input("text", base + "[unit]", line.unit, {
                        className: "form-control form-control-sm",
                        maxLength: 20,
                        placeholder: "Optional",
                    });

                    unit.dataset.quotationField = "unit";
                    unit.dataset.quotationIndex = String(index);
                    unit.setAttribute("aria-label", "Unit");
                    unitCell.appendChild(unit);
                }

                const priceCell = document.createElement("td");
                const price = input("number", base + "[unit_price]", line.unit_price, {
                    className: "form-control form-control-sm",
                    required: true,
                    min: "0",
                    step: "0.01",
                });

                price.dataset.quotationField = "unit_price";
                price.dataset.quotationIndex = String(index);
                price.setAttribute("aria-label", "Unit price");
                priceCell.appendChild(price);

                const amountCell = document.createElement("td");

                amountCell.className = "text-end text-nowrap";
                amountCell.dataset.quotationAmount = String(index);
                amountCell.textContent = money(amountOf(line));

                const removeCell = document.createElement("td");
                const remove = document.createElement("button");

                removeCell.className = "text-center";
                remove.type = "button";
                remove.className = "btn btn-sm btn-outline-danger py-1 px-2";
                remove.title = "Remove";
                remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
                remove.dataset.quotationRemove = String(index);
                removeCell.appendChild(remove);

                row.append(nameCell, qtyCell, unitCell, priceCell, amountCell, removeCell);
                rowsBody.appendChild(row);
            });

            announce();
        }

        rowsBody.addEventListener("input", function (event) {
            const field = event.target.dataset.quotationField;
            const index = Number(event.target.dataset.quotationIndex);

            if (!field || !lines[index]) {
                return;
            }

            lines[index][field] = event.target.value;
            rowsBody.querySelector('[data-quotation-amount="' + index + '"]').textContent = money(amountOf(lines[index]));
            rowsBody.querySelector('[data-quotation-note="' + index + '"]').textContent = stockNote(lines[index]);
            announce();
        });

        rowsBody.addEventListener("click", function (event) {
            const remove = event.target.closest("[data-quotation-remove]");

            if (remove) {
                lines.splice(Number(remove.dataset.quotationRemove), 1);
                render();
            }
        });

        root.querySelectorAll("[data-quotation-add]").forEach(function (button) {
            button.addEventListener("click", function () {
                lines.push({ kind: button.dataset.quotationAdd, description: "", unit: "", quantity: "1", unit_price: "", material: null });
                render();
                rowsBody.querySelector('[data-quotation-index="' + (lines.length - 1) + '"]').focus();
            });
        });

        if (window.materialPicker && search) {
            window.materialPicker.attach(search, {
                url: root.dataset.materialUrl,
                exclude: function () {
                    return lines
                        .filter(function (line) {
                            return line.material;
                        })
                        .map(function (line) {
                            return line.material.material_id;
                        });
                },
                onPick: function (material) {
                    lines.push({
                        kind: "material",
                        description: "",
                        unit: "",
                        quantity: material.allows_fractions ? "" : "1",
                        unit_price: material.unit_price === null || material.unit_price === undefined ? "" : String(material.unit_price),
                        material: material,
                    });
                    search.value = "";
                    render();
                },
            });
        }

        render();
    }

    document.querySelectorAll("[data-quotation-lines]").forEach(setUp);
});
