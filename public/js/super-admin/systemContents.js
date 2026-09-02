/**
 * Configuration -> System Settings.
 *
 * The field list is not written into the page: each section is fetched, and
 * the form is built from whatever the catalogue says is editable. Adding a
 * field on the server therefore adds it here with no change to this file.
 *
 * Two cards use this - System Contents, which edits the public website, and
 * System Settings, which edits how the system behaves - so the whole thing is
 * a function over one pane rather than a script bound to one element id. The
 * two are the same editor against the same endpoints; only the list of
 * sections differs, and each pane carries its own.
 */
document.addEventListener('DOMContentLoaded', function() {
    const routes = window.configurationRoutes || {};
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function sectionUrl(section) {
        return routes.contentBase + '/' + encodeURIComponent(section);
    }

    function imageUrl(key) {
        return routes.contentBase + '/images/' + encodeURIComponent(key);
    }

    function initEditor(pane) {
        const sectionNav = pane.querySelector('[data-content-sections]');
        const fieldsWrap = pane.querySelector('[data-content-fields]');
        const form = pane.querySelector('[data-content-form]');
        const loading = pane.querySelector('[data-content-loading]');
        const errorBox = pane.querySelector('[data-content-error]');
        const savedFlag = pane.querySelector('[data-content-saved]');
        const saveButton = pane.querySelector('[data-content-save]');
        const saveSpinner = pane.querySelector('[data-content-save-spinner]');
        const cancelButton = pane.querySelector('[data-content-cancel]');

        if (!fieldsWrap || !form) {
            return null;
        }

        let currentSection = sectionNav?.querySelector('[data-content-section]')?.dataset.contentSection || '';
        const pendingImages = new Map();
        const imageRemovals = new Set();

        function showError(message) {
            if (!errorBox) {
                return;
            }

            errorBox.textContent = message || '';
            errorBox.classList.toggle('d-none', !message);
        }

        function fieldMarkup(field) {
            const id = 'content-' + field.key.replace(/\./g, '-');
            const help = field.help
                ? '<div class="form-text">' + escapeHtml(field.help) + '</div>'
                : '';
            // Says so when the value shown is the built-in default rather than
            // something somebody typed, so "empty" and "unchanged" look different.
            const badge = field.is_default
                ? ' <span class="badge bg-light text-secondary fw-normal">default</span>'
                : '';

            if (field.type === 'service_list') {
                return repeatableFieldMarkup('service', field);
            }

            if (field.type === 'owner_list') {
                return repeatableFieldMarkup('owner', field);
            }

            if (field.type === 'image') {
                const preview = field.url
                    ? '<img src="' + escapeHtml(field.url) + '" alt="" class="content-image-preview">'
                    : '<div class="content-image-empty"><i class="bi bi-image" aria-hidden="true"></i>' +
                    '<span>No image</span></div>';

                return '<div class="col-md-6"><label class="form-label fw-semibold" for="' + id + '">' +
                    escapeHtml(field.label) + '</label>' +
                    '<div class="content-image-field" data-content-image="' + escapeHtml(field.key) + '">' +
                    preview +
                    '<div class="d-flex flex-wrap gap-2 mt-2">' +
                    '<label class="btn btn-sm btn-outline-primary mb-0" for="' + id + '">' +
                    (field.url ? 'Replace' : 'Upload') + '</label>' +
                    '<input type="file" id="' + id + '" class="d-none" accept="image/*" data-content-file>' +
                    (field.url
                        ? '<button type="button" class="btn btn-sm btn-outline-danger" data-content-remove>Remove</button>'
                        : '') +
                    '</div>' + help + '</div></div>';
            }

            // A spinner rather than a text box, so a setting that has to be a
            // whole number reads as one before anybody types into it. The
            // server validates it regardless - min and max here are a courtesy,
            // not the rule.
            if (field.type === 'number') {
                return '<div class="col-md-4"><label class="form-label fw-semibold" for="' + id + '">' +
                    escapeHtml(field.label) + badge + '</label>' +
                    '<input type="number" min="1" step="1" class="form-control" id="' + id + '" value="' +
                    escapeHtml(field.value) + '" data-content-input="' + escapeHtml(field.key) + '">' +
                    help + '</div>';
            }

            // Stepped to the hour, because everything a setting like this
            // bounds is counted in whole hours - the pickers it feeds offer
            // whole hours and availability is measured in whole-hour slots.
            // The field it must come before, when it has one, travels with it
            // so the pair can be checked before anything is sent.
            // The hours, chosen from rather than typed into. A time box would
            // put a minute field beside the hour, and every one of these
            // settings bounds something the rest of the system counts in whole
            // hours - so the minute is not a finer setting, it is a value
            // nothing downstream could honour. Offering the hours is what
            // makes it impossible to enter rather than merely refused
            // afterwards. The field it must come before, when it has one,
            // travels with it so the pair can be checked before anything is
            // sent.
            if (field.type === 'hour') {
                const before = field.before
                    ? ' data-content-before="' + escapeHtml(field.before) + '"' +
                    ' data-content-before-message="' + escapeHtml(field.before_message || '') + '"'
                    : '';

                const choices = (field.options || []).map(function(option) {
                    return '<option value="' + escapeHtml(option.value) + '"' +
                        (option.value === field.value ? ' selected' : '') + '>' +
                        escapeHtml(option.label) + '</option>';
                }).join('');

                return '<div class="col-md-4"><label class="form-label fw-semibold" for="' + id + '">' +
                    escapeHtml(field.label) + badge + '</label>' +
                    '<select class="form-select" id="' + id + '"' +
                    ' data-content-input="' + escapeHtml(field.key) + '"' +
                    before + '>' + choices + '</select>' + help + '</div>';
            }

            if (field.type === 'textarea' || field.type === 'html') {
                // A long field gets a taller box. The Terms and Conditions is
                // the only one of these somebody writes at length, and four
                // rows would have them editing an agreement through a slot.
                const rows = field.value && field.value.length > 600 ? 20 : 4;

                return '<div class="col-12"><label class="form-label fw-semibold" for="' + id + '">' +
                    escapeHtml(field.label) + badge + '</label>' +
                    '<textarea class="form-control" id="' + id + '" rows="' + rows +
                    '" data-content-input="' + escapeHtml(field.key) + '">' +
                    escapeHtml(field.value) + '</textarea>' + help + '</div>';
            }

            return '<div class="col-md-6"><label class="form-label fw-semibold" for="' + id + '">' +
                escapeHtml(field.label) + badge + '</label>' +
                '<input type="text" class="form-control" id="' + id + '" value="' +
                escapeHtml(field.value) + '" data-content-input="' + escapeHtml(field.key) + '">' +
                help + '</div>';
        }

        function repeatableFieldMarkup(kind, field) {
            const records = Array.isArray(field.value) ? field.value : [];
            const label = escapeHtml(field.label);
            const help = field.help
                ? '<div class="form-text mb-3">' + escapeHtml(field.help) + '</div>'
                : '';
            const addLabel = kind === 'service' ? 'Add Service' : 'Add Owner';

            return '<div class="col-12" data-repeatable-list="' + kind + '">' +
                '<label class="form-label fw-semibold">' + label + '</label>' + help +
                '<div class="d-grid gap-3" data-repeatable-entries>' +
                records.map(function(record) { return repeatableRowMarkup(kind, record); }).join('') +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-primary mt-3" data-repeatable-add>' +
                '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>' + addLabel + '</button>' +
                '</div>';
        }

        function repeatableRowMarkup(kind, record) {
            const id = record.id || newRepeatableId();
            const imageKey = record.image_key || repeatableImageKey(kind, id);
            const isService = kind === 'service';
            const name = isService ? record.title : record.name;
            const details = isService ? record.description : record.contact;
            const nameLabel = isService ? 'Service name' : 'Owner name';
            const detailsLabel = isService ? 'Description' : 'Contact details';
            const detailsRows = isService ? 3 : 2;
            const image = record.image
                ? '<img src="' + escapeHtml(record.image) + '" alt="" class="content-image-preview">'
                : '<div class="content-image-empty"><i class="bi bi-image" aria-hidden="true"></i>' +
                '<span>No image</span></div>';

            return '<article class="card border-0 bg-light" data-repeatable-row data-repeatable-id="' +
                escapeHtml(id) + '" data-repeatable-image-key="' + escapeHtml(imageKey) +
                '" data-has-image="' + (record.image ? 'true' : 'false') + '">' +
                '<div class="card-body p-3"><div class="row g-3 align-items-start">' +
                '<div class="col-md-4"><div class="content-image-field" data-repeatable-image-preview>' + image +
                '<div class="d-flex flex-wrap gap-2 mt-2">' +
                '<label class="btn btn-sm btn-outline-primary mb-0">Upload image' +
                '<input type="file" class="d-none" accept="image/*" data-repeatable-file></label>' +
                '<button type="button" class="btn btn-sm btn-outline-danger' + (record.image ? '' : ' d-none') +
                '" data-repeatable-remove-image>Remove image</button></div></div></div>' +
                '<div class="col-md-8"><div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
                '<strong>' + (isService ? 'Service' : 'Owner') + '</strong>' +
                '<div class="btn-group btn-group-sm" role="group" aria-label="Change display order">' +
                '<button type="button" class="btn btn-outline-secondary" data-repeatable-move="up" aria-label="Move up">' +
                '<i class="bi bi-chevron-up" aria-hidden="true"></i></button>' +
                '<button type="button" class="btn btn-outline-secondary" data-repeatable-move="down" aria-label="Move down">' +
                '<i class="bi bi-chevron-down" aria-hidden="true"></i></button>' +
                '<button type="button" class="btn btn-outline-danger" data-repeatable-remove-row>Remove</button>' +
                '</div></div><div class="mb-3"><label class="form-label small fw-semibold">' + nameLabel +
                '</label><input type="text" class="form-control" value="' + escapeHtml(name) +
                '" data-repeatable-name></div><div><label class="form-label small fw-semibold">' + detailsLabel +
                '</label><textarea class="form-control" rows="' + detailsRows + '" data-repeatable-details>' +
                escapeHtml(details) + '</textarea></div></div></div></div></article>';
        }

        function repeatableImageKey(kind, id) {
            return kind === 'service' ? 'home.service_image.' + id : 'about.owner_image.' + id;
        }

        function newRepeatableId() {
            if (window.crypto?.randomUUID) {
                return window.crypto.randomUUID();
            }

            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(character) {
                const number = Math.floor(Math.random() * 16);
                const value = character === 'x' ? number : (number & 0x3) | 0x8;

                return value.toString(16);
            });
        }

        function renderRepeatablePreview(row, source) {
            const preview = row.querySelector('[data-repeatable-image-preview]');
            const removeButton = row.querySelector('[data-repeatable-remove-image]');

            if (!preview) {
                return;
            }

            preview.querySelector('.content-image-preview, .content-image-empty')?.remove();

            const image = document.createElement('img');
            image.src = source;
            image.alt = '';
            image.className = 'content-image-preview';
            preview.insertBefore(image, preview.firstChild);
            removeButton?.classList.remove('d-none');
        }

        function showRepeatableImagePlaceholder(row) {
            const preview = row.querySelector('[data-repeatable-image-preview]');
            const removeButton = row.querySelector('[data-repeatable-remove-image]');

            if (!preview) {
                return;
            }

            preview.querySelector('.content-image-preview, .content-image-empty')?.remove();
            preview.insertAdjacentHTML('afterbegin', '<div class="content-image-empty">' +
                '<i class="bi bi-image" aria-hidden="true"></i><span>No image</span></div>');
            removeButton?.classList.add('d-none');
        }

        function clearPendingImages() {
            pendingImages.clear();
            imageRemovals.clear();
        }

        /**
         * 'HH:MM' as minutes since midnight, or null when it is not a time.
         */
        function minutesOfDay(value) {
            const parts = /^(\d{1,2}):(\d{2})$/.exec(String(value || '').trim());

            if (!parts) {
                return null;
            }

            const hour = Number(parts[1]);
            const minute = Number(parts[2]);

            return hour > 23 || minute > 59 ? null : hour * 60 + minute;
        }

        /**
         * The first pair of times this form holds that does not come in order,
         * as the sentence to show about it - or null when they all do.
         *
         * Which fields are a pair is not written here: the server sends it with
         * the field, from the same catalogue entry its own check reads, so the
         * two cannot disagree about what the rule is. Equal times fail with the
         * reversed ones - a window that starts and ends at the same hour has
         * nothing inside it.
         */
        function timesOutOfOrder(values) {
            const inputs = Array.from(fieldsWrap.querySelectorAll('[data-content-before]'));

            for (const input of inputs) {
                const earlier = minutesOfDay(values[input.dataset.contentInput]);
                const later = minutesOfDay(values[input.dataset.contentBefore]);

                if (earlier === null || later === null || earlier < later) {
                    continue;
                }

                return input.dataset.contentBeforeMessage ||
                    'These times have to come in order.';
            }

            return null;
        }

        function bindImageActions() {
            fieldsWrap.querySelectorAll('[data-content-image]').forEach(function(wrap) {
                const key = wrap.dataset.contentImage;
                const fileInput = wrap.querySelector('[data-content-file]');
                const removeButton = wrap.querySelector('[data-content-remove]');

                if (fileInput) {
                    fileInput.addEventListener('change', function() {
                        if (!fileInput.files || !fileInput.files.length) {
                            return;
                        }

                        const body = new FormData();
                        body.append('image', fileInput.files[0]);

                        showError('');

                        fetch(imageUrl(key), {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                                credentials: 'same-origin',
                                body: body,
                            })
                            .then(function(response) {
                                return response.json().then(function(payload) {
                                    if (!response.ok) {
                                        throw new Error(payload.error || payload.message ||
                                            'Unable to upload image.');
                                    }

                                    return payload;
                                });
                            })
                            .then(function() {
                                load(currentSection);
                            })
                            .catch(function(exception) {
                                showError(exception.message);
                            });
                    });
                }

                if (removeButton) {
                    removeButton.addEventListener('click', function() {
                        showError('');

                        fetch(imageUrl(key), {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': token,
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify({ _method: 'DELETE' }),
                            })
                            .then(function() {
                                load(currentSection);
                            })
                            .catch(function() {
                                showError('Unable to remove image.');
                            });
                    });
                }
            });
        }

        fieldsWrap.addEventListener('click', function(event) {
            const addButton = event.target.closest('[data-repeatable-add]');

            if (addButton) {
                const list = addButton.closest('[data-repeatable-list]');
                const entries = list?.querySelector('[data-repeatable-entries]');

                if (list && entries) {
                    entries.insertAdjacentHTML('beforeend', repeatableRowMarkup(list.dataset.repeatableList, {}));
                }

                return;
            }

            const moveButton = event.target.closest('[data-repeatable-move]');

            if (moveButton) {
                const row = moveButton.closest('[data-repeatable-row]');
                const direction = moveButton.dataset.repeatableMove;

                if (row && direction === 'up' && row.previousElementSibling) {
                    row.parentElement.insertBefore(row, row.previousElementSibling);
                }

                if (row && direction === 'down' && row.nextElementSibling) {
                    row.parentElement.insertBefore(row.nextElementSibling, row);
                }

                return;
            }

            const removeRowButton = event.target.closest('[data-repeatable-remove-row]');

            if (removeRowButton) {
                const row = removeRowButton.closest('[data-repeatable-row]');

                if (row) {
                    const key = row.dataset.repeatableImageKey;
                    pendingImages.delete(key);
                    imageRemovals.delete(key);
                    row.remove();
                }

                return;
            }

            const removeImageButton = event.target.closest('[data-repeatable-remove-image]');

            if (removeImageButton) {
                const row = removeImageButton.closest('[data-repeatable-row]');

                if (!row) {
                    return;
                }

                const key = row.dataset.repeatableImageKey;
                pendingImages.delete(key);

                if (row.dataset.hasImage === 'true') {
                    imageRemovals.add(key);
                } else {
                    imageRemovals.delete(key);
                }

                showRepeatableImagePlaceholder(row);
            }
        });

        fieldsWrap.addEventListener('change', function(event) {
            const input = event.target.closest('[data-repeatable-file]');

            if (!input?.files?.length) {
                return;
            }

            const row = input.closest('[data-repeatable-row]');

            if (!row) {
                return;
            }

            const key = row.dataset.repeatableImageKey;
            const file = input.files[0];

            pendingImages.set(key, file);
            imageRemovals.delete(key);
            renderRepeatablePreview(row, URL.createObjectURL(file));
        });

        function repeatablePayload(kind) {
            const list = fieldsWrap.querySelector('[data-repeatable-list="' + kind + '"]');

            if (!list) {
                return null;
            }

            return Array.from(list.querySelectorAll('[data-repeatable-row]')).map(function(row) {
                const id = row.dataset.repeatableId;
                const key = row.dataset.repeatableImageKey;
                const name = row.querySelector('[data-repeatable-name]')?.value || '';
                const details = row.querySelector('[data-repeatable-details]')?.value || '';
                const entry = {
                    id: id,
                    remove_image: imageRemovals.has(key),
                };

                if (kind === 'service') {
                    entry.title = name;
                    entry.description = details;
                } else {
                    entry.name = name;
                    entry.contact = details;
                }

                return entry;
            });
        }

        function uploadPendingImages() {
            const uploads = Array.from(pendingImages.entries()).map(function(entry) {
                const body = new FormData();
                body.append('image', entry[1]);

                return fetch(imageUrl(entry[0]), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    credentials: 'same-origin',
                    body: body,
                }).then(function(response) {
                    return response.json().then(function(payload) {
                        if (!response.ok) {
                            throw new Error(payload.error || payload.message || 'Unable to upload image.');
                        }
                    });
                });
            });

            return Promise.all(uploads);
        }

        /**
         * Panels that belong to one section but are not fields of it.
         *
         * Project Types is the only one: it is a table with its own endpoints,
         * not a value in the catalogue, but it IS a project setting and belongs
         * under that pill rather than in a card of its own. Declaring the
         * section it belongs to in the markup keeps the rule in one place.
         */
        function showExtrasFor(section) {
            pane.querySelectorAll('[data-content-extra]').forEach(function(extra) {
                extra.hidden = extra.dataset.contentExtra !== section;
            });
        }

        function render(payload) {
            fieldsWrap.innerHTML = (payload.fields || []).map(fieldMarkup).join('');
            bindImageActions();
            showExtrasFor(payload.section || currentSection);

            const title = pane.querySelector('[data-content-section-title]');

            if (title) {
                title.textContent = payload.label || '';
            }
        }

        function load(section) {
            currentSection = section;
            showError('');
            savedFlag?.classList.add('d-none');

            if (loading) {
                loading.classList.remove('d-none');
            }

            fetch(sectionUrl(section), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Unable to load this content.');
                    }

                    return response.json();
                })
                .then(render)
                .catch(function(exception) {
                    fieldsWrap.innerHTML = '';
                    showExtrasFor(null);
                    showError(exception.message);
                })
                .finally(function() {
                    if (loading) {
                        loading.classList.add('d-none');
                    }
                });
        }

        if (sectionNav) {
            sectionNav.addEventListener('click', function(event) {
                const button = event.target.closest('[data-content-section]');

                if (!button) {
                    return;
                }

                sectionNav.querySelectorAll('[data-content-section]').forEach(function(other) {
                    other.classList.toggle('active', other === button);
                });

                clearPendingImages();
                load(button.dataset.contentSection);
            });
        }

        // Cancel is a re-read, not an undo stack: the saved values are the only
        // thing that was ever true, so fetching them back is exactly what
        // "discard my changes" means.
        if (cancelButton) {
            cancelButton.addEventListener('click', function() {
                clearPendingImages();
                load(currentSection);
            });
        }

        form.addEventListener('submit', function(event) {
            event.preventDefault();

            const values = {};

            fieldsWrap.querySelectorAll('[data-content-input]').forEach(function(input) {
                values[input.dataset.contentInput] = input.value;
            });

            const services = repeatablePayload('service');
            const owners = repeatablePayload('owner');

            // The section these fields actually belong to, rather than
            // whichever pill is highlighted: clicking a new section and saving
            // before its fields arrive would otherwise post one section's
            // values to another, and the save would quietly do nothing.
            const firstKey = Object.keys(values)[0];
            const sectionForFields = firstKey
                ? firstKey.slice(0, firstKey.lastIndexOf('.'))
                : currentSection;

            const ordering = timesOutOfOrder(values);

            if (ordering) {
                showError(ordering);

                return;
            }

            showError('');
            savedFlag?.classList.add('d-none');
            saveButton.disabled = true;
            saveSpinner?.classList.remove('d-none');

            const body = { _method: 'PUT', values: values };

            if (services !== null) {
                body.services = services;
            }

            if (owners !== null) {
                body.owners = owners;
            }

            fetch(sectionUrl(sectionForFields), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                })
                .then(function(response) {
                    return response.json().then(function(payload) {
                        if (!response.ok) {
                            throw new Error(payload.error || payload.message || 'Unable to save changes.');
                        }

                        return payload;
                    });
                })
                .then(function(payload) {
                    return uploadPendingImages().then(function() {
                        clearPendingImages();
                        load(sectionForFields);

                        return payload;
                    });
                })
                .then(function() {
                    savedFlag?.classList.remove('d-none');
                })
                .catch(function(exception) {
                    showError(exception.message);
                })
                .finally(function() {
                    saveButton.disabled = false;
                    saveSpinner?.classList.add('d-none');
                });
        });

        return { load: function() { load(currentSection); } };
    }

    const editors = Array.from(document.querySelectorAll('[data-content-editor]'))
        .map(initEditor)
        .filter(Boolean);

    if (!editors.length) {
        return;
    }

    // Fetched only when System Settings is first opened, so the Configuration
    // page does not pay for content nobody looked at.
    let loaded = false;

    document.getElementById('systemSettingsTab')?.addEventListener('shown.bs.tab', function() {
        if (loaded) {
            return;
        }

        loaded = true;
        editors.forEach(function(editor) {
            editor.load();
        });
    });
});
