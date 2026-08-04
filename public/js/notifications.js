/**
 * The notification bell.
 *
 * Polls /notifications/feed so the badge stays current while a page is open,
 * without a websocket. The interval is deliberately unhurried: notifications
 * are things to look at soon, not to the second, and every open tab is a
 * request.
 */
document.addEventListener('DOMContentLoaded', function() {
    const bell = document.querySelector('[data-notification-bell]');

    if (!bell) {
        return;
    }

    const routes = window.notificationRoutes || {};
    const badge = bell.querySelector('[data-notification-badge]');
    const list = bell.querySelector('[data-notification-list]');
    const markAllButton = bell.querySelector('[data-notification-mark-all]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const POLL_MS = 60000;
    let polling = null;

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function setBadge(count) {
        if (!badge) {
            return;
        }

        // Hidden at zero rather than showing a "0" nobody needs to act on.
        badge.classList.toggle('d-none', !count);
        badge.textContent = count > 99 ? '99+' : String(count);

        if (markAllButton) {
            markAllButton.classList.toggle('d-none', !count);
        }
    }

    function itemMarkup(notification) {
        return '<a class="notification-item' + (notification.is_read ? '' : ' is-unread') + '" ' +
            'href="' + escapeHtml(notification.open_url) + '">' +
            '<span class="notification-item-icon"><i class="bi ' + escapeHtml(notification.icon) +
            '" aria-hidden="true"></i></span>' +
            '<span class="notification-item-body">' +
            '<span class="notification-item-title">' + escapeHtml(notification.title) + '</span>' +
            '<span class="notification-item-message">' + escapeHtml(notification.message) + '</span>' +
            '<span class="notification-item-time">' + escapeHtml(notification.relative_time) + '</span>' +
            '</span>' +
            '</a>';
    }

    function render(payload) {
        setBadge(payload.unread_count || 0);

        if (!list) {
            return;
        }

        const notifications = payload.notifications || [];

        list.innerHTML = notifications.length
            ? notifications.map(itemMarkup).join('')
            : '<div class="notification-empty">You have no notifications.</div>';
    }

    function refresh() {
        if (!routes.feed) {
            return Promise.resolve();
        }

        return fetch(routes.feed, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Could not load notifications.');
                }

                return response.json();
            })
            .then(render)
            .catch(function() {
                // A failed poll is not worth interrupting the page for; the
                // next one will try again.
                if (list && !list.children.length) {
                    list.innerHTML = '<div class="notification-empty">Notifications are unavailable right now.</div>';
                }
            });
    }

    if (markAllButton) {
        markAllButton.addEventListener('click', function() {
            if (!routes.readAll) {
                return;
            }

            fetch(routes.readAll, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ _method: 'PUT' }),
                })
                .then(function() {
                    return refresh();
                });
        });
    }

    refresh();
    polling = window.setInterval(refresh, POLL_MS);

    // A backgrounded tab does not need to keep asking.
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            window.clearInterval(polling);
            polling = null;

            return;
        }

        if (!polling) {
            refresh();
            polling = window.setInterval(refresh, POLL_MS);
        }
    });
});
