/**
 * Configuration -> System Settings -> Backup & Restore.
 *
 * The list of backups, Create Backup, and one restore dialog shared by a
 * stored backup's Restore button and by Upload & Restore. Every write comes
 * back with the whole list, so what is on screen is what the server holds.
 *
 * A finished restore signs everybody out, this Super Admin included, so its
 * answer is a redirect to the sign-in page rather than a new list.
 */
document.addEventListener('DOMContentLoaded', function() {
    const panel = document.querySelector('[data-backups]');
    const routes = window.configurationRoutes || {};

    if (!panel || !routes.backups) {
        return;
    }

    const createForm = panel.querySelector('[data-backup-create-form]');
    const includeFiles = panel.querySelector('[data-backup-include-files]');
    const createButton = panel.querySelector('[data-backup-create]');
    const createSpinner = panel.querySelector('[data-backup-create-spinner]');
    const createIcon = panel.querySelector('[data-backup-create-icon]');
    const createLabel = panel.querySelector('[data-backup-create-label]');
    const body = panel.querySelector('[data-backup-body]');
    const loading = panel.querySelector('[data-backup-loading]');
    const empty = panel.querySelector('[data-backup-empty]');
    const errorBox = panel.querySelector('[data-backup-error]');
    const successBox = panel.querySelector('[data-backup-success]');
    const uploadOpen = panel.querySelector('[data-backup-upload-open]');

    const modalEl = document.querySelector('[data-backup-restore-modal]');
    const restoreForm = modalEl.querySelector('[data-backup-restore-form]');
    const restoreTitle = modalEl.querySelector('[data-backup-restore-title]');
    const restoreName = modalEl.querySelector('[data-backup-restore-name]');
    const fileGroup = modalEl.querySelector('[data-backup-restore-file-group]');
    const fileInput = modalEl.querySelector('[data-backup-restore-file]');
    const passwordInput = modalEl.querySelector('[data-backup-restore-password]');
    const confirmationInput = modalEl.querySelector('[data-backup-restore-confirmation]');
    const restoreError = modalEl.querySelector('[data-backup-restore-error]');
    const restoreSubmit = modalEl.querySelector('[data-backup-restore-submit]');
    const restoreSpinner = modalEl.querySelector('[data-backup-restore-spinner]');
    const restoreLabel = modalEl.querySelector('[data-backup-restore-label]');

    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // The stored backup the dialog was opened for, or null for an upload.
    let restoreTarget = null;
    let restoring = false;

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    function showAlert(box, message) {
        box.textContent = message || '';
        box.classList.toggle('d-none', !message);
    }

    function modal() {
        return window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    }

    function request(url, method, payload) {
        const isForm = payload instanceof FormData;
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (payload && !isForm) {
            headers['Content-Type'] = 'application/json';
        }

        return fetch(url, {
            method: method,
            headers: headers,
            body: payload ? (isForm ? payload : JSON.stringify(payload)) : undefined,
        }).then(function(response) {
            return response
                .json()
                .catch(function() {
                    // A proxy refusing an oversized upload answers in HTML.
                    return response.status === 413 ? { error: 'File too large.' } : {};
                })
                .then(function(payload) {
                    return { ok: response.ok, body: payload };
                });
        }, function() {
            return { ok: false, body: { error: 'Connection lost. Try again.' } };
        });
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let size = Number(bytes) || 0;
        let unit = 0;

        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit++;
        }

        return (unit === 0 ? size : size.toFixed(1)) + ' ' + units[unit];
    }

    const kindBadges = {
        manual: 'bg-primary-subtle text-primary-emphasis',
        pre_restore: 'bg-warning-subtle text-warning-emphasis',
        uploaded: 'bg-info-subtle text-info-emphasis',
    };

    function contentsLabel(backup) {
        if (!backup.includes_files) {
            return 'Database only';
        }

        return 'Database + ' + backup.file_count + (backup.file_count === 1 ? ' file' : ' files');
    }

    function rowMarkup(backup) {
        const base = routes.backups + '/' + backup.id;

        return '<tr>' +
            '<td><span class="fw-semibold backup-name">' + escapeHtml(backup.file_name) + '</span></td>' +
            '<td><span class="badge ' + (kindBadges[backup.kind] || 'bg-secondary') + '">' +
            escapeHtml(backup.kind_label) + '</span></td>' +
            '<td class="small">' + escapeHtml(contentsLabel(backup)) + '</td>' +
            '<td class="small text-nowrap">' + escapeHtml(formatBytes(backup.size_bytes)) + '</td>' +
            '<td class="small">' + escapeHtml(backup.created_by) + '</td>' +
            '<td class="small text-nowrap">' + escapeHtml(backup.created_at) + '</td>' +
            '<td class="text-center text-nowrap">' +
            '<a class="btn btn-sm btn-outline-primary py-1 px-2 me-1" href="' + escapeHtml(base + '/download') +
            '" title="Download" aria-label="Download"><i class="bi bi-download"></i></a>' +
            '<button type="button" class="btn btn-sm btn-outline-warning py-1 px-2 me-1" ' +
            'data-backup-restore="' + backup.id + '" data-backup-name="' + escapeHtml(backup.file_name) + '" ' +
            'title="Restore" aria-label="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>' +
            '<button type="button" class="btn btn-sm btn-outline-danger py-1 px-2" ' +
            'data-backup-delete="' + backup.id + '" data-backup-name="' + escapeHtml(backup.file_name) + '" ' +
            'title="Delete" aria-label="Delete"><i class="bi bi-trash3"></i></button>' +
            '</td>' +
            '</tr>';
    }

    function render(backups) {
        body.innerHTML = backups.map(rowMarkup).join('');
        empty.classList.toggle('d-none', backups.length > 0);
    }

    function handle(result, fallback) {
        if (!result.ok) {
            showAlert(errorBox, result.body.error || fallback);

            return false;
        }

        showAlert(errorBox, '');
        showAlert(successBox, result.body.message || '');
        render(result.body.backups || []);

        return true;
    }

    function load() {
        loading.classList.remove('d-none');

        request(routes.backups, 'GET').then(function(result) {
            loading.classList.add('d-none');

            if (!result.ok) {
                showAlert(errorBox, result.body.error || 'Unable to load backups.');

                return;
            }

            render(result.body.backups || []);
        });
    }

    function setCreating(busy) {
        createButton.disabled = busy;
        includeFiles.disabled = busy;
        createSpinner.classList.toggle('d-none', !busy);
        createIcon.classList.toggle('d-none', busy);
        createLabel.textContent = busy ? 'Creating…' : 'Create Backup';
    }

    createForm.addEventListener('submit', function(event) {
        event.preventDefault();

        setCreating(true);
        showAlert(errorBox, '');
        showAlert(successBox, '');

        request(routes.backups, 'POST', { include_files: includeFiles.checked }).then(function(result) {
            setCreating(false);
            handle(result, 'Backup failed. Try again.');
        });
    });

    // ------------------------------------------------------------------
    // Restore dialog
    // ------------------------------------------------------------------

    function setRestoring(busy) {
        restoring = busy;
        restoreSubmit.disabled = busy;
        restoreSpinner.classList.toggle('d-none', !busy);
        restoreLabel.textContent = busy ? 'Restoring…' : 'Restore';
    }

    function openRestore(target) {
        restoreTarget = target;

        restoreForm.reset();
        showAlert(restoreError, '');
        setRestoring(false);

        restoreTitle.textContent = target ? 'Restore Backup' : 'Upload & Restore';
        restoreName.textContent = target ? target.name : '';
        restoreName.classList.toggle('d-none', !target);
        fileGroup.classList.toggle('d-none', !!target);

        modal()?.show();
    }

    // A restore in progress must finish where the person can see its answer.
    modalEl.addEventListener('hide.bs.modal', function(event) {
        if (restoring) {
            event.preventDefault();
        }
    });

    modalEl.addEventListener('shown.bs.modal', function() {
        (restoreTarget ? passwordInput : fileInput).focus();
    });

    uploadOpen.addEventListener('click', function() {
        openRestore(null);
    });

    restoreForm.addEventListener('submit', function(event) {
        event.preventDefault();

        if (restoring) {
            return;
        }

        const data = new FormData();
        let url = routes.backupUpload;

        if (restoreTarget) {
            url = routes.backups + '/' + restoreTarget.id + '/restore';
        } else {
            const file = fileInput.files[0];

            if (!file) {
                showAlert(restoreError, 'Choose a backup file.');

                return;
            }

            if (!file.name.toLowerCase().endsWith('.tar.gz')) {
                showAlert(restoreError, 'Choose a .tar.gz backup.');

                return;
            }

            data.append('backup_file', file);
        }

        if (!passwordInput.value) {
            showAlert(restoreError, 'Enter your password.');

            return;
        }

        if (confirmationInput.value.trim().toUpperCase() !== 'RESTORE') {
            showAlert(restoreError, 'Type RESTORE to confirm.');

            return;
        }

        data.append('password', passwordInput.value);
        data.append('confirmation', confirmationInput.value);

        showAlert(restoreError, '');
        setRestoring(true);

        request(url, 'POST', data).then(function(result) {
            if (result.ok && result.body.redirect) {
                window.location.href = result.body.redirect;

                return;
            }

            setRestoring(false);
            showAlert(restoreError, result.body.error || 'Restore failed. Nothing changed.');

            // Nothing was restored, but a safety backup may have been taken
            // before the failure, so the list is redrawn.
            load();
        });
    });

    // ------------------------------------------------------------------
    // Row actions
    // ------------------------------------------------------------------

    body.addEventListener('click', function(event) {
        const restore = event.target.closest('[data-backup-restore]');

        if (restore) {
            openRestore({ id: restore.dataset.backupRestore, name: restore.dataset.backupName });

            return;
        }

        const remove = event.target.closest('[data-backup-delete]');

        if (remove) {
            const url = routes.backups + '/' + remove.dataset.backupDelete;

            showAlert(successBox, '');

            window.configurationConfirm({
                title: 'Delete backup?',
                body: 'This cannot be undone.',
                label: 'Delete',
                variant: 'btn-danger',
                onConfirm: function() {
                    return request(url, 'DELETE');
                },
                onSuccess: function(responseBody) {
                    handle({ ok: true, body: responseBody }, '');
                },
            });
        }
    });

    load();
});
