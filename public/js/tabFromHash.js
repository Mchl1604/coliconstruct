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
     * The tab button that owns a pane, matched by reading each toggle's target
     * rather than by building a selector around the hash: a fragment is not a
     * CSS identifier, and one spliced into a selector is a way to be wrong
     * about a page's own markup.
     */
    function toggleForPane(paneId) {
        const toggles = document.querySelectorAll('[data-bs-toggle="tab"]');

        for (const toggle of toggles) {
            const target =
                toggle.getAttribute("data-bs-target") ||
                toggle.getAttribute("href");

            if (target === "#" + paneId) {
                return toggle;
            }
        }

        return null;
    }

    /**
     * What has to be opened for the hash to be visible, outermost first.
     *
     * A hash names one of three things, and all three end up here:
     *
     *   - a tab pane, which is opened;
     *   - an element INSIDE a tab pane - the phases panel, the registered user
     *     card - in which case the pane holding it is opened and the element
     *     is scrolled to;
     *   - something on the page that is not in a tab at all, which is left to
     *     the browser.
     *
     * Nested tabs are why this returns a list. The project details page has a
     * Tasks tab inside a Project Activity tab, and opening the inner one while
     * the outer is closed shows nobody anything.
     */
    function tabsFor(hash) {
        if (!hash || hash === "#") {
            return [];
        }

        const element = document.getElementById(hash.slice(1));

        if (!element) {
            return [];
        }

        const chain = [];

        // Start at the element itself when it IS a pane, so a hash naming a
        // pane opens that pane as well as any pane around it.
        let pane = element.classList.contains("tab-pane")
            ? element
            : element.closest(".tab-pane");

        while (pane) {
            const toggle = toggleForPane(pane.id);

            if (toggle) {
                chain.unshift(toggle);
            }

            pane = pane.parentElement
                ? pane.parentElement.closest(".tab-pane")
                : null;
        }

        return chain;
    }

    function tabFor(hash) {
        const chain = tabsFor(hash);

        return chain.length ? chain[chain.length - 1] : null;
    }

    function show(hash) {
        const chain = tabsFor(hash);

        if (!chain.length) {
            return;
        }

        // Outermost first: Bootstrap will not show a pane whose own container
        // is hidden.
        chain.forEach(function (tab) {
            window.bootstrap.Tab.getOrCreateInstance(tab).show();
        });

        const element = document.getElementById(hash.slice(1));
        const innermost = chain[chain.length - 1];

        // A hash naming a pane scrolls to the tab strip: opening a tab and
        // landing halfway down its content reads as a broken jump rather than
        // a switch. A hash naming something inside a pane scrolls to that,
        // because it is what the reader was sent to look at.
        const destination =
            element && !element.classList.contains("tab-pane")
                ? element
                : innermost;

        destination.scrollIntoView({ block: "center", behavior: "smooth" });
    }

    if (tabFor(window.location.hash)) {
        show(window.location.hash);
    }

    document.addEventListener("click", function (event) {
        const link = event.target.closest('a[href*="#"]');

        // Already taken by another script - a page of the activity log that
        // Project Details redraws in place, say (projectWorkspace.js), which
        // brings the reader to the section itself once it has.
        if (!link || event.defaultPrevented) {
            return;
        }

        // Only links pointing at this same page: one to another project's tab
        // is an ordinary navigation and has to be left alone.
        const target = new URL(link.href, window.location.href);

        if (target.pathname !== window.location.pathname) {
            return;
        }

        if (!tabFor(target.hash)) {
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
                        show(target.hash);
                    },
                    { once: true },
                );

                window.history.replaceState(null, "", target.hash);
                instance.hide();

                return;
            }
        }

        window.history.replaceState(null, "", target.hash);
        show(target.hash);
    });
});
