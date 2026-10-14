/**
 * The Materials tab on Project Details, in both portals.
 *
 * Everything is bound on the document rather than on the tab's own markup,
 * because on the administrators' page a save redraws the tab in place (see
 * projectWorkspace.js) and listeners bound to the old markup would go with it.
 * The history table is the one thing built per element, and it is built again
 * on `workspace:updated`.
 *
 * The dialogs are ordinary forms; this only prepares them - which material,
 * which kind of entry, what unit - before they are sent.
 */
(function () {
    "use strict";

    // ------------------------------------------------------------------
    // Material History
    // ------------------------------------------------------------------

    function ensureHistoryTable() {
        const table = document.getElementById("materialEntriesTable");

        if (!table || !table.getClientRects().length || !window.jQuery || !window.serverTable) {
            return;
        }

        if (window.jQuery.fn.dataTable.isDataTable(table)) {
            window.jQuery(table).DataTable().columns.adjust();

            return;
        }

        window.serverTable(table, {
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50],
            info: false,
            order: [[0, "desc"]],
            columnDefs: [
                { targets: [4, 5, 6], orderable: false },
            ],
            language: {
                search: "",
                searchPlaceholder: "Search history...",
                emptyTable: "No entries yet.",
            },
        });
    }

    document.addEventListener("shown.bs.tab", ensureHistoryTable);
    document.addEventListener("DOMContentLoaded", ensureHistoryTable);
    document.addEventListener("workspace:updated", ensureHistoryTable);

    // ------------------------------------------------------------------
    // Record Use / Record Return
    // ------------------------------------------------------------------

    function qtyRules(input, fractional) {
        input.min = fractional ? "0.01" : "1";
        input.step = fractional ? "0.01" : "1";
        input.inputMode = fractional ? "decimal" : "numeric";
    }

    function refreshRecordDialog(modal) {
        const select = modal.querySelector("[data-record-material-line]");
        const option = select.options[select.selectedIndex];
        const qty = modal.querySelector("[data-record-material-qty]");
        const unit = modal.querySelector("[data-record-material-unit]");
        const hint = modal.querySelector("[data-record-material-hint]");
        const checked = modal.querySelector("[data-record-material-type]:checked");
        const title = modal.querySelector("[data-record-material-title]");

        title.textContent = checked && checked.value === "returned" ? "Record Return" : "Record Use";

        if (!option) {
            unit.textContent = "";
            hint.textContent = "";

            return;
        }

        const onSite = Number(option.dataset.onSite || 0);

        unit.textContent = option.dataset.unit || "";
        hint.textContent = "On site: " + window.materialPicker.formatQty(onSite) + " " + (option.dataset.unit || "");
        qtyRules(qty, option.dataset.fractional === "1");
        qty.max = String(onSite);
    }

    document.addEventListener("show.bs.modal", function (event) {
        const modal = event.target;

        if (!modal.matches || !modal.matches("[data-record-material-modal]")) {
            return;
        }

        const trigger = event.relatedTarget;
        const type = (trigger && trigger.dataset.materialEntryType) || "used";
        const line = trigger && trigger.dataset.materialLine;
        const select = modal.querySelector("[data-record-material-line]");

        modal.querySelectorAll("[data-record-material-type]").forEach(function (radio) {
            radio.checked = radio.value === type;
        });

        if (line) {
            select.value = line;
        } else if (select.selectedOptions[0] && select.selectedOptions[0].disabled) {
            // The first material with something on site, rather than one
            // there is nothing left of.
            const firstOpen = Array.prototype.find.call(select.options, function (option) {
                return !option.disabled;
            });

            if (firstOpen) {
                select.value = firstOpen.value;
            }
        }

        modal.querySelector("[data-record-material-qty]").value = "";
        refreshRecordDialog(modal);
    });

    document.addEventListener("change", function (event) {
        const modal = event.target.closest && event.target.closest("[data-record-material-modal]");

        if (modal && (event.target.matches("[data-record-material-line]") || event.target.matches("[data-record-material-type]"))) {
            refreshRecordDialog(modal);
        }
    });

    // ------------------------------------------------------------------
    // Add Material
    // ------------------------------------------------------------------

    function panel() {
        return document.querySelector("[data-project-materials]");
    }

    function choose(modal, material) {
        const chosen = modal.querySelector("[data-add-material-chosen]");
        const searchWrap = modal.querySelector("[data-add-material-search-wrap]");

        modal.querySelector("[data-add-material-id]").value = material ? material.material_id : "";
        modal.querySelector("[data-add-material-submit]").disabled = !material;
        modal.querySelector("[data-add-material-new]").classList.add("d-none");

        if (!material) {
            chosen.classList.add("d-none");
            searchWrap.classList.remove("d-none");
            modal.querySelector("[data-add-material-unit]").textContent = "–";

            return;
        }

        modal.querySelector("[data-add-material-label]").textContent = material.label;
        modal.querySelector("[data-add-material-meta]").textContent = window.materialPicker.metaLine(material);
        modal.querySelector("[data-add-material-unit]").textContent = material.unit;
        qtyRules(modal.querySelector("[data-add-material-qty]"), !!material.allows_fractions);

        chosen.classList.remove("d-none");
        searchWrap.classList.add("d-none");
    }

    function openNew(modal, typedName) {
        const section = modal.querySelector("[data-add-material-new]");

        section.querySelectorAll("[data-quick-add-field]").forEach(function (field) {
            field.value = "";
        });

        section.querySelector('[data-quick-add-field="name"]').value = typedName || "";
        section.querySelector("[data-quick-add-error]").classList.add("d-none");
        section.classList.remove("d-none");
        section.querySelector('[data-quick-add-field="name"]').focus();
    }

    document.addEventListener("show.bs.modal", function (event) {
        const modal = event.target;

        if (!modal.matches || !modal.matches("[data-add-material-modal]")) {
            return;
        }

        const trigger = event.relatedTarget;
        let preset = null;

        if (trigger && trigger.dataset.materialAdd) {
            try {
                preset = JSON.parse(trigger.dataset.materialAdd);
            } catch (error) {
                preset = null;
            }
        }

        modal.querySelector("[data-add-material-qty]").value = "";
        choose(modal, preset);

        const search = modal.querySelector("[data-add-material-search]");
        const host = panel();

        search.value = "";

        if (host && host.dataset.suggestUrl) {
            window.materialPicker.attach(search, {
                url: host.dataset.suggestUrl,
                onPick: function (material) {
                    choose(modal, material);
                    modal.querySelector("[data-add-material-qty]").focus();
                },
                onAddNew: function (typedName) {
                    openNew(modal, typedName);
                },
            });
        }
    });

    // Enter in an Add to List field means "add it to the list", not "send
    // the Add Material form around it".
    document.addEventListener("keydown", function (event) {
        if (event.key !== "Enter" || !event.target.closest) {
            return;
        }

        const section = event.target.closest("[data-add-material-modal] [data-add-material-new]");

        if (section && event.target.matches("input")) {
            event.preventDefault();
            section.querySelector("[data-quick-add-save]").click();
        }
    });

    document.addEventListener("shown.bs.modal", function (event) {
        const modal = event.target;

        if (!modal.matches || !modal.matches("[data-add-material-modal]")) {
            return;
        }

        const target = modal.querySelector("[data-add-material-id]").value
            ? modal.querySelector("[data-add-material-qty]")
            : modal.querySelector("[data-add-material-search]");

        target.focus();
    });

    document.addEventListener("click", function (event) {
        const change = event.target.closest("[data-add-material-change]");

        if (change) {
            const modal = change.closest("[data-add-material-modal]");

            choose(modal, null);
            modal.querySelector("[data-add-material-search]").focus();

            return;
        }

        const cancelNew = event.target.closest("[data-add-material-new-cancel]");

        if (cancelNew) {
            cancelNew.closest("[data-add-material-new]").classList.add("d-none");

            return;
        }

        const save = event.target.closest("[data-add-material-modal] [data-quick-add-save]");

        if (save) {
            const modal = save.closest("[data-add-material-modal]");
            const section = modal.querySelector("[data-add-material-new]");
            const errorBox = section.querySelector("[data-quick-add-error]");
            const host = panel();
            const payload = {};

            section.querySelectorAll("[data-quick-add-field]").forEach(function (field) {
                payload[field.dataset.quickAddField] = field.value.trim();
            });

            save.disabled = true;

            window.materialPicker.quickAdd(host.dataset.quickAddUrl, payload).then(function (result) {
                save.disabled = false;

                if (!result.ok) {
                    errorBox.textContent = result.body.error || "Unable to add material.";
                    errorBox.classList.remove("d-none");

                    return;
                }

                choose(modal, result.body.material);
                modal.querySelector("[data-add-material-qty]").focus();
            });

            return;
        }

        // ------------------------------------------------------------------
        // Void, from a history row
        // ------------------------------------------------------------------

        const voidButton = event.target.closest("[data-material-void-url]");

        if (voidButton) {
            const modal = document.querySelector("[data-void-material-modal]");

            if (!modal || !window.bootstrap) {
                return;
            }

            modal.querySelector("[data-void-material-form]").action = voidButton.dataset.materialVoidUrl;
            modal.querySelector("[data-void-material-summary]").textContent =
                voidButton.dataset.materialVoidSummary || "";

            bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    });
})();
