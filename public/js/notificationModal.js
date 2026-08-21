/**
 * The Notification Center dialog.
 *
 * Same endpoints as the Notification Center page - searched, filtered and
 * paged in SQL, because this list only grows - rendered as a list rather than
 * a table so it reads on a phone as well as it does on a desktop.
 *
 * Nothing is fetched until the dialog is opened: the bell already polls for
 * the count, and a client who never opens this should not pay for it.
 */
document.addEventListener('DOMContentLoaded', function() {
    const modalElement = document.querySelector('[data-notification-modal]');

    if (!modalElement) {
        return;
    }

    const routes = window.notificationModalRoutes || {};
    const list = modalElement.querySelector('[data-modal-list]');
    const searchInput = modalElement.querySelector('[data-modal-search]');
    const statusSelect = modalElement.querySelector('[data-modal-status]');
    const loading = modalElement.querySelector('[data-modal-loading]');
    const empty = modalElement.querySelector('[data-modal-empty]');
    const pagination = modalElement.querySelector('[data-modal-pagination]');
    const markAllButton = modalElement.querySelector('[data-modal-mark-all]');
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
        return '<article class="notification-modal-item' +
            (notification.is_read ? '' : ' is-unread') + '">' +
            '<span class="notification-item-icon"><i class="bi ' + escapeHtml(notification.icon) +
            '" aria-hidden="true"></i></span>' +
            '<div class="notification-modal-item-body">' +
            '<div class="notification-modal-item-title">' +
            escapeHtml(notification.title) +
            (notification.is_read
                ? ''
                : '<span class="notification-modal-dot" aria-label="Unread"></span>') +
            '</div>' +
            '<p class="notification-modal-item-message">' + escapeHtml(notification.message) + '</p>' +
            '<div class="notification-modal-item-meta">' +
            '<span>' + escapeHtml(notification.created_at) + '</span>' +
            '<span aria-hidden="true">&middot;</span>' +
            '<span>' + escapeHtml(notification.relative_time) + '</span>' +
            '</div>' +
            '</div>' +
            '<div class="notification-modal-item-actions">' +
            '<a class="btn btn-sm btn-outline-secondary" href="' + escapeHtml(notification.open_url) + '" ' +
            'title="Open" aria-label="Open notification"><i class="bi bi-eye" aria-hidden="true"></i></a>' +
            (notification.is_read
                ? ''
                : '<button type="button" class="btn btn-sm btn-outline-primary" data-mark-read="' +
                notification.id + '" title="Mark as read" aria-label="Mark as read">' +
                '<i class="bi bi-check2" aria-hidden="true"></i></button>') +
            '<button type="button" class="btn btn-sm btn-outline-danger" data-delete="' + notification.id +
            '" title="Delete" aria-label="Delete notification">' +
            '<i class="bi bi-trash" aria-hidden="true"></i></button>' +
            '</div>' +
            '</article>';
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
            '<span class="btn-group btn-group-sm">' +
            '<button type="button" class="btn btn-outline-secondary" data-page="' + (meta.current_page - 1) +
            '"' + (meta.current_page <= 1 ? ' disabled' : '') + '>Previous</button>' +
            '<button type="button" class="btn btn-outline-secondary" disabled>Page ' + meta.current_page +
            ' of ' + meta.last_page + '</button>' +
            '<button type="button" class="btn btn-outline-secondary" data-page="' + (meta.current_page + 1) +
            '"' + (meta.current_page >= meta.last_page ? ' disabled' : '') + '>Next</button>' +
            '</span>';

        pagination.querySelectorAll('[data-page]').forEach(function(button) {
            button.addEventListener('click', function() {
                page = Number(button.dataset.page);
                load();
            });
        });
    }

    function load() {
        const params = new URLSearchParams({
            search: searchInput ? searchInput.value : '',
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

                list.innerHTML = rows.map(rowMarkup).join('');
                renderPagination(payload.meta || {});

                if (empty) {
                    empty.classList.toggle('d-none', rows.length > 0);
                }

                if (markAllButton) {
                    markAllButton.disabled = !payload.unread_count;
                }
            })
            .catch(function() {
                list.innerHTML = '';

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

    list.addEventListener('click', function(event) {
        const markRead = event.target.closest('[data-mark-read]');

        if (markRead) {
            send(routeFor('read', markRead.dataset.markRead), 'PUT').then(load);

            return;
        }

        const remove = event.target.closest('[data-delete]');

        if (remove) {
            send(routeFor('destroy', remove.dataset.delete), 'DELETE').then(load);
        }
    });

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(reload, 300);
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', reload);
    }

    if (markAllButton) {
        markAllButton.addEventListener('click', function() {
            send(routes.readAll, 'PUT').then(load);
        });
    }

    // Fetched on every open rather than once: something may have arrived
    // while the dialog was shut.
    modalElement.addEventListener('show.bs.modal', load);
});
