/**
 * Project Activity -> Checklist. Each tick saves on its own. A refused save
 * puts the box back, so the screen only ever shows what was saved.
 */
document.addEventListener('DOMContentLoaded', function() {
    const root = document.querySelector('[data-project-checklist]');

    if (!root) {
        return;
    }

    const errorBox = root.querySelector('[data-checklist-error]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function showError(message) {
        errorBox.textContent = message || '';
        errorBox.classList.toggle('d-none', !message);
    }

    root.addEventListener('change', function(event) {
        const box = event.target.closest('[data-checklist-item]');

        if (!box) {
            return;
        }

        const unit = box.closest('[data-checklist-unit]');
        const done = box.checked;

        box.disabled = true;
        showError('');

        fetch(root.dataset.saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ detail_id: Number(unit.dataset.detailId), item: box.value, done: done }),
        }).then(function(response) {
            return response.ok ? response.json() : Promise.reject();
        }).then(function(payload) {
            unit.querySelector('[data-checklist-count]').textContent = payload.done_count;
        }).catch(function() {
            box.checked = !done;
            showError('Not saved. Please try again.');
        }).finally(function() {
            box.disabled = false;
        });
    });
});
