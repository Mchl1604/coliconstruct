/**
 * Configuration -> System Settings: the sidebar.
 *
 * Each category is a button with its setting types folded away beneath it.
 * Choosing a category shows its pane and slides its types open, folding the
 * other category's away, so the sidebar only ever lists the types of the
 * category on screen. Choosing the open category again folds or unfolds its
 * list without leaving it.
 *
 * Which type is chosen is not this file's business: each editor owns its own
 * list and redraws its own fields (systemContents.js). This file only decides
 * which category is open.
 */
document.addEventListener('DOMContentLoaded', function() {
    const nav = document.querySelector('[data-settings-nav]');

    if (!nav) {
        return;
    }

    const categories = Array.from(nav.querySelectorAll('[data-settings-category]'));

    // Below this width the sidebar sits above the content rather than beside
    // it, so a chosen type is out of sight until the page is scrolled to it.
    // Kept in step with the breakpoint in configuration.css.
    const stacked = window.matchMedia('(max-width: 991.98px)');

    function menuFor(button) {
        return document.getElementById(button.getAttribute('aria-controls'));
    }

    function paneFor(button) {
        return document.getElementById(button.dataset.settingsCategory);
    }

    /**
     * Folds or unfolds a category's list. Bootstrap's Collapse does the
     * sliding, and honours prefers-reduced-motion; without it the list simply
     * appears.
     */
    function setOpen(button, open) {
        const menu = menuFor(button);

        button.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (!menu) {
            return;
        }

        if (window.bootstrap?.Collapse) {
            const collapse = window.bootstrap.Collapse.getOrCreateInstance(menu, { toggle: false });

            if (open) {
                collapse.show();
            } else {
                collapse.hide();
            }

            return;
        }

        menu.classList.toggle('show', open);
    }

    function showCategory(chosen) {
        categories.forEach(function(button) {
            const isChosen = button === chosen;
            const pane = paneFor(button);

            button.classList.toggle('active', isChosen);

            if (isChosen) {
                button.setAttribute('aria-current', 'true');
            } else {
                button.removeAttribute('aria-current');
            }

            setOpen(button, isChosen);

            if (!pane) {
                return;
            }

            if (isChosen) {
                pane.classList.add('active');
                // Read a size first, so the fade has a start to run from - the
                // same reflow Bootstrap's own tabs force. Not a timer or an
                // animation frame: either can be held back while the page is
                // not being drawn, leaving the pane invisible.
                void pane.offsetWidth;
                pane.classList.add('show');
            } else {
                pane.classList.remove('active', 'show');
            }
        });

        reveal(paneFor(chosen), false);
    }

    /**
     * Brings a pane's top into view. The categories are not the same length,
     * so a switch made far down a long one can leave the window scrolled past
     * the end of a short one. `always` is for the stacked layout, where the
     * pane sits below the sidebar and is out of sight even when nothing has
     * been scrolled.
     */
    function reveal(pane, always) {
        if (!pane) {
            return;
        }

        const topbar = document.querySelector('.admin-topbar');
        const clear = topbar ? topbar.getBoundingClientRect().bottom : 0;

        if (always || pane.getBoundingClientRect().top < clear) {
            pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    categories.forEach(function(button) {
        button.addEventListener('click', function() {
            if (button.classList.contains('active')) {
                setOpen(button, button.getAttribute('aria-expanded') !== 'true');

                return;
            }

            showCategory(button);
        });
    });

    // A type chosen from a list. Its editor has already taken the click; what
    // is left here is making sure its category is the one on screen, and that
    // the fields it is about to draw are where the eye can find them.
    nav.addEventListener('click', function(event) {
        const type = event.target.closest('[data-content-section]');
        const category = type?.closest('.settings-sidebar-group')?.querySelector('[data-settings-category]');

        if (!category) {
            return;
        }

        if (!category.classList.contains('active')) {
            showCategory(category);
        }

        reveal(paneFor(category), stacked.matches);
    });
});
