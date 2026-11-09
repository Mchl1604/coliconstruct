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

    function peso(value) {
        return "\u20B1 " + money(value);
    }

    function amountOf(line) {
        const amount = Number(line.quantity) * Number(line.unit_price);

        return isFinite(amount) ? Math.round(amount * 100) / 100 : 0;
    }

    function setUp(root) {
        const rowsBody = root.querySelector("[data-quotation-rows]");
        const empty = root.querySelector("[data-quotation-empty]");
        const totalText = root.querySelector("[data-quotation-total]");
        const subtotalText = root.querySelector("[data-quotation-subtotal]");
        const discountAmountText = root.querySelector("[data-quotation-discount-amount]");
        const percentInput = root.querySelector("[data-quotation-discount-percent]");
        const specialInput = root.querySelector("[data-quotation-special-discount]");
        const search = root.querySelector("[data-quotation-material-search]");
        const lines = JSON.parse(root.dataset.rows || "[]");

        // Labor is one amount: an older line with a count is folded into it.
        lines.forEach(function (line) {
            if (line.kind === "labor" && Number(line.quantity) !== 1 && line.unit_price !== "") {
                line.unit_price = String(amountOf(line));
                line.quantity = "1";
            }
        });

        function laborCount() {
            return lines.filter(function (line) {
                return line.kind === "labor";
            }).length;
        }

        // Every quotation has its labor line: it is there from the start and
        // stays. It has no unit.
        if (!laborCount()) {
            lines.unshift({ kind: "labor", description: "Labor cost", unit: "", quantity: "1", unit_price: "", material: null });
        }

        function subtotal() {
            return Math.round(
                lines.reduce(function (sum, line) {
                    return sum + amountOf(line);
                }, 0) * 100,
            ) / 100;
        }

        // The same sums as ProjectQuotation::breakdown().
        function discountAmount() {
            const percent = Math.min(Math.max(Number(percentInput ? percentInput.value : 0) || 0, 0), 100);

            return Math.round(subtotal() * percent) / 100;
        }

        function total() {
            const special = Math.max(Number(specialInput ? specialInput.value : 0) || 0, 0);

            return Math.max(Math.round((subtotal() - discountAmount() - special) * 100) / 100, 0);
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

            if (subtotalText) {
                subtotalText.textContent = money(subtotal());
                discountAmountText.textContent = money(discountAmount());
            }
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

                // Labor: the amount alone, typed where the amount shows.
                if (line.kind === "labor") {
                    const spacer = document.createElement("td");
                    const laborAmount = document.createElement("td");
                    const laborPrice = input("number", base + "[unit_price]", line.unit_price, {
                        className: "form-control form-control-sm text-end",
                        required: true,
                        min: "0",
                        step: "0.01",
                        placeholder: "Amount",
                    });
                    const laborRemove = document.createElement("td");

                    spacer.colSpan = 3;
                    spacer.appendChild(input("hidden", base + "[quantity]", "1"));
                    laborPrice.dataset.quotationField = "unit_price";
                    laborPrice.dataset.quotationIndex = String(index);
                    laborPrice.setAttribute("aria-label", "Labor amount");
                    laborAmount.appendChild(laborPrice);
                    laborAmount.dataset.quotationAmount = String(index);
                    laborAmount.dataset.quotationLabor = "";

                    if (laborCount() > 1) {
                        const remove = document.createElement("button");

                        laborRemove.className = "text-center";
                        remove.type = "button";
                        remove.className = "btn btn-sm btn-outline-danger py-1 px-2";
                        remove.title = "Remove";
                        remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
                        remove.dataset.quotationRemove = String(index);
                        laborRemove.appendChild(remove);
                    }

                    row.append(nameCell, spacer, laborAmount, laborRemove);
                    rowsBody.appendChild(row);

                    return;
                }

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
                } else if (line.kind !== "labor") {
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
                amountCell.textContent = peso(amountOf(line));

                const removeCell = document.createElement("td");
                const remove = document.createElement("button");

                removeCell.className = "text-center";
                remove.type = "button";
                remove.className = "btn btn-sm btn-outline-danger py-1 px-2";
                remove.title = "Remove";
                remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
                remove.dataset.quotationRemove = String(index);

                if (line.kind !== "labor" || laborCount() > 1) {
                    removeCell.appendChild(remove);
                }

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
            const amountCell = rowsBody.querySelector('[data-quotation-amount="' + index + '"]');

            if (!amountCell.hasAttribute("data-quotation-labor")) {
                amountCell.textContent = peso(amountOf(lines[index]));
            }

            rowsBody.querySelector('[data-quotation-note="' + index + '"]').textContent = stockNote(lines[index]);
            announce();
        });

        [percentInput, specialInput].forEach(function (field) {
            if (field) {
                field.addEventListener("input", announce);
            }
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
