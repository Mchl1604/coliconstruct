/**
 * The confirmation dialog, for pages outside Configuration.
 *
 * Replaces window.confirm(), which this application used to ask three of its
 * most consequential questions with: replacing a project's lead technician,
 * removing an uploaded document, and rewriting dates that have already been
 * worked. A browser dialog cannot be styled, cannot separate the question from
 * its consequence, and looks like something the page did by accident - which
 * is the wrong shape for a decision somebody cannot take back.
 *
 * Two ways to use it.
 *
 * As a gate, which is what a synchronous window.confirm() becomes:
 *
 *     if (!(await confirmDialog({ title, body, label }))) {
 *         return;
 *     }
 *
 * Or handing it the request as well, so a refusal is rendered inside the
 * dialog rather than somewhere the reader has already scrolled past:
 *
 *     confirmDialog({
 *         title, body, label,
 *         onConfirm: () => fetch(...).then(...),   // resolves { ok, body }
 *     }).then(body => { ... });                    // false if they backed out
 *
 * Resolves false if the person left by any route - Cancel, the close cross,
 * Escape, or the backdrop. Requires <x-confirm-dialog /> on the page; without
 * it every question answers false, because a missing dialog is not a reason to
 * do something destructive unasked.
 */
(function () {
    let modalEl = null;
    let instance = null;

    /** The resolver for the question on screen, if any. */
    let settle = null;

    /** The request the dialog is running, when it was given one. */
    let handler = null;

    function parts() {
        // Still the dialog on the page. Project Details redraws its content
        // after a save (see projectWorkspace.js), dialog included, and the
        // one held from before is then detached.
        if (modalEl && modalEl.isConnected) {
            return true;
        }

        modalEl = document.querySelector("[data-app-confirm]");

        if (!modalEl || !window.bootstrap) {
            return false;
        }

        instance = window.bootstrap.Modal.getOrCreateInstance(modalEl);

        // Every exit that is not the accept button. It fires on the accept
        // route too, which is why answer() clears the resolver before the hide
        // rather than after it.
        modalEl.addEventListener("hidden.bs.modal", function () {
            answer(false);
        });

        modalEl
            .querySelector("[data-app-confirm-accept]")
            .addEventListener("click", accept);

        return true;
    }

    function el(name) {
        return modalEl.querySelector("[data-app-confirm-" + name + "]");
    }

    function answer(value) {
        if (!settle) {
            return;
        }

        const resolve = settle;

        settle = null;
        handler = null;
        resolve(value);
    }

    function busy(on) {
        el("accept").disabled = on;
        el("cancel").disabled = on;
        el("spinner").classList.toggle("d-none", !on);
    }

    function showError(message) {
        const box = el("error");

        box.textContent = message || "";
        box.classList.toggle("d-none", !message);
    }

    function accept() {
        // A plain gate: the caller does the work, so the dialog's job is over
        // the moment it is answered.
        if (!handler) {
            answer(true);
            instance.hide();

            return;
        }

        showError("");
        busy(true);

        Promise.resolve()
            .then(handler)
            .then(function (result) {
                busy(false);

                // The action was refused. The dialog stays open with the
                // reason in it, which is the whole point of asking here rather
                // than in a browser dialog - the person is still looking at
                // the thing they asked about.
                if (!result || !result.ok) {
                    showError(
                        (result && result.body && result.body.error) ||
                            "Unable to complete that action.",
                    );

                    return;
                }

                const value = result.body === undefined ? true : result.body;

                answer(value);
                instance.hide();
            })
            .catch(function (error) {
                busy(false);
                showError(error.message || "Unable to complete that action.");
            });
    }

    window.confirmDialog = function (options) {
        const config = options || {};

        if (!parts()) {
            return Promise.resolve(false);
        }

        el("title").textContent = config.title || "Are you sure?";
        el("body").textContent = config.body || "";
        el("label").textContent = config.label || "Confirm";

        const detail = el("detail");

        detail.textContent = config.detail || "";
        detail.classList.toggle("d-none", !config.detail);

        const variant = config.variant === "primary" ? "primary" : "danger";

        // Rebuilt rather than toggled, because the class carries the colour and
        // the button is reused for every question this page asks.
        el("accept").className = "btn px-3 btn-" + variant;
        el("header").className =
            "modal-header " +
            (variant === "danger" ? "bg-danger text-white" : "");

        modalEl
            .querySelector(".btn-close")
            .classList.toggle("btn-close-white", variant === "danger");

        showError("");
        busy(false);

        return new Promise(function (resolve) {
            // A second question asked while one is open answers the first with
            // "no" rather than dropping its promise on the floor.
            answer(false);

            settle = resolve;
            handler = typeof config.onConfirm === "function" ? config.onConfirm : null;

            instance.show();
        });
    };
})();
