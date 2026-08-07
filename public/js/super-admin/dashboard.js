/**
 * The Admin / Super Admin dashboard.
 *
 * Almost nothing happens here. Every number on the page is server-rendered,
 * including the ones in the ring's legend, so the page is readable before this
 * file runs. What is left is the clock, the ring itself - decoration over
 * numbers that are already on screen - and a refresh of the figures when the
 * tab is brought back to the front.
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
    // The ring
    // ------------------------------------------------------------------

    const canvas = document.querySelector('[data-status-ring]');
    const slices = data.ring || [];

    if (canvas && window.Chart && slices.length) {
        new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: slices.map(function(slice) {
                    return slice.label;
                }),
                datasets: [{
                    data: slices.map(function(slice) {
                        return slice.value;
                    }),
                    backgroundColor: slices.map(function(slice) {
                        return slice.colour;
                    }),
                    borderWidth: 0,
                    // Rounded ends and a gap between arcs, so the ring reads as
                    // separate strokes rather than one sliced disc.
                    borderRadius: 12,
                    spacing: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '74%',
                plugins: {
                    // The legend beside it already names every slice.
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                const slice = slices[context.dataIndex] || {};

                                return slice.value + ' (' + slice.percent + '%)';
                            },
                        },
                    },
                },
            },
        });
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
