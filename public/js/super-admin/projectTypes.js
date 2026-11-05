/**
 * Configuration -> System Settings -> Project Types.
 *
 * The fixed list of work the company does, with the form each type asks for
 * and how much is using it. Read-only.
 */
document.addEventListener('DOMContentLoaded', function() {
    const pane = document.getElementById('projectTypesPane');
    const routes = window.configurationRoutes || {};

    if (!pane || !routes.projectTypes) {
        return;
    }

    const body = pane.querySelector('[data-project-type-body]');
    const loading = pane.querySelector('[data-project-type-loading]');
    const empty = pane.querySelector('[data-project-type-empty]');
    const errorBox = pane.querySelector('[data-project-type-error]');

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function countCell(count, noun) {
        if (!count) {
            return '<span class="project-type-count is-none">None</span>';
        }

        return '<span class="project-type-count">' + count + ' ' +
            (count === 1 ? noun : noun + 's') + '</span>';
    }

    function rowMarkup(type) {
        return '<tr>' +
            '<td><span class="fw-semibold">' + escapeHtml(type.type_name) + '</span></td>' +
            '<td>' + escapeHtml(type.form_name || '') + '</td>' +
            '<td>' + countCell(type.project_count, 'project') + '</td>' +
            '<td>' + countCell(type.technician_count, 'technician') + '</td>' +
            '</tr>';
    }

    fetch(routes.projectTypes, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function(response) {
        return response.ok ? response.json() : Promise.reject();
    }).then(function(payload) {
        const types = payload.types || [];

        body.innerHTML = types.map(rowMarkup).join('');
        empty.classList.toggle('d-none', types.length > 0);
    }).catch(function() {
        errorBox.textContent = 'Unable to load project types.';
        errorBox.classList.remove('d-none');
    }).finally(function() {
        loading.classList.add('d-none');
    });
});
