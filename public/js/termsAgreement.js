/**
 * Opens the Terms and Conditions agreement dialog as soon as the page is ready.
 *
 * The dialog is rendered by the layout only when the signed-in client has not
 * accepted the current version, so its presence in the markup IS the decision -
 * there is nothing to check here beyond whether it is on the page.
 *
 * Nothing about the requirement depends on this file. A client with scripting
 * switched off, or one who deletes the element, is still held out of their
 * portal by EnsureTermsAreAccepted on the server; all they lose is the dialog
 * that would have explained why and offered the way through.
 */
document.addEventListener('DOMContentLoaded', function() {
    const modalElement = document.querySelector('[data-terms-agreement]');

    if (!modalElement || typeof bootstrap === 'undefined') {
        return;
    }

    // static backdrop and no keyboard dismissal are set in the markup, so the
    // only ways out of this are the two buttons it carries.
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
});
