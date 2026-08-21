/**
 * The Notification Center table.
 *
 * Search, filters and paging all round-trip to the server: this list only
 * grows, so narrowing it in the browser would mean shipping the whole history
 * to narrow ten rows out of it.
 */
document.addEventListener('DOMContentLoaded', function() {
    const body = document.querySelector('[data-center-body]');

    if (!body) {
        return;
    }

    const routes = window.notificationCenterRoutes || {};
    const searchInput = document.querySelector('[data-center-search]');
    const moduleSelect = document.querySelector('[data-center-module]');
    const statusSelect = document.querySelector('[data-center-status]');
    const loading = document.querySelector('[data-center-loading]');
    const empty = document.querySelector('[data-center-empty]');
    const pagination = document.querySelector('[data-center-pagination]');
    const markAllButton = document.querySelector('[data-center-mark-all]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let page = 1;
    let searchTimer = null;

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function routeFor(name, id) {
        return (routes[name] || '').replace('__id__', String(id));
    }

    function send(url, method) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ _method: method }),
        });
    }

    function rowMarkup(notification) {
        // The whole row is a link to wherever the notification points, exactly
        // as each item in the bell dropdown is. The title carries a real <a>
        // so it can be middle-clicked, copied and reached by keyboard; the
        // row-level handler below is the convenience on top of it.
        return '<tr class="notification-center-row ' + (notification.is_read ? '' : 'is-unread') + '" ' +
            'data-notification-row="' + notification.id + '" ' +
            'data-open-url="' + escapeHtml(notification.open_url) + '">' +
            '<td class="text-start notification-center-dates">' + escapeHtml(notification.created_at) + '</td>' +
            '<td class="text-start">' +
            '<a class="notification-center-link" href="' + escapeHtml(notification.open_url) + '">' +
            '<div class="notification-center-title">' +
            '<i class="bi ' + escapeHtml(notification.icon) + ' me-1" aria-hidden="true"></i>' +
            escapeHtml(notification.title) + '</div>' +
            '<div class="notification-center-message">' + escapeHtml(notification.message) + '</div>' +
            '</a>' +
            '</td>' +
            '<td class="text-start"><span class="badge bg-secondary">' + escapeHtml(notification.module) +
            '</span></td>' +
            '<td class="text-start">' +
            (notification.is_read
                ? '<span class="badge bg-light text-secondary">Read</span>'
                : '<span class="badge bg-primary">Unread</span>') +
            '</td>' +
            '<td class="text-center">' +
            '<a class="btn btn-sm btn-outline-secondary me-1" href="' + escapeHtml(notification.open_url) + '" ' +
            'title="Open" aria-label="Open notification"><i class="bi bi-eye" aria-hidden="true"></i></a>' +
            (notification.is_read
                ? ''
                : '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-mark-read="' +
                notification.id + '" title="Mark as read" aria-label="Mark as read">' +
                '<i class="bi bi-check2" aria-hidden="true"></i></button>') +
            '<button type="button" class="btn btn-sm btn-outline-danger" data-delete="' + notification.id + '" ' +
            'title="Delete" aria-label="Delete notification"><i class="bi bi-trash" aria-hidden="true"></i></button>' +
            '</td>' +
            '</tr>';
    }

    function renderPagination(meta) {
        if (!pagination) {
            return;
        }

        if (!meta.total) {
            pagination.innerHTML = '';

            return;
        }

        pagination.innerHTML =
            '<span class="text-secondary small">Showing ' + meta.from + '&ndash;' + meta.to + ' of ' +
            meta.total + '</span>' +
            '<span class="btn-group">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-page="' + (meta.current_page - 1) +
            '"' + (meta.current_page <= 1 ? ' disabled' : '') + '>Previous</button>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" disabled>Page ' + meta.current_page +
            ' of ' + meta.last_page + '</button>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-page="' + (meta.current_page + 1) +
            '"' + (meta.current_page >= meta.last_page ? ' disabled' : '') + '>Next</button>' +
            '</span>';

        pagination.querySelectorAll('[data-page]').forEach(function(button) {
            button.addEventListener('click', function() {
                page = Number(button.dataset.page);
                load();
            });
        });
    }

    function bindRowActions() {
        // Clicking anywhere on the row opens it - the same gesture the bell
        // dropdown answers to. Clicks on the buttons, and on the title's own
        // link, are left alone: those already do something of their own.
        body.querySelectorAll('[data-open-url]').forEach(function(row) {
            row.addEventListener('click', function(event) {
                if (event.target.closest('a, button')) {
                    return;
                }

                window.location.href = row.dataset.openUrl;
            });
        });

        body.querySelectorAll('[data-mark-read]').forEach(function(button) {
            button.addEventListener('click', function() {
                send(routeFor('read', button.dataset.markRead), 'PUT').then(load);
            });
        });

        body.querySelectorAll('[data-delete]').forEach(function(button) {
            button.addEventListener('click', function() {
                send(routeFor('destroy', button.dataset.delete), 'DELETE').then(load);
            });
        });
    }

    function load() {
        const params = new URLSearchParams({
            search: searchInput ? searchInput.value : '',
            module: moduleSelect ? moduleSelect.value : 'all',
            status: statusSelect ? statusSelect.value : 'all',
            page: String(page),
        });

        if (loading) {
            loading.classList.remove('d-none');
        }

        fetch(routes.list + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Unable to load notifications.');
                }

                return response.json();
            })
            .then(function(payload) {
                const rows = payload.rows || [];

                body.innerHTML = rows.map(rowMarkup).join('');
                bindRowActions();
                renderPagination(payload.meta || {});

                if (empty) {
                    empty.classList.toggle('d-none', rows.length > 0);
                }

                if (markAllButton) {
                    markAllButton.disabled = !payload.unread_count;
                }
            })
            .catch(function() {
                body.innerHTML = '';

                if (empty) {
                    empty.classList.remove('d-none');
                    empty.textContent = 'Notifications are unavailable right now.';
                }
            })
            .finally(function() {
                if (loading) {
                    loading.classList.add('d-none');
                }
            });
    }

    // Any change to the narrowing starts again from page one - staying on
    // page 4 of a result set that now has two pages shows nothing.
    function reload() {
        page = 1;
        load();
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(reload, 300);
        });
    }

    [moduleSelect, statusSelect].forEach(function(select) {
        if (select) {
            select.addEventListener('change', reload);
        }
    });

    if (markAllButton) {
        markAllButton.addEventListener('click', function() {
            send(routes.readAll, 'PUT').then(load);
        });
    }

    load();
});
