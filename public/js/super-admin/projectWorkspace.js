/**
 * Project Details as one workspace.
 *
 * Every change on this page is an ordinary form: edit the project, change the
 * team, add a task, complete a phase. Each one used to post, be redirected
 * back, and have the whole application drawn again - the layout, the sidebar,
 * every script - landing on the first tab at the top of the page.
 *
 * Now the same form is sent with fetch instead. Same fields, same token, same
 * method spoofing, same URL and the same Accept header a browser sends, so the
 * server cannot tell the difference: it runs exactly the validation,
 * authorisation and rules it always ran, and still answers with its redirect.
 * fetch follows that redirect, and the page the server draws is read for three
 * things - its Project Details content, its toasts, and any refusal.
 *
 * What happens next is one of three things:
 *
 *   - Saved. The dialog closes and only the workspace - everything inside
 *     `[data-project-workspace]` - is replaced with the server's new copy.
 *     The header, the sidebar and the notification bell are never touched, and
 *     the open tab, the inner tab and the scroll position carry across.
 *   - Refused. Nothing is redrawn. The dialog stays open with everything that
 *     was typed into it, and the reason is printed at the top of it. A form
 *     that is not in a dialog has nothing typed to keep, so the page is
 *     redrawn as the server now has it and the reason is a toast.
 *   - Sent somewhere else. Completing and archiving a project end on another
 *     page; so does an expired session. That is followed as an ordinary
 *     navigation, and the toasts are carried to the page it lands on.
 *
 * Replacing the markup drops every listener bound to it. The page's scripts
 * listen for `workspace:updated`, dispatched on the document with the new root
 * as `event.detail.root`, and bind to it the way they did on DOMContentLoaded;
 * a form's own script can listen for `workspace:failed` on the form to put
 * itself back after a refusal.
 *
 * `projectWorkspace.refresh()` redraws the workspace from the server without a
 * form - for scripts that save through an endpoint of their own.
 *
 * With script off the page works exactly as it always did, and a form marked
 * `data-workspace-native` is left to submit normally.
 */
