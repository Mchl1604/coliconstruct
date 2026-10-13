/**
 * Project Details' Change Client dialog.
 *
 * Existing client shows the client search (accountPicker.js, searching on the
 * server); New client shows the wizard's client fields. The half that is not
 * showing is disabled, so it is neither submitted nor held to its `required`
 * fields. The company field appears for a Commercial client only. The server
 * checks all of it again - see ChangeProjectClientRequest.
 *
 * Bound again on `workspace:updated`, because a save on this page replaces
 * the dialog's markup - see projectWorkspace.js.
 */
(function () {
    "use strict";

    function setUp(root) {
        const form = root.querySelector("[data-change-client-form]");

        if (!form || form.dataset.changeClientReady) {
            return;
        }

        form.dataset.changeClientReady = "1";

        const modes = Array.from(form.querySelectorAll("[data-change-client-mode]"));
        const existingBlock = form.querySelector("[data-change-client-existing]");
        const newBlock = form.querySelector("[data-change-client-new]");
        const typeSelect = form.querySelector("[data-change-client-type]");
        const companyBlock = form.querySelector("[data-change-client-company]");
        const pickedId = existingBlock ? existingBlock.querySelector("[data-picker-id]") : null;
        const submit = form.querySelector("[data-change-client-submit]");

        function usesExisting() {
            const checked = modes.find(function (radio) {
                return radio.checked;
            });

            return !checked || checked.value === "existing";
        }

        function setEnabled(block, enabled) {
            if (!block) {
                return;
            }

            block.hidden = !enabled;
            block.querySelectorAll("input, select, textarea").forEach(function (field) {
                field.disabled = !enabled;
            });
        }

        function applyCompany() {
            const commercial = typeSelect && typeSelect.value === "Commercial";

            if (companyBlock) {
                setEnabled(companyBlock, !usesExisting() && commercial);
                companyBlock.querySelector("input").required = commercial;
            }
        }

        function applySubmit() {
            if (submit) {
                // Nothing to move the project to until somebody is picked.
                submit.disabled = usesExisting() && !(pickedId && pickedId.value);
            }
        }

        function apply() {
            const existing = usesExisting();

            setEnabled(existingBlock, existing);
            setEnabled(newBlock, !existing);
            applyCompany();
            applySubmit();
        }

        modes.forEach(function (radio) {
            radio.addEventListener("change", apply);
        });

        if (typeSelect) {
            typeSelect.addEventListener("change", applyCompany);
        }

        if (existingBlock) {
            existingBlock.addEventListener("account-picker:chosen", applySubmit);
            existingBlock.addEventListener("account-picker:cleared", applySubmit);
        }

        apply();
    }

    document.addEventListener("DOMContentLoaded", function () {
        setUp(document);
    });

    document.addEventListener("workspace:updated", function (event) {
        setUp(event.detail.root);
    });
})();
