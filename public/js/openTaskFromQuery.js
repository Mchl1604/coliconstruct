/**
 * Opens a task's details modal when the page is reached with ?task=<id>.
 *
 * Tasks live in per-task modals rather than on their own pages, so this is
 * what makes a task notification land on the task itself rather than just the
 * list it is somewhere in.
 */
document.addEventListener('DOMContentLoaded', function() {
    const taskId = new URLSearchParams(window.location.search).get('task');

    if (!taskId) {
        return;
    }

    const modal = document.getElementById('taskModal' + taskId);

    if (!modal || !window.bootstrap) {
        return;
    }

    bootstrap.Modal.getOrCreateInstance(modal).show();

    // The task may be inside a collapsed project card further down the page.
    modal.addEventListener('shown.bs.modal', function() {
        modal.querySelector('.modal-content')?.scrollIntoView({ block: 'nearest' });
    }, { once: true });
});
