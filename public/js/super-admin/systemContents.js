/**
 * Configuration -> System Contents.
 *
 * The field list is not written into the page: each section is fetched, and
 * the form is built from whatever the catalogue says is editable. Adding a
 * field on the server therefore adds it here with no change to this file.
 */
document.addEventListener('DOMContentLoaded', function() {
    const pane = document.getElementById('systemContentsPane');

    if (!pane) {
        return;
    }

    const routes = window.configurationRoutes || {};
    const sectionNav = pane.querySelector('[data-content-sections]');
    const fieldsWrap = pane.querySelector('[data-content-fields]');
    const form = pane.querySelector('[data-content-form]');
    const loading = pane.querySelector('[data-content-loading]');
    const errorBox = pane.querySelector('[data-content-error]');
    const savedFlag = pane.querySelector('[data-content-saved]');
    const saveButton = pane.querySelector('[data-content-save]');
    const saveSpinner = pane.querySelector('[data-content-save-spinner]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let currentSection = sectionNav?.querySelector('[data-content-section]')?.dataset.contentSection || 'branding';
    let loaded = false;

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function showError(message) {
        if (!errorBox) {
            return;
        }

        errorBox.textContent = message || '';
        errorBox.classList.toggle('d-none', !message);
    }

    function sectionUrl(section) {
        return routes.contentBase + '/' + encodeURIComponent(section);
    }

    function imageUrl(key) {
        return routes.contentBase + '/images/' + encodeURIComponent(key);
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

        if (field.type === 'textarea' || field.type === 'html') {
            return '<div class="col-12"><label class="form-label fw-semibold" for="' + id + '">' +
                escapeHtml(field.label) + badge + '</label>' +
                '<textarea class="form-control" id="' + id + '" rows="4" data-content-input="' +
                escapeHtml(field.key) + '">' + escapeHtml(field.value) + '</textarea>' +
                help + '</div>';
        }

        const inputType = field.type === 'url' ? 'text' : 'text';

        return '<div class="col-md-6"><label class="form-label fw-semibold" for="' + id + '">' +
            escapeHtml(field.label) + badge + '</label>' +
            '<input type="' + inputType + '" class="form-control" id="' + id + '" value="' +
            escapeHtml(field.value) + '" data-content-input="' + escapeHtml(field.key) + '">' +
            help + '</div>';
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

    function render(payload) {
        fieldsWrap.innerHTML = (payload.fields || []).map(fieldMarkup).join('');
        bindImageActions();

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

            load(button.dataset.contentSection);
        });
    }

    if (form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();

            const values = {};

            fieldsWrap.querySelectorAll('[data-content-input]').forEach(function(input) {
                values[input.dataset.contentInput] = input.value;
            });

            // The section these fields actually belong to, rather than
            // whichever pill is highlighted: clicking a new section and saving
            // before its fields arrive would otherwise post one section's
            // values to another, and the save would quietly do nothing.
            const firstKey = Object.keys(values)[0];
            const sectionForFields = firstKey ? firstKey.split('.')[0] : currentSection;

            showError('');
            savedFlag?.classList.add('d-none');
            saveButton.disabled = true;
            saveSpinner?.classList.remove('d-none');

            fetch(sectionUrl(sectionForFields), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ _method: 'PUT', values: values }),
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
                    render(payload);
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
    }

    // Fetched only when System Settings is first opened, so the Configuration
    // page does not pay for content nobody looked at.
    document.getElementById('systemSettingsTab')?.addEventListener('shown.bs.tab', function() {
        if (!loaded) {
            loaded = true;
            load(currentSection);
        }
    });
});