(function (global) {
    'use strict';

    const ROOT = '[data-project-workspace]';
    const TOGGLES = '[data-bs-toggle="tab"], [data-bs-toggle="pill"]';

    /** One request at a time: a second save while the first is in flight would race it. */
    let busy = false;

    function workspace() {
        return document.querySelector(ROOT);
    }

    // ------------------------------------------------------------------
    // Reading the server's answer
    // ------------------------------------------------------------------

    function toastsIn(doc) {
        return Array.prototype.map
            .call(doc.querySelectorAll('[data-toast-container] [data-toast-type]'), function (toast) {
                const body = toast.querySelector('.toast-body');

                return {
                    type: toast.getAttribute('data-toast-type'),
                    message: body ? body.textContent.trim() : '',
                };
            })
            .filter(function (toast) {
                return toast.message;
            });
    }

    /** The validation messages the page prints in its "not saved" alert. */
    function validationErrorsIn(doc) {
        return Array.prototype.map
            .call(doc.querySelectorAll('[data-workspace-errors] li'), function (item) {
                return item.textContent.trim();
            })
            .filter(Boolean);
    }

    /**
     * What to say when the server did not answer with a page at all. A
     * refusal the server explains - validation, a rule - arrives as a page
     * with the reason on it, and never reaches this.
     */
    function statusMessage(status) {
        switch (status) {
            case 403:
                return 'You are not allowed to do that.';
            case 404:
                return 'That record could not be found. It may have been removed.';
            case 413:
                return 'The files are too large to upload.';
            case 419:
                return 'This page has expired. Reload it and try again.';
            case 429:
                return 'Too many attempts. Wait a moment and try again.';
            default:
                return 'Something went wrong on the server. Reload the page to see what was saved.';
        }
    }

    // ------------------------------------------------------------------
    // Toasts
    // ------------------------------------------------------------------

    function showToasts(toasts) {
        if (!global.adminShell) {
            return;
        }

        toasts.forEach(function (toast) {
            global.adminShell.showToast(toast.type, toast.message);
        });
    }

    // ------------------------------------------------------------------
    // Swapping the workspace
    // ------------------------------------------------------------------

    function paneId(toggle) {
        const target = toggle.getAttribute('data-bs-target') || toggle.getAttribute('href') || '';

        return target.charAt(0) === '#' && target.length > 1 ? target.slice(1) : null;
    }

    function activeTabs(scope) {
        if (global.pageMemory) {
            return global.pageMemory.activeTabs(scope);
        }

        return Array.prototype.filter
            .call(scope.querySelectorAll(TOGGLES), function (toggle) {
                return toggle.classList.contains('active');
            })
            .map(paneId)
            .filter(Boolean);
    }

    function paneFor(scope, toggle) {
        const id = paneId(toggle);

        return id ? scope.querySelector('#' + CSS.escape(id)) : null;
    }

    /**
     * Open the same tabs in the new markup before it is shown. Switching
     * afterwards would draw the first tab for a moment and fade across to the
     * right one - the very jump this page exists to remove.
     */
    function applyTabs(scope, ids) {
        const toggles = Array.prototype.slice.call(scope.querySelectorAll(TOGGLES));

        ids.forEach(function (id) {
            const toggle = toggles.find(function (candidate) {
                return paneId(candidate) === id;
            });
            const pane = toggle ? paneFor(scope, toggle) : null;

            if (!toggle || !pane) {
                return;
            }

            const strip = toggle.closest('.nav, [role="tablist"]') || scope;

            strip.querySelectorAll(TOGGLES).forEach(function (other) {
                const otherPane = paneFor(scope, other);

                other.classList.remove('active');
                other.setAttribute('aria-selected', 'false');

                if (otherPane) {
                    otherPane.classList.remove('active', 'show');
                }
            });

            toggle.classList.add('active');
            toggle.setAttribute('aria-selected', 'true');
            pane.classList.add('active');

            if (pane.classList.contains('fade')) {
                pane.classList.add('show');
            }
        });
    }

    /**
     * Take down what the old markup had running. DataTables and Bootstrap
     * both keep references to their elements, and a table left initialised
     * keeps a resize listener for a table nobody can see. Date pickers are
     * released by datePicker.js as their inputs leave the page.
     */
    function release(scope) {
        if (global.jQuery && global.jQuery.fn && global.jQuery.fn.dataTable) {
            global.jQuery.fn.dataTable.tables().forEach(function (table) {
                if (scope.contains(table)) {
                    global.jQuery(table).DataTable().destroy();
                }
            });
        }

        if (!global.bootstrap) {
            return;
        }

        [
            ['.modal', 'Modal'],
            [TOGGLES, 'Tab'],
            ['[data-bs-toggle="dropdown"]', 'Dropdown'],
        ].forEach(function (pair) {
            scope.querySelectorAll(pair[0]).forEach(function (element) {
                const instance = global.bootstrap[pair[1]].getInstance(element);

                if (instance) {
                    instance.dispose();
                }
            });
        });
    }

    /**
     * Close every open dialog in the workspace and wait for it to finish, so
     * the backdrop and the body's scroll lock go with it rather than being
     * left behind by markup that has been replaced.
     */
    function closeModals(scope) {
        const open = Array.prototype.slice.call(scope.querySelectorAll('.modal.show'));

        if (!open.length || !global.bootstrap) {
            return Promise.resolve();
        }

        return Promise.all(open.map(function (modal) {
            return new Promise(function (resolve) {
                modal.addEventListener('hidden.bs.modal', function () {
                    resolve();
                }, { once: true });

                // A hide that never reports back must not hold the page.
                setTimeout(resolve, 600);

                global.bootstrap.Modal.getOrCreateInstance(modal).hide();
            });
        }));
    }

    function clearModalLeftovers() {
        if (document.querySelector('.modal.show')) {
            return;
        }

        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    /**
     * A selector that finds "the same" control in the new markup, so focus
     * can go back to what the person was on. Only the two ways this page
     * names its controls; anything else is simply not refocused.
     */
    function focusKey(element) {
        const scope = workspace();

        if (!element || !scope || !scope.contains(element)) {
            return null;
        }

        if (element.id) {
            return '#' + CSS.escape(element.id);
        }

        const target = element.getAttribute('data-bs-target');

        return target ? '[data-bs-target="' + CSS.escape(target) + '"]' : null;
    }

    /**
     * Scripts parsed out of the answer never run on their own. Each inline one
     * is recreated in this document so it runs once, as the new markup goes
     * in - they are what hand the page's scripts its data (the team picker's
     * candidates, the Registered User options).
     */
    function armScripts(scope) {
        scope.querySelectorAll('script').forEach(function (old) {
            const type = (old.getAttribute('type') || '').toLowerCase();

            if (old.src || (type && type !== 'text/javascript' && type !== 'module')) {
                return;
            }

            const fresh = document.createElement('script');

            Array.prototype.forEach.call(old.attributes, function (attribute) {
                fresh.setAttribute(attribute.name, attribute.value);
            });

            fresh.textContent = old.textContent;
            old.replaceWith(fresh);
        });
    }

    /**
     * Replace the workspace with the one in `doc`. Returns false when the
     * answer has no workspace to take one from.
     */
    function swap(doc, url) {
        const current = workspace();
        const next = doc.querySelector(ROOT);

        if (!current || !next) {
            return false;
        }

        const tabs = activeTabs(current);
        const scrollX = global.scrollX;
        const scrollY = global.scrollY;

        // Read now rather than when the request left: a dialog that has just
        // closed has handed focus back to the button that opened it, and that
        // button is what the person expects to be on afterwards.
        const focusSelector = focusKey(document.activeElement);

        release(current);

        const fresh = document.importNode(next, true);

        applyTabs(fresh, tabs);
        armScripts(fresh);

        current.replaceWith(fresh);
        clearModalLeftovers();

        // The address follows the content - a filter or a page of the
        // activity log is still what a reload shows. The fragment is the
        // page's own and is kept.
        if (url) {
            try {
                const target = new URL(url, global.location.href);

                target.hash = global.location.hash;
                global.history.replaceState(global.history.state, '', target.href);
            } catch (error) {
                // The content is right; only the address bar is not.
            }
        }

        // Instant, not the page's default: Bootstrap makes the root scroll
        // smoothly, which would glide back into place rather than stay put.
        const stay = function () {
            global.scrollTo({ top: scrollY, left: scrollX, behavior: 'instant' });
        };

        stay();

        document.dispatchEvent(new CustomEvent('workspace:updated', {
            detail: { root: fresh },
        }));

        // Again, once the page's scripts have run: the task table is drawn
        // in full until DataTables pages it, which changes the height the
        // first scroll was measured against.
        stay();

        if (focusSelector) {
            const again = fresh.querySelector(focusSelector);

            if (again && typeof again.focus === 'function') {
                again.focus({ preventScroll: true });
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // A form's controls while it is being sent
    // ------------------------------------------------------------------

    function submitButtons(form) {
        return Array.prototype.slice.call(
            form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'),
        );
    }

    function setBusy(form, submitter) {
        const buttons = submitButtons(form);

        buttons.forEach(function (button) {
            button.dataset.workspaceWasDisabled = button.disabled ? '1' : '0';
            button.disabled = true;
        });

        const spinnerHost = submitter && buttons.indexOf(submitter) !== -1 ? submitter : buttons[0];

        if (spinnerHost && spinnerHost.tagName === 'BUTTON') {
            const spinner = document.createElement('span');

            spinner.className = 'spinner-border spinner-border-sm me-1';
            spinner.setAttribute('aria-hidden', 'true');
            spinner.setAttribute('data-workspace-spinner', '');
            spinnerHost.prepend(spinner);
        }

        form.setAttribute('aria-busy', 'true');
    }

    function clearBusy(form) {
        submitButtons(form).forEach(function (button) {
            if (button.dataset.workspaceWasDisabled !== undefined) {
                button.disabled = button.dataset.workspaceWasDisabled === '1';
                delete button.dataset.workspaceWasDisabled;
            }
        });

        form.querySelectorAll('[data-workspace-spinner]').forEach(function (spinner) {
            spinner.remove();
        });

        form.removeAttribute('aria-busy');
    }

    // ------------------------------------------------------------------
    // A refusal inside the dialog it came from
    // ------------------------------------------------------------------

    function errorBoxFor(form, modal) {
        const scope = form.querySelector('.modal-body') ? form : modal;

        const body = Array.prototype.find.call(scope.querySelectorAll('.modal-body'), function (candidate) {
            return !candidate.classList.contains('d-none');
        }) || modal.querySelector('.modal-body');

        if (!body) {
            return null;
        }

        let box = body.querySelector(':scope > [data-workspace-form-error]');

        if (!box) {
            box = document.createElement('div');
            box.className = 'alert alert-danger';
            box.setAttribute('role', 'alert');
            box.setAttribute('tabindex', '-1');
            box.setAttribute('data-workspace-form-error', '');
            body.prepend(box);
        }

        return { box: box, body: body };
    }

    function clearFormErrors(form) {
        const modal = form.closest('.modal');

        (modal || form).querySelectorAll('[data-workspace-form-error]').forEach(function (box) {
            box.remove();
        });
    }

    function printInDialog(form, modal, messages) {
        const target = errorBoxFor(form, modal);

        if (!target) {
            return false;
        }

        const heading = document.createElement('div');
        heading.className = 'fw-semibold mb-1';
        heading.innerHTML = '<i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>';
        heading.appendChild(document.createTextNode('Your changes were not saved.'));

        const list = document.createElement('ul');
        list.className = 'mb-0 ps-3';

        messages.forEach(function (message) {
            const item = document.createElement('li');

            item.textContent = message;
            list.appendChild(item);
        });

        target.box.replaceChildren(heading, list);
        target.body.scrollTop = 0;
        target.box.focus({ preventScroll: true });

        return true;
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /**
     * The request, and what to do with the answer. `form` is null for a
     * plain redraw.
     */
    function request(url, init, form) {
        return fetch(url, init)
            .then(
                function (response) {
                    return response.text().then(function (html) {
                        return { response: response, html: html };
                    });
                },
                function () {
                    return null;
                },
            )
            .then(function (result) {
                if (!result) {
                    return refused(form, ['Unable to reach the server. Nothing was changed.'], null, []);
                }

                return answer(result.response, result.html, form);
            })
            .catch(function (error) {
                // The server answered and this page failed to draw it. The
                // save may well have happened, so the honest recovery is the
                // page as the server now has it - drawn the ordinary way.
                if (global.console) {
                    global.console.error(error);
                }

                global.location.reload();

                return new Promise(function () {});
            });
    }

    function answer(response, html, form) {
        if (!response.ok) {
            return refused(form, [statusMessage(response.status)], null, []);
        }

        const type = response.headers.get('Content-Type') || '';

        if (type.indexOf('text/html') === -1) {
            return refused(form, ['The server answered with something this page cannot show. Reload the page to see what was saved.'], null, []);
        }

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const toasts = toastsIn(doc);
        const target = new URL(response.url || global.location.href, global.location.href);
        const current = workspace();
        const next = doc.querySelector(ROOT);

        // Sent somewhere else: another page, another project, or - with no
        // workspace in the answer at all - probably the sign-in page.
        const elsewhere = target.pathname !== global.location.pathname
            || !current
            || !next
            || next.getAttribute('data-project-workspace') !== current.getAttribute('data-project-workspace');

        if (elsewhere) {
            if (global.adminShell) {
                global.adminShell.carryToasts(toasts);
            }

            global.location.assign(target.href);

            // The page is going away; nothing is released or re-enabled.
            return new Promise(function () {});
        }

        const errors = validationErrorsIn(doc).concat(
            toasts
                .filter(function (toast) {
                    return toast.type === 'error';
                })
                .map(function (toast) {
                    return toast.message;
                }),
        );

        if (errors.length) {
            return refused(
                form,
                errors,
                { doc: doc, url: target.href },
                toasts.filter(function (toast) {
                    return toast.type !== 'error';
                }),
            );
        }

        return closeModals(current).then(function () {
            swap(doc, target.href);
            showToasts(toasts);

            return true;
        });
    }

    /**
     * The save did not go through.
     *
     * In a dialog, the dialog is the place to say so: it is what the person is
     * looking at, and everything they typed is still in it. Outside one there
     * is nothing to keep, so the page is redrawn as the server now has it -
     * the refusal may well be because the project changed underneath it - and
     * the reason is raised as a toast.
     */
    function refused(form, messages, answerPage, otherToasts) {
        const modal = form ? form.closest('.modal') : null;

        if (form) {
            clearBusy(form);

            form.dispatchEvent(new CustomEvent('workspace:failed', {
                bubbles: true,
                detail: { messages: messages },
            }));
        }

        if (modal && modal.classList.contains('show') && printInDialog(form, modal, messages)) {
            showToasts(otherToasts);

            return false;
        }

        if (answerPage) {
            const scope = workspace();

            closeModals(scope || document).then(function () {
                swap(answerPage.doc, answerPage.url);
            });
        }

        showToasts(otherToasts.concat(messages.map(function (message) {
            return { type: 'error', message: message };
        })));

        return false;
    }

    function send(form, submitter) {
        const method = ((submitter && submitter.getAttribute('formmethod')) || form.getAttribute('method') || 'GET').toUpperCase();
        const action = (submitter && submitter.getAttribute('formaction')) || form.action;
        const data = new FormData(form);

        // FormData(form) leaves out the button that was pressed; the server
        // may be reading it.
        if (submitter && submitter.name) {
            data.append(submitter.name, submitter.value);
        }

        const init = {
            method: method === 'GET' ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'text/html,application/xhtml+xml' },
        };

        let url = action;

        if (init.method === 'GET') {
            const target = new URL(action, global.location.href);

            target.search = new URLSearchParams(data).toString();
            url = target.href;
        } else {
            init.body = data;
        }

        busy = true;
        clearFormErrors(form);
        setBusy(form, submitter);

        return request(url, init, form).finally(function () {
            busy = false;

            if (form.isConnected) {
                clearBusy(form);
            }
        });
    }

    /** Redraw the workspace from the server, with no form involved. */
    function refresh(url) {
        if (busy) {
            return Promise.resolve(false);
        }

        busy = true;

        return request(url || global.location.href, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'text/html,application/xhtml+xml' },
        }, null).finally(function () {
            busy = false;
        });
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    function sameOriginHttp(url) {
        try {
            const target = new URL(url, global.location.href);

            return target.origin === global.location.origin && /^https?:$/.test(target.protocol);
        } catch (error) {
            return false;
        }
    }

    // On the window, not the document: a submit reaches the window last, so
    // every script with a say about a form - quotationSync asking about the
    // other half of the quotation, the team editor confirming a new lead,
    // scheduleRecovery sending Resume itself - has already had it, and one
    // that took the submit over has marked it handled.
    global.addEventListener('submit', function (event) {
        const form = event.target;
        const scope = workspace();

        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !scope || !scope.contains(form)) {
            return;
        }

        if (form.hasAttribute('data-workspace-native') || (form.target && form.target !== '_self')) {
            return;
        }

        const submitter = event.submitter || null;
        const action = (submitter && submitter.getAttribute('formaction')) || form.action;

        // `javascript:void(0)` on a view-only task: nothing to send.
        if (!sameOriginHttp(action)) {
            return;
        }

        event.preventDefault();

        if (busy) {
            return;
        }

        send(form, submitter);
    });

    // Links that only change what the workspace shows - a page of the
    // activity log - redraw it rather than the whole application.
    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[data-workspace-link]');
        const scope = workspace();

        if (!link || !scope || !scope.contains(link) || event.defaultPrevented) {
            return;
        }

        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        if (!sameOriginHttp(link.href)) {
            return;
        }

        event.preventDefault();

        const hash = new URL(link.href, global.location.href).hash;

        refresh(link.href).then(function (drawn) {
            const destination = drawn && hash ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;

            if (destination) {
                destination.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        });
    });

    global.projectWorkspace = {
        refresh: refresh,
    };
})(window);
