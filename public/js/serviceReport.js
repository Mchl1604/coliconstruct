/**
 * The Service Report dialog.
 *
 * The dialog is drawn inside the project's card, so it moves to the end of the
 * page to sit above everything. Printing copies the sheet into a holder beside
 * the page; serviceReport.css then hides everything but that holder.
 */
document.addEventListener('DOMContentLoaded', function() {
    if (window.serviceReportReady) {
        return;
    }

    window.serviceReportReady = true;

    document.querySelectorAll('[data-service-report-modal]').forEach(function(modal) {
        document.body.appendChild(modal);

        // A refused edit comes back with its dialog open on what was typed.
        if (modal.dataset.reopen === '1') {
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    });

    function clearPrint() {
        document.body.classList.remove('sr-printing');
        document.getElementById('serviceReportPrint')?.remove();
    }

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-service-report-print]');

        if (!button) {
            return;
        }

        const sheet = button.closest('[data-service-report-modal]').querySelector('[data-service-report-sheet]');
        const holder = document.createElement('div');

        clearPrint();
        holder.id = 'serviceReportPrint';
        holder.appendChild(sheet.cloneNode(true));
        document.body.appendChild(holder);
        document.body.classList.add('sr-printing');

        window.print();
    });

    window.addEventListener('afterprint', clearPrint);
});
