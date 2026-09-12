/**
 * The shell around every page in the two staff portals: the sidebar and the
 * toasts.
 *
 * Both layouts used to carry their own inline copy of this, and the two had
 * already drifted - the portal's toggle never said whether the sidebar was
 * expanded. One file, loaded by superadminNav and portalNav alike.
 *
 * The sidebar remembers being collapsed. It used to open again on every page
 * load, which in an application where most saves are a form post and a
 * redirect meant it opened again after every save as well. The preference is
 * kept in localStorage - it is how this person likes the screen, not something
 * about one page - and the layout applies it before the sidebar is drawn (see
 * the inline script at the top of `[data-admin-shell]`), so a collapsed
 * sidebar never flashes open first.
 *
 * Toasts: the ones the server flashed are shown on load, as before, and
 * `adminShell.showToast()` raises one from script in the same markup. A page
 * that saves with fetch and then has to send the person somewhere else can
 * hand its toasts to `adminShell.carryToasts()`, and the next page shows them.
 */
(function (global) {
    'use strict';

    // The inline script in each layout reads the same key; the two must agree.
    const COLLAPSED_KEY = 'adminShell.sidebarCollapsed';
    const CARRIED_TOASTS_KEY = 'adminShell.carriedToasts';
    const DESKTOP_WIDTH = 992;

    // The same four the flash-toasts component draws.
    const TOAST_CLASSES = {
        success: 'bg-success text-white',
        error: 'bg-danger text-white',
        warning: 'bg-warning text-dark',
        info: 'bg-info text-dark',
    };

    /**
     * Storage can be switched off, full, or refused outright in a private
     * window. None of that is a reason for the sidebar or a toast to break.
     */
    function store(kind) {
        try {
            return global[kind] || null;
        } catch (error) {
            return null;
        }
    }

    function read(kind, key) {
        try {
            const storage = store(kind);

            return storage ? storage.getItem(key) : null;
        } catch (error) {
            return null;
        }
    }

    function write(kind, key, value) {
        try {
            const storage = store(kind);

            if (!storage) {
                return;
            }

            if (value === null) {
                storage.removeItem(key);
            } else {
                storage.setItem(key, value);
            }
        } catch (error) {
            // Not remembered, which is all that is lost.
        }
    }

    // ------------------------------------------------------------------
    // Toasts
    // ------------------------------------------------------------------

    function showToastElement(element) {
        if (!global.bootstrap) {
            return;
        }

        global.bootstrap.Toast.getOrCreateInstance(element).show();
    }

    /**
     * One toast, drawn exactly as x-flash-toasts draws a flashed one.
     */
    function showToast(type, message) {
        const container = document.querySelector('[data-toast-container]');

        if (!container || !message) {
            return;
        }

        const variant = TOAST_CLASSES[type] ? type : 'info';
        const isDark = TOAST_CLASSES[variant].indexOf('text-dark') !== -1;

        const toast = document.createElement('div');
        toast.className = 'toast align-items-center border-0 ' + TOAST_CLASSES[variant];
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        toast.setAttribute('data-bs-autohide', 'true');
        toast.setAttribute('data-bs-delay', '3000');
        toast.setAttribute('data-toast-type', variant);

        const row = document.createElement('div');
        row.className = 'd-flex';

        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close ' + (isDark ? '' : 'btn-close-white ') + 'me-2 m-auto';
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', 'Close');

        row.appendChild(body);
        row.appendChild(close);
        toast.appendChild(row);
        container.appendChild(toast);

        // Raised from script, so nothing else would ever take it away again.
        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });

        showToastElement(toast);
    }

    /**
     * Toasts for the page this one is about to navigate to, as
     * [{ type, message }]. Kept for the one navigation only.
     */
    function carryToasts(toasts) {
        const list = (toasts || []).filter(function (toast) {
            return toast && toast.message;
        });

        write('sessionStorage', CARRIED_TOASTS_KEY, list.length ? JSON.stringify(list) : null);
    }

    function showCarriedToasts() {
        const raw = read('sessionStorage', CARRIED_TOASTS_KEY);

        if (!raw) {
            return;
        }

        write('sessionStorage', CARRIED_TOASTS_KEY, null);

        let list = [];

        try {
            list = JSON.parse(raw);
        } catch (error) {
            list = [];
        }

        (Array.isArray(list) ? list : []).forEach(function (toast) {
            showToast(toast.type, toast.message);
        });
    }

    // ------------------------------------------------------------------
    // Sidebar
    // ------------------------------------------------------------------

    function initSidebar() {
        const shell = document.querySelector('[data-admin-shell]');
        const toggle = document.querySelector('[data-sidebar-toggle]');
        const backdrop = document.querySelector('[data-sidebar-backdrop]');

        if (!shell || !toggle || !backdrop) {
            return;
        }

        function isDesktop() {
            return global.innerWidth >= DESKTOP_WIDTH;
        }

        function setSidebarOpen(isOpen) {
            shell.classList.toggle('sidebar-open', isOpen);
            toggle.setAttribute('aria-expanded', String(isOpen));
        }

        function setSidebarCollapsed(isCollapsed) {
            shell.classList.toggle('sidebar-collapsed', isCollapsed);
            toggle.setAttribute('aria-expanded', String(!isCollapsed));
            write('localStorage', COLLAPSED_KEY, isCollapsed ? '1' : '0');
        }

        // The layout may already have collapsed it before this ran.
        if (isDesktop()) {
            toggle.setAttribute('aria-expanded', String(!shell.classList.contains('sidebar-collapsed')));
        }

        toggle.addEventListener('click', function () {
            if (isDesktop()) {
                setSidebarCollapsed(!shell.classList.contains('sidebar-collapsed'));
                setSidebarOpen(false);

                return;
            }

            setSidebarOpen(!shell.classList.contains('sidebar-open'));
        });

        backdrop.addEventListener('click', function () {
            setSidebarOpen(false);
        });

        global.addEventListener('resize', function () {
            if (isDesktop()) {
                setSidebarOpen(false);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.toast').forEach(showToastElement);
        showCarriedToasts();
        initSidebar();
    });

    global.adminShell = {
        showToast: showToast,
        carryToasts: carryToasts,
    };
})(window);
