/**
 * The Admin / Super Admin dashboard.
 *
 * Almost nothing happens here. Every number and every shortcut on the page is
 * server-rendered, so the page is readable and usable before this file runs.
 * What is left is the clock and a refresh of the figures when the tab is
 * brought back to the front.
 */
document.addEventListener('DOMContentLoaded', function() {
    const page = document.querySelector('.dashboard-page');

    if (!page) {
        return;
    }

    const data = window.dashboardData || {};

    // ------------------------------------------------------------------
    // The clock
    // ------------------------------------------------------------------

    const clock = document.querySelector('[data-dashboard-clock]');

    if (clock) {
        window.setInterval(function() {
            clock.textContent = new Date().toLocaleTimeString([], {
                hour: 'numeric',
                minute: '2-digit',
            });
        }, 30000);
    }

    // ------------------------------------------------------------------
    // Refreshing the figures
    // ------------------------------------------------------------------

    /**
     * Re-read the figures without a reload, and pulse the ones that moved.
     *
     * Called when the tab is brought back to the front, which is when a
     * dashboard left open in another window is most likely to be stale.
     */
    function refreshSummary() {
        if (!data.summaryUrl) {
            return;
        }

        fetch(data.summaryUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            .then(function(response) {
                return response.ok ? response.json() : null;
            })
            .then(function(payload) {
                if (!payload) {
                    return;
                }

                (payload.cards || []).forEach(function(card) {
                    const holder = document.querySelector('[data-stat-key="' + card.key + '"]');
                    const value = holder && holder.querySelector('[data-stat-value]');

                    if (!value || value.textContent.trim() === String(card.value)) {
                        return;
                    }

                    value.textContent = card.value;
                    value.classList.remove('is-updated');
                    // Reading offsetWidth restarts the animation; without it a
                    // second change in the same session would not replay.
                    void value.offsetWidth;
                    value.classList.add('is-updated');
                });
            })
            .catch(function() {
                // A failed refresh leaves the figures as they were, which is
                // not worth interrupting the page for.
            });
    }

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            refreshSummary();
        }
    });
});
