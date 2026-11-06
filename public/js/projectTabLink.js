/**
 * Links into a project's page. A link may name a tab or something inside one,
 * such as .../projects/5#project-materials, and #complete starts Complete
 * Project. Tabs inside tabs are opened from the outside in.
 */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    function open(hash) {
        if (hash === "#complete") {
            const button = document.querySelector("[data-project-complete]:not([disabled])");

            if (button) {
                button.click();
            }

            return;
        }

        const target = hash.length > 1 ? document.getElementById(hash.slice(1)) : null;

        if (!target) {
            return;
        }

        const tabs = [];
        let pane = target.closest(".tab-pane");

        while (pane) {
            const tab = Array.from(document.querySelectorAll('[data-bs-toggle="tab"]')).find(function (button) {
                return button.dataset.bsTarget === "#" + pane.id;
            });

            if (tab) {
                tabs.unshift(tab);
            }

            pane = pane.parentElement.closest(".tab-pane");
        }

        tabs.forEach(function (tab) {
            bootstrap.Tab.getOrCreateInstance(tab).show();
        });

        target.scrollIntoView({ block: "start" });
    }

    open(window.location.hash);

    // A link from the Cannot Complete Yet dialog stays on the same page.
    document.addEventListener("click", function (event) {
        const link = event.target.closest("[data-tab-link]");

        if (!link || link.pathname !== window.location.pathname) {
            return;
        }

        const dialog = link.closest(".modal");

        event.preventDefault();

        if (dialog) {
            bootstrap.Modal.getOrCreateInstance(dialog).hide();
        }

        open(link.hash);
    });
});
