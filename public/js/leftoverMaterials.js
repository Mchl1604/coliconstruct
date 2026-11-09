/**
 * Complete Project with materials still on site.
 *
 * The Complete button opens the Leftover Materials dialog first. The page's
 * own Materials card is moved into it while it is open, so its buttons work
 * as they do on the tab - except Add/Request Material, which the dialog's
 * own style hides. A button that opens one of the card's
 * dialogs closes this one first, puts the card back, then opens that dialog -
 * Bootstrap does not stack dialogs. When that dialog is cancelled, or after
 * its save redraws the page, the flow comes back here; once nothing is left
 * on site the Service Report form opens instead.
 *
 * Continue opens the Service Report form, the dialog Complete would have
 * opened without leftovers.
 */
(function () {
    "use strict";

    const MODAL = "[data-leftover-materials-modal]";
    const CARD = "[data-project-materials]";
    const KEY = "leftovers:" + window.location.pathname;
    const FRESH_MS = 5 * 60 * 1000;

    let next = null;
    let pendingTrigger = null;
    let placeholder = null;

    function modal() {
        return document.querySelector(MODAL);
    }

    function startFlow() {
        try {
            window.sessionStorage.setItem(KEY, String(Date.now()));
        } catch (error) {
            // Storage off: the flow just does not come back after a save.
        }
    }

    function endFlow() {
        try {
            window.sessionStorage.removeItem(KEY);
        } catch (error) {
            // Nothing to clear.
        }
    }

    function inFlow() {
        try {
            const started = Number(window.sessionStorage.getItem(KEY));

            return started > 0 && Date.now() - started < FRESH_MS;
        } catch (error) {
            return false;
        }
    }

    function show(element, trigger) {
        if (element && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(element).show(trigger);
        }
    }

    /** Back to this dialog, or on to the form when nothing is left. */
    function resume() {
        if (!inFlow()) {
            return;
        }

        const leftovers = modal();

        if (leftovers) {
            show(leftovers);

            return;
        }

        endFlow();

        const complete = document.querySelector("[data-project-complete]:not([disabled])");

        if (complete) {
            complete.click();
        }
    }

    document.addEventListener("show.bs.modal", function (event) {
        if (!event.target.matches || !event.target.matches(MODAL)) {
            return;
        }

        startFlow();
        next = null;

        const card = document.querySelector(CARD);
        const slot = event.target.querySelector("[data-leftover-slot]");

        if (card && slot && !slot.contains(card)) {
            placeholder = document.createComment("project-materials");
            card.parentNode.insertBefore(placeholder, card);
            slot.appendChild(card);
        }
    });

    document.addEventListener("hidden.bs.modal", function (event) {
        const target = event.target;

        if (!target.matches) {
            return;
        }

        if (target.matches(MODAL)) {
            const card = target.querySelector(CARD);

            if (card && placeholder && placeholder.parentNode) {
                placeholder.parentNode.replaceChild(card, placeholder);
            }

            placeholder = null;

            if (next === "continue") {
                endFlow();
                const button = target.querySelector("[data-leftover-continue]");
                show(document.querySelector(button.dataset.continueTarget), button);
            } else if (next === "action" && pendingTrigger) {
                const trigger = pendingTrigger;
                pendingTrigger = null;
                trigger.click();
            } else {
                endFlow();
            }

            next = null;

            return;
        }

        // One of the card's dialogs, opened from here. Cancelled: come back.
        // Sent: the save's redraw or reload comes back instead.
        if (inFlow() && target.dataset.leftoverSubmitting !== "1") {
            window.setTimeout(resume, 0);
        }

        delete target.dataset.leftoverSubmitting;
    });

    document.addEventListener("click", function (event) {
        const continueButton = event.target.closest("[data-leftover-continue]");

        if (continueButton) {
            next = "continue";
            window.bootstrap.Modal.getInstance(modal()).hide();

            return;
        }

        const trigger = event.target.closest("[data-leftover-slot] [data-bs-toggle='modal'], [data-leftover-slot] [data-material-void-url]");

        if (!trigger) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        next = "action";
        pendingTrigger = trigger;
        window.bootstrap.Modal.getInstance(modal()).hide();
    }, true);

    document.addEventListener("submit", function (event) {
        const dialog = event.target.closest && event.target.closest(".modal");

        if (inFlow() && dialog && !dialog.matches(MODAL)) {
            dialog.dataset.leftoverSubmitting = "1";
        }
    }, true);

    // A refused save leaves its dialog open; closing it should come back.
    document.addEventListener("workspace:failed", function (event) {
        const dialog = event.target.closest && event.target.closest(".modal");

        if (dialog) {
            delete dialog.dataset.leftoverSubmitting;
        }
    }, true);

    document.addEventListener("workspace:updated", resume);
    document.addEventListener("DOMContentLoaded", function () {
        window.setTimeout(resume, 0);
    });
})();
