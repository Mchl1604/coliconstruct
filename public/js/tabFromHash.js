/**
 * Turns a #fragment into a Bootstrap tab.
 *
 * The panes on the project pages are tabs rather than sections, so a link to
 * one of them - "Go to the open tasks" on a completion blocker, a notification
 * that lands on the reports - arrives at a hash the browser has nothing to
 * scroll to, and the page opens on whichever tab was active anyway.
 *
 * Two ways in, because a blocker link is followed from both:
 *
 *   - from another page, where the hash is already in the address bar at load;
 *   - from a dialog on this one, where the link points at the page it is
 *     already on and nothing would happen at all.
 */
document.addEventListener("DOMContentLoaded", function () {
    if (!window.bootstrap) {
        return;
    }

    /**
     * The tab button that owns a pane, or null when the hash names something
     * that is not a tab (an ordinary anchor, or nothing on this page).
     *
     * Matched by reading each toggle's target rather than by building a
     * selector around the hash: a fragment is not a CSS identifier, and one
     * spliced into a selector is a way to be wrong about a page's own markup.
     */
    function tabFor(hash) {
        if (!hash || hash === "#") {
            return null;
        }

        const toggles = document.querySelectorAll('[data-bs-toggle="tab"]');

        for (const toggle of toggles) {
            const target =
                toggle.getAttribute("data-bs-target") ||
                toggle.getAttribute("href");

            if (target === hash) {
                return toggle;
            }
        }

        return null;
    }

    function show(tab) {
        window.bootstrap.Tab.getOrCreateInstance(tab).show();

        // The tab strip, not the pane: opening a tab and landing halfway down
        // its content reads as a broken jump rather than a switch.
        tab.scrollIntoView({ block: "center", behavior: "smooth" });
    }

    const atLoad = tabFor(window.location.hash);

    if (atLoad) {
        show(atLoad);
    }

    document.addEventListener("click", function (event) {
        const link = event.target.closest('a[href*="#"]');

        if (!link) {
            return;
        }

        // Only links pointing at this same page: one to another project's tab
        // is an ordinary navigation and has to be left alone.
        const target = new URL(link.href, window.location.href);

        if (target.pathname !== window.location.pathname) {
            return;
        }

        const tab = tabFor(target.hash);

        if (!tab) {
            return;
        }

        event.preventDefault();

        // The link is usually inside the dialog that refused the completion,
        // and a tab opening behind an open modal is a tab nobody can see.
        const modal = link.closest(".modal");

        if (modal) {
            const instance = window.bootstrap.Modal.getInstance(modal);

            if (instance) {
                modal.addEventListener(
                    "hidden.bs.modal",
                    function () {
                        show(tab);
                    },
                    { once: true },
                );

                window.history.replaceState(null, "", target.hash);
                instance.hide();

                return;
            }
        }

        window.history.replaceState(null, "", target.hash);
        show(tab);
    });
});
