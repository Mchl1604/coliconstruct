/**
 * Configuration -> System Settings: the jump links above the sections.
 *
 * Clicking one brings its section into view and flashes it, so the eye knows
 * where it landed; the pill for whatever is on screen stays lit, so the nav
 * always says where you are. Sections are found from the links themselves, so
 * adding a card to the tab means adding one anchor and nothing here.
 *
 * Nothing here assumes the window is what scrolls: scrollIntoView moves
 * whichever ancestor actually scrolls, the landing offset is CSS
 * (scroll-margin-top), and the pill is chosen from rectangles rather than from
 * a scroll position.
 */
document.addEventListener('DOMContentLoaded', function() {
    const nav = document.querySelector('[data-settings-nav]');

    if (!nav) {
        return;
    }

    const links = Array.from(nav.querySelectorAll('[data-settings-link]'));

    const sections = links
        .map(function(link) {
            return {
                link: link,
                section: document.querySelector(link.getAttribute('href')),
            };
        })
        .filter(function(pair) {
            return pair.section;
        });

    if (!sections.length) {
        return;
    }

    function markCurrent(current) {
        sections.forEach(function(pair) {
            pair.link.classList.toggle('is-current', pair.section === current);
        });
    }

    /**
     * The section that owns the screen: the last one whose top has passed
     * under the sticky nav. Falling back to the first keeps a pill lit while
     * the tab is scrolled to the very top.
     */
    function currentSection() {
        const line = nav.getBoundingClientRect().bottom + 24;
        let current = sections[0].section;

        sections.forEach(function(pair) {
            if (pair.section.getBoundingClientRect().top <= line) {
                current = pair.section;
            }
        });

        return current;
    }

    function refresh() {
        // Only worth answering while the tab is open; the pane is display:none
        // the rest of the time and every rectangle reads zero.
        if (nav.offsetParent !== null) {
            markCurrent(currentSection());
        }
    }

    sections.forEach(function(pair) {
        pair.link.addEventListener('click', function(event) {
            event.preventDefault();

            // scrollIntoView moves whatever actually scrolls; how far below the
            // sticky nav it lands is the section's own scroll-margin-top.
            pair.section.scrollIntoView({ behavior: 'smooth', block: 'start' });

            markCurrent(pair.section);

            // Restarted each time: re-adding a running animation class does
            // nothing until the class has actually been off the element.
            pair.section.classList.remove('settings-section-landed');
            void pair.section.offsetWidth;
            pair.section.classList.add('settings-section-landed');
        });
    });

    let ticking = false;

    function onScroll() {
        if (ticking) {
            return;
        }

        ticking = true;

        window.requestAnimationFrame(function() {
            refresh();
            ticking = false;
        });
    }

    // Capture phase, so a scroll inside any container is heard too - scroll
    // events do not bubble.
    document.addEventListener('scroll', onScroll, { capture: true, passive: true });
    window.addEventListener('resize', onScroll, { passive: true });

    // A second opinion that needs no scroll event at all, for anything that
    // moves the sections without one (a card growing as its list loads).
    if (window.IntersectionObserver) {
        const observer = new window.IntersectionObserver(refresh, {
            threshold: [0, 0.25, 0.5, 1],
        });

        sections.forEach(function(pair) {
            observer.observe(pair.section);
        });
    }

    // The tab starts hidden, so the first honest answer comes when it opens.
    const tabButton = document.getElementById('systemSettingsTab');

    if (tabButton) {
        tabButton.addEventListener('shown.bs.tab', refresh);
    }

    markCurrent(sections[0].section);
});
