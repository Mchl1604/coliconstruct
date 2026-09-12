/**
 * Where the reader was on a page, kept across the reloads the page cannot
 * avoid.
 *
 * Most saves in the staff portals are ordinary form posts. The server answers
 * with a redirect back to the same page, and the browser draws it again from
 * scratch: on its first tab, scrolled to the top, however far into the page
 * the person had been working. Edit a task on the Tasks tab of a project and
 * you came back to Project Information.
 *
 * So the page notes, as it is left, which tab panes were open - inner ones
 * included - and how far down it was scrolled, and puts both back when the
 * same page is drawn again. Per browser tab (sessionStorage), and only for the
 * page that was just left: a reload, or a form that posted and was sent back.
 * Arriving from anywhere else opens the page the way it always opened.
 *
 * Two things win over what was remembered:
 *
 *   - a #fragment naming something on the page. That is where a link meant
 *     to send the reader - tabFromHash.js, or the browser itself, handles it;
 *   - a page that opens a tab of its own accord while it loads, such as
 *     Configuration opening Inquiries for a notification. Whatever the page
 *     decided about itself is left alone.
 *
 * The scroll position is not put back over an error: a refused save that
 * prints its reason at the top of the page has to be seen.
 *
 * Loaded by both staff layouts, after Bootstrap and before any page script, so
 * the tabs it finds when it runs are the ones the server drew.
 */
(function (global) {
    'use strict';

    const KEY = 'pageMemory';

    // Long enough for a slow upload to come back; short enough that coming
    // back to a page after lunch is a fresh visit.
    const MAX_AGE = 10 * 60 * 1000;

    const TOGGLES = '[data-bs-toggle="tab"], [data-bs-toggle="pill"]';

    function paneId(toggle) {
        const target = toggle.getAttribute('data-bs-target') || toggle.getAttribute('href') || '';

        return target.charAt(0) === '#' && target.length > 1 ? target.slice(1) : null;
    }

    /**
     * The panes open under `root`, outermost first - document order puts an
     * outer tab strip before the strips inside its panes, and that is the
     * order they have to be opened in.
     */
    function activeTabs(root) {
        return Array.prototype.filter
            .call((root || document).querySelectorAll(TOGGLES), function (toggle) {
                return toggle.classList.contains('active');
            })
            .map(paneId)
            .filter(Boolean);
    }

    function toggleFor(id) {
        return Array.prototype.find.call(document.querySelectorAll(TOGGLES), function (toggle) {
            return paneId(toggle) === id;
        }) || null;
    }

    function storage() {
        try {
            return global.sessionStorage || null;
        } catch (error) {
            return null;
        }
    }

    function save() {
        const store = storage();

        if (!store) {
            return;
        }

        try {
            store.setItem(KEY, JSON.stringify({
                path: global.location.pathname,
                tabs: activeTabs(document),
                scrollY: Math.round(global.scrollY || 0),
                at: Date.now(),
            }));
        } catch (error) {
            // Full or refused: the page simply opens fresh next time.
        }
    }

    /** What was remembered about this page, used once. */
    function take() {
        const store = storage();

        if (!store) {
            return null;
        }

        let saved = null;

        try {
            saved = JSON.parse(store.getItem(KEY) || 'null');
            store.removeItem(KEY);
        } catch (error) {
            return null;
        }

        if (!saved || saved.path !== global.location.pathname) {
            return null;
        }

        if (!saved.at || Date.now() - saved.at > MAX_AGE) {
            return null;
        }

        return saved;
    }

    function hashNamesSomething() {
        const hash = global.location.hash;

        if (!hash || hash === '#') {
            return false;
        }

        try {
            return Boolean(document.getElementById(decodeURIComponent(hash.slice(1))));
        } catch (error) {
            return false;
        }
    }

    function showsAnError() {
        return Boolean(document.querySelector('.alert-danger:not(.d-none), .is-invalid'));
    }

    function restoreTabs(ids) {
        if (!global.bootstrap) {
            return;
        }

        (ids || []).forEach(function (id) {
            const toggle = toggleFor(id);

            if (toggle && !toggle.classList.contains('active')) {
                global.bootstrap.Tab.getOrCreateInstance(toggle).show();
            }
        });
    }

    /**
     * Scrolled once the tabs are open, and once more when the page has
     * finished loading - images arriving late make it taller, and the first
     * attempt may have been cut short at the old bottom. Not the second time
     * if the reader has already started scrolling themselves.
     */
    function restoreScroll(y) {
        if (!y) {
            return;
        }

        let moved = false;

        function markMoved() {
            moved = true;
        }

        // Instant, not the page's default: Bootstrap makes the root scroll
        // smoothly, and gliding down from the top after every save is the
        // jump this is meant to hide.
        function jump() {
            global.scrollTo({ top: y, left: global.scrollX, behavior: 'instant' });
        }

        ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(function (name) {
            global.addEventListener(name, markMoved, { once: true, passive: true });
        });

        jump();

        if (document.readyState === 'complete') {
            return;
        }

        global.addEventListener('load', function () {
            if (!moved) {
                jump();
            }
        }, { once: true });
    }

    // Read now: this runs at the foot of the body, after the markup and
    // before any page script, so these are the tabs the server drew.
    const drawn = activeTabs(document).join(',');

    global.addEventListener('pagehide', save);

    document.addEventListener('DOMContentLoaded', function () {
        const saved = take();

        if (!saved) {
            return;
        }

        // After every other DOMContentLoaded handler, so the panes that load
        // their contents when shown have their listeners in place, and so a
        // page that opened a tab itself has already done so.
        setTimeout(function () {
            if (hashNamesSomething()) {
                return;
            }

            if (activeTabs(document).join(',') === drawn) {
                restoreTabs(saved.tabs);
            }

            if (!showsAnError()) {
                restoreScroll(saved.scrollY);
            }
        }, 0);
    });

    global.pageMemory = {
        activeTabs: activeTabs,
    };
})(window);
