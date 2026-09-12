/**
 * The quotation check on the Edit Project Details dialog.
 *
 * The quotation amount and the quotation file describe the same thing, so a
 * save that changes one and not the other may leave them disagreeing. When
 * Save is pressed with only one half changed, the dialog swaps its form for a
 * question about the other half:
 *
 *   amount changed, no new file   ->  replace the file too?
 *   new file, amount unchanged    ->  update the amount too?
 *
 * Each has three answers. Yes fills in the other half right here and saves
 * both together; No saves the one half and leaves the other exactly as it is;
 * Cancel goes back to the form and saves nothing. Changing both on the form,
 * or neither, saves without asking - there is no mismatch to ask about.
 *
 * The answer travels as `quotation_change`, and the server refuses a
 * quotation change that does not match it - see QuotationChange. This script
 * is what asks; it is not what enforces.
 */
(function () {
    'use strict';

    const COPY = {
        amount: {
            title: 'The quotation amount has been changed.',
            question:
                'Do you also want to replace the uploaded quotation file with a new quotation file ' +
                'that reflects this updated amount?',
            yes: 'Yes, replace quotation file',
            no: 'No, keep existing quotation file',
            save: 'Save amount and file',
        },
        file: {
            title: 'The quotation file has been changed.',
            question: 'Do you also want to update the quotation amount to match the new quotation file?',
            yes: 'Yes, update quotation amount',
            no: 'No, keep existing quotation amount',
            save: 'Save file and amount',
        },
    };

    function init(root) {
        const form = (root || document).querySelector('[data-edit-project-form]');
        const step = form ? form.querySelector('[data-quotation-sync]') : null;

        if (!form || !step) {
            return;
        }

        const q = function (selector) {
            return form.querySelector(selector);
        };

        const formBody = q('[data-edit-project-body]');
        const formFooter = q('[data-edit-project-footer]');
        const stepFooter = q('[data-quotation-sync-footer]');
        const change = q('[data-quotation-change]');

        // The form's own quotation fields - the ones that are submitted.
        const amountField = q('[data-quotation-amount]');
        const amountInput = amountField.querySelector('[data-money-input]');
        const amountValue = amountField.querySelector('[data-money-value]');
        const fileInput = q('[data-quotation-file-input]');

        // The step's fields. Unnamed: whatever is entered in them is copied
        // onto the form's fields above when the answer is saved.
        const syncUpload = q('[data-quotation-sync-upload]');
        const syncFile = q('[data-quotation-sync-file]');
        const syncPicked = q('[data-quotation-sync-picked]');
        const syncAmount = q('[data-quotation-sync-amount]');
        const syncAmountInput = syncAmount.querySelector('[data-money-input]');
        const syncAmountValue = syncAmount.querySelector('[data-money-value]');
        const syncAmountError = q('[data-quotation-sync-amount-error]');

        const choices = q('[data-quotation-sync-choices]');
        const confirmRow = q('[data-quotation-sync-confirm]');
        const yesButton = q('[data-quotation-sync-yes]');
        const noButton = q('[data-quotation-sync-no]');
        const cancelButton = q('[data-quotation-sync-cancel]');
        const backButton = q('[data-quotation-sync-back]');
        const saveButton = q('[data-quotation-sync-save]');
        const saveLabel = q('[data-quotation-sync-save-label]');
        const spinner = q('[data-quotation-sync-spinner]');
        const note = q('[data-quotation-sync-note]');

        const originalAmount = step.dataset.originalAmount || '';
        let currentFiles = [];

        try {
            currentFiles = JSON.parse(step.dataset.currentFiles || '[]');
        } catch (error) {
            currentFiles = [];
        }

        /** Which half changed: 'amount', 'file', or null while the form shows. */
        let mode = null;

        /** Set once an answer is being sent, so nothing asks twice. */
        let sending = false;

        /** "1,500" -> "1500.00", the shape the server stores; '' for nothing. */
        function normalize(value) {
            const text = String(value == null ? '' : value).replace(/,/g, '').trim();

            if (text === '') {
                return '';
            }

            const number = Number(text);

            return Number.isFinite(number) ? number.toFixed(2) : text;
        }

        function peso(value) {
            const normalized = normalize(value);

            if (normalized === '') {
                return 'not set';
            }

            return '₱' + Number(normalized).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }

        function amountChanged() {
            return normalize(amountValue.value) !== originalAmount;
        }

        function fileChosen() {
            return Boolean(fileInput && fileInput.files && fileInput.files.length);
        }

        function fileNames(files) {
            return Array.from(files || []).map(function (file) {
                return file.name;
            });
        }

        function describeFiles(names) {
            return names.length ? names.join(', ') : 'none on record';
        }

        function show(element, visible) {
            element.classList.toggle('d-none', !visible);
        }

        /** Put a raw amount into a moneyInput pair, grouped as if typed. */
        function setMoney(input, raw) {
            input.value = raw;
            // moneyInput.js settles the pair on blur: groups the visible one
            // and writes the raw number into the hidden one.
            input.dispatchEvent(new Event('blur'));
        }

        function renderPicked() {
            const names = fileNames(syncFile.files);

            syncPicked.innerHTML = names
                .map(function (name) {
                    const span = document.createElement('span');
                    span.textContent = name;

                    return '<li><i class="bi bi-paperclip"></i>' + span.innerHTML + '</li>';
                })
                .join('');

            show(syncPicked, names.length > 0);

            if (mode === 'amount') {
                saveButton.disabled = names.length === 0;
            }
        }

        /** The facts panel: where each half stands right now. */
        function renderFacts() {
            const amountFact = q('[data-quotation-sync-amount-fact]');
            const fileFact = q('[data-quotation-sync-file-fact]');

            if (mode === 'amount') {
                amountFact.textContent = peso(originalAmount) + '  →  ' + peso(amountValue.value);
                fileFact.textContent = 'Current: ' + describeFiles(currentFiles);

                return;
            }

            amountFact.textContent = peso(originalAmount) + ' (unchanged)';
            fileFact.textContent = currentFiles.length
                ? describeFiles(fileNames(fileInput.files)) + ' replaces ' + describeFiles(currentFiles)
                : 'New: ' + describeFiles(fileNames(fileInput.files));
        }

        /** The three answers. */
        function showChoices() {
            show(syncUpload, false);
            show(syncAmount, false);
            show(syncAmountError, false);
            show(confirmRow, false);
            show(choices, true);
            show(note, true);

            syncFile.value = '';
            renderPicked();
        }

        /** After Yes: the other half, filled in here. */
        function showConfirm() {
            show(choices, false);
            show(note, false);
            show(confirmRow, true);
            saveLabel.textContent = COPY[mode].save;

            if (mode === 'amount') {
                show(syncUpload, true);
                saveButton.disabled = !(syncFile.files && syncFile.files.length);
                syncFile.focus();

                return;
            }

            show(syncAmount, true);
            saveButton.disabled = false;
            setMoney(syncAmountInput, amountValue.value || originalAmount);
            syncAmountInput.focus();
            syncAmountInput.select();
        }

        function open(which) {
            mode = which;

            q('[data-quotation-sync-title]').textContent = COPY[mode].title;
            q('[data-quotation-sync-question]').textContent = COPY[mode].question;
            q('[data-quotation-sync-yes-label]').textContent = COPY[mode].yes;
            q('[data-quotation-sync-no-label]').textContent = COPY[mode].no;

            renderFacts();
            showChoices();

            show(formBody, false);
            show(formFooter, false);
            show(step, true);
            show(stepFooter, true);

            step.focus();
        }

        /** Back to the form, exactly as it was left. Nothing is saved. */
        function close(returnFocus) {
            const was = mode;

            mode = null;
            showChoices();

            show(step, false);
            show(stepFooter, false);
            show(formBody, true);
            show(formFooter, true);

            if (!returnFocus) {
                return;
            }

            // Back at the field that raised the question.
            if (was === 'amount') {
                amountInput.focus();
            } else if (was === 'file' && fileInput) {
                fileInput.focus();
            }
        }

        function busy(button) {
            sending = true;

            [yesButton, noButton, cancelButton, backButton, saveButton].forEach(function (control) {
                control.disabled = true;
            });

            if (button === saveButton) {
                show(spinner, true);
            }
        }

        /**
         * Send the form with the answer on it.
         *
         * requestSubmit() rather than submit(): submit() skips the submit
         * event, and the event is what lets the page send this with fetch and
         * redraw in place - see projectWorkspace.js. The handler below lets it
         * straight through, because `sending` is already set.
         */
        function send(answer, button) {
            change.value = answer;
            busy(button);
            form.requestSubmit();
        }

        /**
         * The save was refused and the dialog is still open. Back to the form,
         * everything as it was typed, so the reason printed at the top of it
         * can be acted on and the save tried again.
         */
        function recover() {
            sending = false;

            [yesButton, noButton, cancelButton, backButton, saveButton].forEach(function (control) {
                control.disabled = false;
            });

            show(spinner, false);

            if (mode !== null) {
                close(false);
            }

            change.value = 'none';
        }

        // ------------------------------------------------------------------
        // Wiring
        // ------------------------------------------------------------------

        form.addEventListener('submit', function (event) {
            if (sending) {
                return;
            }

            const amount = amountChanged();
            const file = fileChosen();

            // Both halves changed together, or neither did: nothing is out of
            // step, so nothing needs asking.
            if (amount === file) {
                change.value = amount ? 'both' : 'none';

                return;
            }

            event.preventDefault();
            open(amount ? 'amount' : 'file');
        });

        noButton.addEventListener('click', function () {
            // The half that did not change stays exactly as it is on record.
            send(mode, noButton);
        });

        yesButton.addEventListener('click', showConfirm);
        backButton.addEventListener('click', showChoices);

        cancelButton.addEventListener('click', function () {
            close(true);
        });

        syncFile.addEventListener('change', renderPicked);

        syncAmountInput.addEventListener('input', function () {
            show(syncAmountError, false);
        });

        saveButton.addEventListener('click', function () {
            if (mode === 'amount') {
                if (!(syncFile.files && syncFile.files.length)) {
                    return;
                }

                // Handed to the form's own quotation field, so the upload
                // arrives exactly as if it had been chosen there.
                fileInput.files = syncFile.files;
                fileInput.dispatchEvent(new Event('change'));

                send('both', saveButton);

                return;
            }

            // Settle whatever is half-typed before reading it.
            syncAmountInput.dispatchEvent(new Event('blur'));

            const raw = normalize(syncAmountValue.value);

            if (raw === '' || !Number.isFinite(Number(raw)) || Number(raw) < 0) {
                show(syncAmountError, true);
                syncAmountInput.focus();

                return;
            }

            setMoney(amountInput, syncAmountValue.value);
            send('both', saveButton);
        });

        // Closing the whole dialog mid-question leaves nothing behind: it
        // opens on the form next time, not on a half-answered question.
        const modal = form.closest('.modal');

        if (modal) {
            modal.addEventListener('hidden.bs.modal', function () {
                if (mode !== null && !sending) {
                    close(false);
                }
            });
        }

        form.addEventListener('workspace:failed', recover);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }

    // The dialog is redrawn after every save - see projectWorkspace.js.
    document.addEventListener('workspace:updated', function (event) {
        init(event.detail.root);
    });
})();
