/**
 * The My Projects filter bar and search box.
 *
 * A client's list is small and already rendered, so both narrowings run in the
 * browser: no reload, and the two combine - Ongoing plus "residential" leaves
 * only the ongoing residential work.
 *
 * The chosen status survives leaving the page and coming back, which is what
 * following one project out of a filtered list and returning normally means.
 */
document.addEventListener('DOMContentLoaded', function() {
    const grid = document.querySelector('[data-project-grid]');

    if (!grid) {
        return;
    }

    const STORAGE_KEY = 'coliconstruct.myProjects.status';

    const tabs = Array.prototype.slice.call(document.querySelectorAll('[data-project-filter]'));
    const searchInput = document.querySelector('[data-project-search]');
    const emptyState = document.querySelector('[data-project-empty]');
    const cards = Array.prototype.slice.call(grid.querySelectorAll('[data-project-card]'));
    const countBadge = document.querySelector('[data-project-count]');

    let status = 'all';
    let term = '';

    function setActiveTab(value) {
        tabs.forEach(function(tab) {
            const isActive = tab.dataset.projectFilter === value;

            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-pressed', String(isActive));
        });
    }

    function apply() {
        let visible = 0;

        cards.forEach(function(card) {
            const matchesStatus = status === 'all' || card.dataset.status === status;
            const matchesTerm = term === '' || (card.dataset.search || '').indexOf(term) !== -1;
            const shown = matchesStatus && matchesTerm;

            card.classList.toggle('d-none', !shown);

            if (shown) {
                visible += 1;
            }
        });

        if (emptyState) {
            emptyState.classList.toggle('d-none', visible > 0);
        }

        // The heading's count follows the view rather than the whole list, so
        // it never contradicts what is on screen.
        if (countBadge) {
            countBadge.textContent = visible + ' ' + (visible === 1 ? 'project' : 'projects');
        }
    }

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            status = tab.dataset.projectFilter;

            setActiveTab(status);
            apply();

            try {
                window.sessionStorage.setItem(STORAGE_KEY, status);
            } catch (error) {
                // Private browsing can refuse storage; the filter still works
                // for as long as the page is open.
            }
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            term = searchInput.value.trim().toLowerCase();
            apply();
        });
    }

    let remembered = null;

    try {
        remembered = window.sessionStorage.getItem(STORAGE_KEY);
    } catch (error) {
        remembered = null;
    }

    // Only restore a tab that still exists - a remembered value from an older
    // version of this bar must not leave every card hidden.
    if (remembered && tabs.some(function(tab) {
            return tab.dataset.projectFilter === remembered;
        })) {
        status = remembered;
        setActiveTab(status);
    }

    apply();
});
