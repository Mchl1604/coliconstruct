/**
 * Configuration -> System Settings -> Project Types.
 *
 * The list of work the company does, which is the same list the Technicians
 * page offers as specialties. Every write goes to the server and comes back
 * with the whole list, so what is on screen is always what was saved rather
 * than what this file guessed.
 */
document.addEventListener('DOMContentLoaded', function() {
    const pane = document.getElementById('projectTypesPane');

    if (!pane) {
        return;
    }

    const routes = window.configurationRoutes || {};

    if (!routes.projectTypes) {
        return;
    }

    const addForm = pane.querySelector('[data-project-type-add-form]');
    const nameInput = pane.querySelector('[data-project-type-name]');
    const addButton = pane.querySelector('[data-project-type-add]');
    const addSpinner = pane.querySelector('[data-project-type-add-spinner]');
    const body = pane.querySelector('[data-project-type-body]');
    const loading = pane.querySelector('[data-project-type-loading]');
    const empty = pane.querySelector('[data-project-type-empty]');
    const errorBox = pane.querySelector('[data-project-type-error]');
    const successBox = pane.querySelector('[data-project-type-success]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // The row being renamed, so only one input is ever open at a time.
    let editingId = null;

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function showError(message) {
        errorBox.textContent = message || '';
        errorBox.classList.toggle('d-none', !message);
    }

    function showSuccess(message) {
        successBox.textContent = message || '';
        successBox.classList.toggle('d-none', !message);
    }

    function request(url, method, payload) {
        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: payload ? JSON.stringify(payload) : undefined,
        }).then(function(response) {
            return response
                .json()
                .catch(function() {
                    return {};
                })
                .then(function(payload) {
                    return { ok: response.ok, body: payload };
                });
        });
    }

    /**
     * Whether a type may be removed, and why not when it may not.
     *
     * The same rule the server enforces, said here so the button explains
     * itself instead of only refusing once it has been pressed.
     */
    function blockedReason(type) {
        if (type.project_count > 0) {
            return 'In use by ' + type.project_count +
                (type.project_count === 1 ? ' project' : ' projects') +
                '. Change those projects before removing it.';
        }

        if (type.technician_count > 0) {
            return 'An approved specialty of ' + type.technician_count +
                (type.technician_count === 1 ? ' technician' : ' technicians') +
                '. Take it off them before removing it.';
        }

        return '';
    }

    /**
     * A count wears yellow, so the types that are actually in use are the ones
     * that catch the eye; nothing in use is stated in the section's own blue
     * rather than greyed out, which reads as disabled.
     */
    function countCell(count, noun) {
        if (!count) {
            return '<span class="project-type-count is-none">None</span>';
        }

        return '<span class="project-type-count">' + count + ' ' +
            (count === 1 ? noun : noun + 's') + '</span>';
    }

    function rowMarkup(type) {
        const blocked = blockedReason(type);

        // The rename input replaces the name in place, so the row keeps its
        // position and its counts while it is being edited.
        const nameCell = editingId === type.type_id
            ? '<div class="d-flex gap-2 align-items-center">' +
              '<input type="text" class="form-control form-control-sm" maxlength="255" ' +
              'value="' + escapeHtml(type.type_name) + '" data-project-type-input>' +
              '<button type="button" class="btn btn-sm btn-success" data-project-type-save="' +
              type.type_id + '">Save</button>' +
              '<button type="button" class="btn btn-sm btn-outline-secondary" ' +
              'data-project-type-cancel>Cancel</button>' +
              '</div>'
            : '<span class="fw-semibold">' + escapeHtml(type.type_name) + '</span>';

        return '<tr>' +
            '<td>' + nameCell + '</td>' +
            '<td>' + countCell(type.project_count, 'project') + '</td>' +
            '<td>' + countCell(type.technician_count, 'technician') + '</td>' +
            '<td class="text-center">' +
            '<button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 me-1" ' +
            'data-project-type-edit="' + type.type_id + '" title="Rename">' +
            '<i class="bi bi-pencil"></i></button>' +
            '<button type="button" class="btn btn-sm btn-outline-danger py-1 px-2" ' +
            'data-project-type-delete="' + type.type_id + '" ' +
            'data-project-type-label="' + escapeHtml(type.type_name) + '"' +
            (blocked ? ' disabled title="' + escapeHtml(blocked) + '"' : ' title="Remove"') +
            '><i class="bi bi-trash3"></i></button>' +
            '</td>' +
            '</tr>';
    }

    function render(types) {
        body.innerHTML = types.map(rowMarkup).join('');
        empty.classList.toggle('d-none', types.length > 0);

        const openInput = body.querySelector('[data-project-type-input]');

        if (openInput) {
            openInput.focus();
            openInput.select();
        }
    }

    function handle(result) {
        if (!result.ok) {
            showError(result.body.error || 'Unable to save project type.');

            return false;
        }

        showError('');

        if (result.body.message) {
            showSuccess(result.body.message);
        }

        editingId = null;
        render(result.body.types || []);

        return true;
    }

    function load() {
        loading.classList.remove('d-none');

        request(routes.projectTypes, 'GET').then(function(result) {
            loading.classList.add('d-none');

            if (!result.ok) {
                showError(result.body.error || 'Unable to load project types.');

                return;
            }

            render(result.body.types || []);
        });
    }

    addForm.addEventListener('submit', function(event) {
        event.preventDefault();

        const name = nameInput.value.trim();

        if (!name) {
            showError('Enter a name for the project type.');

            return;
        }

        addButton.disabled = true;
        addSpinner.classList.remove('d-none');
        showSuccess('');

        request(routes.projectTypes, 'POST', { type_name: name }).then(function(result) {
            addButton.disabled = false;
            addSpinner.classList.add('d-none');

            if (handle(result)) {
                nameInput.value = '';
            }
        });
    });

    body.addEventListener('click', function(event) {
        const edit = event.target.closest('[data-project-type-edit]');

        if (edit) {
            editingId = Number(edit.dataset.projectTypeEdit);
            showError('');
            showSuccess('');
            // Reloaded rather than patched in place, so the row being renamed
            // is drawn from what the server currently holds - the counts
            // beside it may have moved since the list was last drawn.
            load();

            return;
        }

        const cancel = event.target.closest('[data-project-type-cancel]');

        if (cancel) {
            editingId = null;
            load();

            return;
        }

        const save = event.target.closest('[data-project-type-save]');

        if (save) {
            const input = body.querySelector('[data-project-type-input]');
            const name = input ? input.value.trim() : '';

            if (!name) {
                showError('Enter a name for the project type.');

                return;
            }

            save.disabled = true;
            showSuccess('');

            request(
                routes.projectTypes + '/' + save.dataset.projectTypeSave,
                'PUT',
                { type_name: name }
            ).then(function(result) {
                save.disabled = false;
                handle(result);
            });

            return;
        }

        const remove = event.target.closest('[data-project-type-delete]');

        if (remove && !remove.disabled) {
            const label = remove.dataset.projectTypeLabel;

            // Removing a type takes the matching specialty with it, which is
            // worth saying out loud before it happens.
            if (!window.confirm(
                'Remove "' + label + '"?\n\nIt will no longer be offered as a project type ' +
                'or as a technician specialty.'
            )) {
                return;
            }

            remove.disabled = true;
            showSuccess('');

            request(
                routes.projectTypes + '/' + remove.dataset.projectTypeDelete,
                'DELETE'
            ).then(function(result) {
                remove.disabled = false;
                handle(result);
            });
        }
    });

    body.addEventListener('keydown', function(event) {
        if (!event.target.matches('[data-project-type-input]')) {
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            body.querySelector('[data-project-type-save]')?.click();
        }

        if (event.key === 'Escape') {
            editingId = null;
            load();
        }
    });

    load();
});
