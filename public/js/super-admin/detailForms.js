/**
 * Configuration -> System Settings -> Project Types -> Detail Forms.
 *
 * The fixed forms project types ask for: listed, and each one viewable in a
 * dialog. Read-only.
 */
document.addEventListener('DOMContentLoaded', function() {
    const pane = document.getElementById('detailFormsPane');
    const modalElement = document.querySelector('[data-detail-form-modal]');
    const routes = window.configurationRoutes || {};

    if (!pane || !modalElement || !routes.detailForms) {
        return;
    }

    const body = pane.querySelector('[data-detail-forms-body]');
    const errorBox = pane.querySelector('[data-detail-forms-error]');
    const titleElement = modalElement.querySelector('[data-detail-form-title]');
    const fieldList = modalElement.querySelector('[data-detail-field-list]');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function getJson(url) {
        return fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function(response) {
            return response.ok ? response.json() : Promise.reject();
        });
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    function rowMarkup(form) {
        return '<tr>' +
            '<td class="fw-semibold">' + escapeHtml(form.name) + '</td>' +
            '<td>' + form.field_count + '</td>' +
            '<td>' + (form.type_names.length ? escapeHtml(form.type_names.join(', ')) : 'None') + '</td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-primary py-1 px-2" ' +
            'data-detail-form-view="' + form.form_id + '" title="View"><i class="bi bi-eye"></i></button></td>' +
            '</tr>';
    }

    function fieldMarkup(field) {
        return '<tr>' +
            '<td>' + escapeHtml(field.label) + (field.is_required ? ' <span class="text-danger">*</span>' : '') + '</td>' +
            '<td>' + (field.scope === 'unit' ? 'Per unit' : 'Project') + '</td>' +
            '<td class="small text-secondary">' + escapeHtml((field.options || []).join(', ')) + '</td>' +
            '</tr>';
    }

    body.addEventListener('click', function(event) {
        const view = event.target.closest('[data-detail-form-view]');

        if (!view) {
            return;
        }

        getJson(routes.detailForms + '/' + view.dataset.detailFormView).then(function(payload) {
            titleElement.textContent = payload.form.name;
            fieldList.innerHTML = payload.form.fields.map(fieldMarkup).join('');
            modal.show();
        }).catch(function() {
            showError('Unable to load this form.');
        });
    });

    getJson(routes.detailForms).then(function(payload) {
        body.innerHTML = (payload.forms || []).map(rowMarkup).join('');
    }).catch(function() {
        body.innerHTML = '';
        showError('Unable to load forms.');
    });
});
