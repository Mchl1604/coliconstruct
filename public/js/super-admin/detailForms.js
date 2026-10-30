/**
 * Configuration -> System Settings -> Project Types -> Detail Forms.
 *
 * The forms a project type asks for in the create wizard's Units step, and the
 * builder that makes them. Every write goes to the server and comes back with
 * the whole list, which is announced as 'detail-forms:changed' so the Project
 * Types table above can offer the new form straight away.
 *
 * window.detailForms.openBuilder() is how Project Types' "+ New form" opens
 * the same dialog and learns which form it saved.
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
    const successBox = pane.querySelector('[data-detail-forms-success]');
    const builder = modalElement.querySelector('[data-detail-form-builder]');
    const titleElement = modalElement.querySelector('[data-detail-form-title]');
    const builderError = modalElement.querySelector('[data-detail-form-error]');
    const nameInput = modalElement.querySelector('[data-detail-form-name]');
    const fieldList = modalElement.querySelector('[data-detail-field-list]');
    const preview = modalElement.querySelector('[data-detail-form-preview]');
    const saveButton = modalElement.querySelector('[data-detail-form-save]');
    const saveSpinner = modalElement.querySelector('[data-detail-form-save-spinner]');
    const fieldTypes = JSON.parse(modalElement.dataset.fieldTypes || '{}');
    const fieldScopes = JSON.parse(modalElement.dataset.fieldScopes || '{}');
    const OPTION_TYPES = ['select', 'checkboxes'];
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let forms = [];
    // The form being edited, or null for a new one.
    let editingId = null;
    let fields = [];
    // Told which form was saved, for Project Types' "+ New form". Cleared
    // whenever the dialog closes, so a later plain edit does not call it.
    let onSaved = null;
    let saved = false;
    let onClosed = null;

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function show(box, message) {
        box.textContent = message || '';
        box.classList.toggle('d-none', !message);
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

    // ---------------------------------------------------------------
    // The list
    // ---------------------------------------------------------------

    function rowMarkup(form) {
        const usedBy = form.type_names.length
            ? form.type_names.map(escapeHtml).join(', ')
            : '<span class="text-secondary">None</span>';
        const blocked = form.type_count > 0
            ? 'Used by ' + form.type_count + (form.type_count === 1 ? ' type' : ' types') + '.'
            : '';

        return '<tr>' +
            '<td class="fw-semibold">' + escapeHtml(form.name) + '</td>' +
            '<td>' + form.field_count + '</td>' +
            '<td class="small">' + usedBy + '</td>' +
            '<td class="text-center text-nowrap">' +
            '<button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 me-1" ' +
            'data-detail-form-edit="' + form.form_id + '" title="Edit">' +
            '<i class="bi bi-pencil"></i></button>' +
            '<button type="button" class="btn btn-sm btn-outline-danger py-1 px-2" ' +
            'data-detail-form-delete="' + form.form_id + '" ' +
            'data-detail-form-label="' + escapeHtml(form.name) + '"' +
            (blocked ? ' disabled title="' + escapeHtml(blocked) + '"' : ' title="Remove"') +
            '><i class="bi bi-trash3"></i></button>' +
            '</td>' +
            '</tr>';
    }

    function render(list) {
        forms = list || [];
        body.innerHTML = forms.length
            ? forms.map(rowMarkup).join('')
            : '<tr><td colspan="4" class="text-secondary small">No forms yet.</td></tr>';

        document.dispatchEvent(new CustomEvent('detail-forms:changed', { detail: { forms: forms } }));
    }

    function load() {
        return request(routes.detailForms, 'GET').then(function(result) {
            if (!result.ok) {
                show(errorBox, result.body.error || 'Unable to load forms.');

                return;
            }

            render(result.body.forms || []);
        });
    }

    // Project Types changes which types use which form, which is what the
    // Used by column and the remove button read.
    document.addEventListener('project-types:changed', load);

    // ---------------------------------------------------------------
    // The builder
    // ---------------------------------------------------------------

    function blankField() {
        return { field_id: null, label: '', input_type: 'text', scope: 'project', options: [], is_required: false };
    }

    function fieldRowMarkup(field, index) {
        const typeOptions = Object.keys(fieldTypes).map(function(value) {
            return '<option value="' + value + '"' + (value === field.input_type ? ' selected' : '') + '>' +
                escapeHtml(fieldTypes[value]) + '</option>';
        }).join('');
        const hasOptions = OPTION_TYPES.indexOf(field.input_type) !== -1;
        const scopeOptions = Object.keys(fieldScopes).map(function(value) {
            return '<option value="' + value + '"' + (value === field.scope ? ' selected' : '') + '>' +
                escapeHtml(fieldScopes[value]) + '</option>';
        }).join('');

        // Two lines rather than a grid row: the dialog is narrower than the
        // window on most screens, so viewport breakpoints would squeeze it.
        return '<div class="detail-field-row border rounded-3 p-2 mb-2" data-field-index="' + index + '">' +
            '<div class="d-flex gap-2 align-items-center mb-2">' +
            '<input type="text" class="form-control form-control-sm flex-grow-1" maxlength="100" placeholder="Label" ' +
            'aria-label="Field label" value="' + escapeHtml(field.label) + '" data-field-prop="label">' +
            '<div class="btn-group btn-group-sm flex-shrink-0" role="group" aria-label="Field order">' +
            '<button type="button" class="btn btn-light" data-field-move="-1" title="Move up"' +
            (index === 0 ? ' disabled' : '') + '><i class="bi bi-arrow-up"></i></button>' +
            '<button type="button" class="btn btn-light" data-field-move="1" title="Move down"' +
            (index === fields.length - 1 ? ' disabled' : '') + '><i class="bi bi-arrow-down"></i></button>' +
            '<button type="button" class="btn btn-light text-danger" data-field-remove title="Remove">' +
            '<i class="bi bi-x-lg"></i></button>' +
            '</div>' +
            '</div>' +
            '<div class="d-flex gap-3 align-items-center">' +
            '<select class="form-select form-select-sm w-auto" aria-label="Field type" data-field-prop="input_type">' +
            typeOptions + '</select>' +
            '<select class="form-select form-select-sm w-auto" aria-label="Filled" data-field-prop="scope">' +
            scopeOptions + '</select>' +
            '<div class="form-check mb-0">' +
            '<input class="form-check-input" type="checkbox" id="detailFieldRequired' + index + '"' +
            (field.is_required ? ' checked' : '') + ' data-field-prop="is_required">' +
            '<label class="form-check-label small" for="detailFieldRequired' + index + '">Required</label>' +
            '</div>' +
            '</div>' +
            (hasOptions
                ? '<textarea class="form-control form-control-sm mt-2" rows="3" placeholder="One option per line" ' +
                  'aria-label="Options" data-field-prop="options">' +
                  escapeHtml(field.options.join('\n')) + '</textarea>'
                : '') +
            '</div>';
    }

    function renderFields() {
        fieldList.innerHTML = fields.length
            ? fields.map(fieldRowMarkup).join('')
            : '<p class="text-secondary small mb-2">No fields yet.</p>';
        renderPreview();
    }

    /** The field as the create wizard will draw it, without a name. */
    function previewInput(field) {
        switch (field.input_type) {
            case 'textarea':
                return '<textarea class="form-control form-control-sm" rows="2" disabled></textarea>';
            case 'number':
                return '<input type="number" class="form-control form-control-sm" disabled>';
            case 'date':
                return '<input type="date" class="form-control form-control-sm" disabled>';
            case 'yes_no':
                return '<select class="form-select form-select-sm" disabled><option>Yes / No</option></select>';
            case 'select':
                return '<select class="form-select form-select-sm" disabled><option>' +
                    escapeHtml(field.options[0] || 'Choose') + '</option></select>';
            case 'checkboxes':
                return (field.options.length ? field.options : ['Option']).map(function(option) {
                    return '<div class="form-check form-check-inline small">' +
                        '<input class="form-check-input" type="checkbox" disabled>' +
                        '<label class="form-check-label">' + escapeHtml(option) + '</label></div>';
                }).join('');
            default:
                return '<input type="text" class="form-control form-control-sm" disabled>';
        }
    }

    function previewGrid(list) {
        return '<div class="row g-2">' + list.map(function(field) {
            return '<div class="' + (field.input_type === 'textarea' || field.input_type === 'checkboxes' ? 'col-12' : 'col-sm-6') + '">' +
                '<label class="form-label small mb-1">' + escapeHtml(field.label) +
                (field.is_required ? ' <span class="text-danger">*</span>' : '') + '</label>' +
                previewInput(field) + '</div>';
        }).join('') + '</div>';
    }

    /**
     * The form as the wizard draws it: the project fields once, then one unit
     * card with Add Unit when any field is per unit.
     */
    function renderPreview() {
        const visible = fields.filter(function(field) {
            return field.label.trim() !== '';
        });
        const projectFields = visible.filter(function(field) {
            return field.scope !== 'unit';
        });
        const unitFields = visible.filter(function(field) {
            return field.scope === 'unit';
        });

        if (!visible.length) {
            preview.innerHTML = '<p class="text-secondary small mb-0">Add a field to see it here.</p>';

            return;
        }

        preview.innerHTML =
            '<div class="fw-semibold small mb-2">' + escapeHtml(nameInput.value.trim() || 'Form') + '</div>' +
            (projectFields.length ? previewGrid(projectFields) : '') +
            (unitFields.length
                ? '<div class="border rounded-3 p-2 mt-3 bg-white">' +
                  '<div class="fw-semibold small mb-2">Unit 1</div>' + previewGrid(unitFields) + '</div>' +
                  '<button type="button" class="btn btn-sm btn-outline-primary mt-2" disabled>' +
                  '<i class="bi bi-plus-lg me-1"></i>Add Unit</button>'
                : '');
    }

    function openBuilder(options) {
        options = options || {};
        editingId = options.formId || null;
        onSaved = options.onSaved || null;
        onClosed = options.onClosed || null;
        saved = false;
        show(builderError, '');

        if (!editingId) {
            titleElement.textContent = 'New Form';
            nameInput.value = '';
            fields = [blankField()];
            renderFields();
            modal.show();

            return;
        }

        request(routes.detailForms + '/' + editingId, 'GET').then(function(result) {
            if (!result.ok) {
                show(errorBox, result.body.error || 'Unable to open that form.');

                return;
            }

            const form = result.body.form;
            titleElement.textContent = 'Edit Form';
            nameInput.value = form.name;
            fields = form.fields.map(function(field) {
                return Object.assign({}, field, { options: field.options || [] });
            });
            renderFields();
            modal.show();
        });
    }

    fieldList.addEventListener('input', function(event) {
        const row = event.target.closest('[data-field-index]');
        const prop = event.target.dataset.fieldProp;

        if (!row || !prop) {
            return;
        }

        const field = fields[Number(row.dataset.fieldIndex)];

        if (prop === 'label') {
            field.label = event.target.value;
        } else if (prop === 'options') {
            field.options = event.target.value.split('\n');
        }

        renderPreview();
    });

    fieldList.addEventListener('change', function(event) {
        const row = event.target.closest('[data-field-index]');
        const prop = event.target.dataset.fieldProp;

        if (!row || !prop) {
            return;
        }

        const field = fields[Number(row.dataset.fieldIndex)];

        if (prop === 'input_type') {
            field.input_type = event.target.value;
            // Redrawn so the options box appears or goes with the type.
            renderFields();

            return;
        }

        if (prop === 'scope') {
            field.scope = event.target.value;
            renderPreview();

            return;
        }

        if (prop === 'is_required') {
            field.is_required = event.target.checked;
            renderPreview();
        }
    });

    fieldList.addEventListener('click', function(event) {
        const row = event.target.closest('[data-field-index]');

        if (!row) {
            return;
        }

        const index = Number(row.dataset.fieldIndex);
        const move = event.target.closest('[data-field-move]');

        if (move && !move.disabled) {
            const target = index + Number(move.dataset.fieldMove);
            const moved = fields.splice(index, 1)[0];
            fields.splice(target, 0, moved);
            renderFields();

            return;
        }

        if (event.target.closest('[data-field-remove]')) {
            fields.splice(index, 1);
            renderFields();
        }
    });

    modalElement.querySelector('[data-detail-field-add]').addEventListener('click', function() {
        fields.push(blankField());
        renderFields();
        fieldList.querySelector('[data-field-index="' + (fields.length - 1) + '"] [data-field-prop="label"]')?.focus();
    });

    nameInput.addEventListener('input', renderPreview);

    builder.addEventListener('submit', function(event) {
        event.preventDefault();

        const payload = {
            name: nameInput.value.trim(),
            fields: fields.map(function(field) {
                return {
                    field_id: field.field_id,
                    label: field.label.trim(),
                    input_type: field.input_type,
                    scope: field.scope,
                    options: OPTION_TYPES.indexOf(field.input_type) !== -1
                        ? field.options.map(function(option) {
                            return option.trim();
                        }).filter(Boolean)
                        : [],
                    is_required: field.is_required,
                };
            }),
        };

        if (!payload.name) {
            show(builderError, 'Enter a form name.');
            nameInput.focus();

            return;
        }

        saveButton.disabled = true;
        saveSpinner.classList.remove('d-none');

        request(
            editingId ? routes.detailForms + '/' + editingId : routes.detailForms,
            editingId ? 'PUT' : 'POST',
            payload
        ).then(function(result) {
            saveButton.disabled = false;
            saveSpinner.classList.add('d-none');

            if (!result.ok) {
                show(builderError, result.body.error || 'Unable to save the form.');

                return;
            }

            saved = true;
            show(errorBox, '');
            show(successBox, result.body.message || '');
            render(result.body.forms || []);

            if (onSaved) {
                onSaved(result.body.form);
            }

            modal.hide();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function() {
        if (!saved && onClosed) {
            onClosed();
        }

        onSaved = null;
        onClosed = null;
    });

    body.addEventListener('click', function(event) {
        const edit = event.target.closest('[data-detail-form-edit]');

        if (edit) {
            show(successBox, '');
            openBuilder({ formId: Number(edit.dataset.detailFormEdit) });

            return;
        }

        const remove = event.target.closest('[data-detail-form-delete]');

        if (remove && !remove.disabled) {
            const url = routes.detailForms + '/' + remove.dataset.detailFormDelete;

            window.configurationConfirm({
                title: 'Remove "' + remove.dataset.detailFormLabel + '"?',
                body: 'Saved projects keep their details.',
                label: 'Remove Form',
                variant: 'btn-danger',
                onConfirm: function() {
                    return request(url, 'DELETE');
                },
                onSuccess: function(responseBody) {
                    show(successBox, responseBody.message || '');
                    render(responseBody.forms || []);
                },
            });
        }
    });

    pane.querySelector('[data-detail-form-new]').addEventListener('click', function() {
        show(successBox, '');
        openBuilder();
    });

    window.detailForms = {
        list: function() {
            return forms;
        },
        openBuilder: openBuilder,
    };

    load();
});
