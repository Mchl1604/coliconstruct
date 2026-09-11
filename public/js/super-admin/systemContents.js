/**
 * Configuration -> System Settings.
 *
 * The field list is not written into the page: each section is fetched, and
 * the form is built from whatever the catalogue says is editable. Adding a
 * field on the server therefore adds it here with no change to this file.
 *
 * Two categories use this - System Contents, which edits the public website,
 * and General Settings, which edits how the system behaves - so the whole
 * thing is a function over one pane rather than a script bound to one element
 * id. The two are the same editor against the same endpoints; only the list of
 * sections differs, and each pane names its own list of types in the System
 * Settings sidebar (data-content-nav).
 *
 * One section is on screen at a time. Choosing another type replaces the
 * fields, so a switch with unsaved changes asks first; the Save bar says at
 * all times whether there is anything to save.
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
        // The list of types lives in the System Settings sidebar, outside the
        // pane, so the pane names it rather than containing it.
        const sectionNav = pane.dataset.contentNav
            ? document.getElementById(pane.dataset.contentNav)
            : pane.querySelector('[data-content-sections]');
        const fieldsWrap = pane.querySelector('[data-content-fields]');
        const form = pane.querySelector('[data-content-form]');
        const loading = pane.querySelector('[data-content-loading]');
        const errorBox = pane.querySelector('[data-content-error]');
        const saveBar = pane.querySelector('[data-content-savebar]');
        const saveState = pane.querySelector('[data-content-state]');
        const saveButton = pane.querySelector('[data-content-save]');
        const saveSpinner = pane.querySelector('[data-content-save-spinner]');
        const saveIcon = pane.querySelector('[data-content-save-icon]');
        const saveLabel = pane.querySelector('[data-content-save-label]');
        const cancelButton = pane.querySelector('[data-content-cancel]');
        const panel = pane.querySelector('.content-section-panel');

        if (!fieldsWrap || !form) {
            return null;
        }

        let currentSection = (sectionNav?.querySelector('[data-content-section].active') ||
            sectionNav?.querySelector('[data-content-section]'))?.dataset.contentSection || '';
        const pendingImages = new Map();
        const imageRemovals = new Set();

        // What the fields held when they were last drawn from the server, so
        // "is there anything to save" is a comparison rather than a guess. Null
        // until a section has been drawn at all.
        let baseline = null;
        // 'loading' while a section is being fetched, 'saving' while one is
        // being written, null otherwise.
        let busy = null;
        // Set by a save that went through, cleared by the next edit - it is
        // what lets the bar say "saved" rather than merely "nothing to save".
        let justSaved = false;
        // Only the newest request may draw. Switching twice in quick succession
        // otherwise lets the slower answer land last, showing one section's
        // fields under another's name.
        let loadToken = 0;

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
                                // The image is saved the moment it is chosen;
                                // anything typed but not yet saved stays typed.
                                load(currentSection, true);
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
                                load(currentSection, true);
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
         * Panels in the markup that stand for a type of their own rather than
         * being fields of a catalogue section - Project Types and Default
         * Phases & Tasks. Each has its own endpoints and its own saving; this
         * only shows the one whose entry is chosen, and hides the rest.
         */
        function showExtrasFor(section) {
            pane.querySelectorAll('[data-content-extra]').forEach(function(extra) {
                extra.hidden = extra.dataset.contentExtra !== section;
            });
        }

        function typeButtons() {
            return sectionNav ? Array.from(sectionNav.querySelectorAll('[data-content-section]')) : [];
        }

        function buttonFor(section) {
            return typeButtons().find(function(button) {
                return button.dataset.contentSection === section;
            }) || null;
        }

        function labelFor(section) {
            return buttonFor(section)?.textContent.trim() || 'this section';
        }

        // An entry drawn from the markup rather than fetched from the
        // catalogue - see showPanel().
        function isPanelType(section) {
            return buttonFor(section)?.dataset.contentKind === 'panel';
        }

        /**
         * Lights the chosen type in the sidebar. Called when a section starts
         * loading rather than when it is clicked, so a switch that is called
         * off at the "discard changes?" question never moves the highlight.
         */
        function markChosen(section) {
            typeButtons().forEach(function(button) {
                const chosen = button.dataset.contentSection === section;

                button.classList.toggle('active', chosen);

                if (chosen) {
                    button.setAttribute('aria-current', 'true');
                } else {
                    button.removeAttribute('aria-current');
                }
            });
        }

        // ------------------------------------------------------------------
        // Unsaved changes
        // ------------------------------------------------------------------

        /**
         * Everything Save would send, as one string. Compared with the
         * baseline taken when the section was drawn, it answers "has anything
         * changed" for every kind of field at once - typed text, a chosen
         * hour, a service added, moved or removed, an image picked or taken
         * away - without each of them having to report it.
         */
        function snapshot() {
            const values = {};

            fieldsWrap.querySelectorAll('[data-content-input]').forEach(function(input) {
                values[input.dataset.contentInput] = input.value;
            });

            return JSON.stringify({
                values: values,
                services: repeatablePayload('service'),
                owners: repeatablePayload('owner'),
                images: Array.from(pendingImages.keys()).sort(),
            });
        }

        function isDirty() {
            return baseline !== null && snapshot() !== baseline;
        }

        function setState(icon, text, modifier) {
            if (!saveState) {
                return;
            }

            const glyph = document.createElement('i');
            glyph.className = 'bi ' + icon;
            glyph.setAttribute('aria-hidden', 'true');

            saveState.replaceChildren(glyph, document.createTextNode(text));
            saveState.className = 'settings-save-state' + (modifier ? ' ' + modifier : '');
        }

        /**
         * The Save bar, drawn from the state rather than poked at from each
         * place the state changes: busy, dirty and just-saved together decide
         * every part of it.
         */
        function renderState() {
            const saving = busy === 'saving';
            const working = busy !== null;
            const dirty = isDirty();

            saveButton.disabled = working || !dirty;
            saveButton.classList.toggle('is-saving', saving);
            saveButton.setAttribute('aria-busy', saving ? 'true' : 'false');
            saveSpinner?.classList.toggle('d-none', !saving);
            saveIcon?.classList.toggle('d-none', saving);

            if (saveLabel) {
                saveLabel.textContent = saving ? 'Saving…' : 'Save Changes';
            }

            if (cancelButton) {
                cancelButton.disabled = working || !dirty;
            }

            // Held still while a save is in flight: the save redraws the
            // section it wrote, and a switch made meanwhile would be drawn over.
            typeButtons().forEach(function(button) {
                button.disabled = saving;
            });

            saveBar?.classList.toggle('is-dirty', dirty && !working);
            panel?.classList.toggle('is-loading', busy === 'loading');

            if (saving) {
                setState('bi-arrow-repeat', 'Saving your changes…', 'is-busy');
            } else if (busy === 'loading') {
                setState('bi-hourglass-split', 'Loading…', 'is-busy');
            } else if (dirty) {
                setState('bi-pencil-fill', 'Unsaved changes', 'is-dirty');
            } else if (justSaved) {
                setState('bi-check2-circle', 'All changes saved', 'is-saved');
            } else {
                setState('bi-check2', 'No unsaved changes', '');
            }
        }

        /**
         * Asks before throwing away unsaved work, in the page's own dialog so
         * it reads like every other question this page asks.
         */
        function confirmDiscard(consequence, label, proceed) {
            const title = 'Discard unsaved changes?';
            const body = 'Your changes to ' + labelFor(currentSection) + ' have not been saved. ' + consequence;

            if (typeof window.configurationConfirm !== 'function') {
                if (window.confirm(title + '\n\n' + body)) {
                    proceed();
                }

                return;
            }

            window.configurationConfirm({
                title: title,
                body: body,
                label: label,
                variant: 'btn-danger',
                onConfirm: function() {
                    return Promise.resolve({ ok: true, body: {} });
                },
                onSuccess: proceed,
            });
        }

        /**
         * What was typed, so it can be put back after a re-read. An image is
         * saved the moment it is chosen, and the re-read that shows it must
         * not take the rest of the unsaved work with it. The service and owner
         * lists are kept whole - rows, order, chosen pictures and all - and
         * put back in place of the freshly drawn ones.
         */
        function captureEdits() {
            const values = {};
            const lists = {};

            fieldsWrap.querySelectorAll('[data-content-input]').forEach(function(input) {
                values[input.dataset.contentInput] = input.value;
            });

            fieldsWrap.querySelectorAll('[data-repeatable-list]').forEach(function(list) {
                lists[list.dataset.repeatableList] = list.querySelector('[data-repeatable-entries]');
            });

            return { values: values, lists: lists };
        }

        function restoreEdits(kept) {
            fieldsWrap.querySelectorAll('[data-content-input]').forEach(function(input) {
                if (Object.prototype.hasOwnProperty.call(kept.values, input.dataset.contentInput)) {
                    input.value = kept.values[input.dataset.contentInput];
                }
            });

            fieldsWrap.querySelectorAll('[data-repeatable-list]').forEach(function(list) {
                const entries = kept.lists[list.dataset.repeatableList];
                const fresh = list.querySelector('[data-repeatable-entries]');

                if (entries && fresh) {
                    fresh.replaceWith(entries);
                }
            });
        }

        // Typing, choosing, and the list buttons all change what Save would
        // send; the bar has to notice each of them. Registered after the list
        // handlers above, so it reads the fields after they have changed.
        ['input', 'change', 'click'].forEach(function(type) {
            fieldsWrap.addEventListener(type, function() {
                if (type !== 'click') {
                    justSaved = false;
                }

                renderState();
            });
        });

        // ------------------------------------------------------------------
        // Drawing a section
        // ------------------------------------------------------------------

        function render(payload) {
            fieldsWrap.innerHTML = (payload.fields || []).map(fieldMarkup).join('');
            bindImageActions();
            showExtrasFor(payload.section || currentSection);

            const title = pane.querySelector('[data-content-section-title]');

            if (title) {
                const icon = document.createElement('i');
                icon.className = 'bi ' + (buttonFor(payload.section || currentSection)?.dataset.icon || 'bi-sliders');
                icon.setAttribute('aria-hidden', 'true');

                title.replaceChildren(icon, document.createTextNode(payload.label || ''));
            }
        }

        /**
         * Fetches a section and draws it. `keepEdits` carries unsaved work
         * across the re-read - see captureEdits(). Resolves once drawn, and
         * rejects if it could not be.
         */
        function load(section, keepEdits) {
            const kept = keepEdits && isDirty() ? captureEdits() : null;
            const mine = ++loadToken;

            currentSection = section;
            markChosen(section);

            // The System Contents card takes each type's accent colour.
            if ('contentAccents' in pane.dataset) {
                pane.dataset.settingsAccent = section;
            }

            // Back from a panel entry, if that is where this came from.
            if (panel) {
                panel.hidden = false;
            }

            showExtrasFor(section);
            showError('');
            busy = 'loading';
            renderState();

            if (loading) {
                loading.classList.remove('d-none');
            }

            return fetch(sectionUrl(section), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Unable to load this content.');
                    }

                    return response.json();
                })
                .then(function(payload) {
                    if (mine !== loadToken) {
                        return;
                    }

                    render(payload);
                    baseline = snapshot();

                    if (kept) {
                        restoreEdits(kept);
                    }
                })
                .catch(function(exception) {
                    if (mine !== loadToken) {
                        return;
                    }

                    fieldsWrap.innerHTML = '';
                    showExtrasFor(null);
                    baseline = snapshot();
                    showError(exception.message);

                    throw exception;
                })
                .finally(function() {
                    if (mine !== loadToken) {
                        return;
                    }

                    busy = null;
                    renderState();

                    if (loading) {
                        loading.classList.add('d-none');
                    }
                });
        }

        /**
         * Puts a panel entry on screen - Project Types, Default Phases & Tasks
         * - in place of the catalogue form. Nothing is fetched here: each panel
         * loads and saves itself (projectTypes.js, phaseTemplates.js), and
         * work left in one stays put while another entry is open, since hiding
         * it takes nothing away.
         *
         * The form is emptied rather than merely hidden, so fields that were
         * discarded on the way here cannot count as unsaved changes later.
         */
        function showPanel(section) {
            ++loadToken;
            currentSection = section;
            markChosen(section);
            clearPendingImages();
            fieldsWrap.innerHTML = '';
            baseline = snapshot();
            busy = null;
            showError('');

            if (loading) {
                loading.classList.add('d-none');
            }

            if (panel) {
                panel.hidden = true;
            }

            showExtrasFor(section);
            renderState();
        }

        function switchTo(section) {
            justSaved = false;

            if (isPanelType(section)) {
                showPanel(section);

                return;
            }

            clearPendingImages();
            load(section).catch(function() {});
        }

        if (sectionNav) {
            sectionNav.addEventListener('click', function(event) {
                const button = event.target.closest('[data-content-section]');

                if (!button) {
                    return;
                }

                const requested = button.dataset.contentSection;

                if (requested === currentSection) {
                    return;
                }

                if (!isDirty()) {
                    switchTo(requested);

                    return;
                }

                confirmDiscard(
                    'Switching to ' + labelFor(requested) + ' will lose them.',
                    'Discard and Switch',
                    function() {
                        switchTo(requested);
                    }
                );
            });
        }

        // Discarding is a re-read, not an undo stack: the saved values are the
        // only thing that was ever true, so fetching them back is exactly what
        // "discard my changes" means.
        if (cancelButton) {
            cancelButton.addEventListener('click', function() {
                if (!isDirty()) {
                    return;
                }

                confirmDiscard(
                    'They will go back to what was last saved.',
                    'Discard Changes',
                    function() {
                        clearPendingImages();
                        load(currentSection).catch(function() {});
                    }
                );
            });
        }

        form.addEventListener('submit', function(event) {
            event.preventDefault();

            if (busy !== null || !isDirty()) {
                return;
            }

            const values = {};

            fieldsWrap.querySelectorAll('[data-content-input]').forEach(function(input) {
                values[input.dataset.contentInput] = input.value;
            });

            const services = repeatablePayload('service');
            const owners = repeatablePayload('owner');

            // The section these fields actually belong to, rather than
            // whichever type the sidebar has lit: choosing a new section and
            // saving before its fields arrive would otherwise post one
            // section's values to another, and the save would quietly do
            // nothing.
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
            justSaved = false;
            busy = 'saving';
            renderState();

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
                .then(function() {
                    return uploadPendingImages();
                })
                .then(function() {
                    clearPendingImages();
                    busy = null;

                    return load(sectionForFields).then(function() {
                        justSaved = true;
                        renderState();
                    });
                })
                .catch(function(exception) {
                    busy = null;
                    showError(exception.message);
                    renderState();
                });
        });

        renderState();

        return {
            load: function() {
                switchTo(currentSection);
            },
            isDirty: isDirty,
        };
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

    // Switching the sidebar or the Configuration tabs only hides a pane, so
    // unsaved work survives those. Leaving the page does not, and the browser's
    // own prompt is the only one that can stop it.
    window.addEventListener('beforeunload', function(event) {
        if (editors.some(function(editor) { return editor.isDirty(); })) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
});
