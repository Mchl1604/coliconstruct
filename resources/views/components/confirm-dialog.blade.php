{{--
    The dialog a page asks a destructive question with.

    Included once per page that needs one, and driven by window.confirmDialog()
    in /js/confirmDialog.js - see that file for the contract.

    It exists because window.confirm() cannot be styled, cannot carry a second
    line of detail, and cannot show the reason a server gave for refusing the
    thing it just asked about. A browser dialog also looks like something the
    page did by accident, which is the opposite of what an irreversible action
    should look like.

    Deliberately NOT included on Configuration: that page has its own
    confirmation modal, which owns the request it is confirming so that a
    refusal can be rendered inside it. Two on one page would be two of
    everything that can drift.
--}}
<div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true" aria-labelledby="appConfirmTitle"
    data-app-confirm>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" data-app-confirm-header>
                <h5 class="modal-title" id="appConfirmTitle" data-app-confirm-title>Are you sure?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="mb-0" data-app-confirm-body></p>

                {{-- The consequence, when there is one worth setting apart from
                     the question itself - who comes off a project, what stops
                     being editable. --}}
                <div class="app-confirm-detail mt-3 d-none" data-app-confirm-detail></div>

                {{-- Only ever filled when the caller handed the dialog the
                     request as well as the question. --}}
                <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-app-confirm-error></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-app-confirm-cancel>
                    Cancel
                </button>
                <button type="button" class="btn btn-primary px-3" data-app-confirm-accept>
                    <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                        data-app-confirm-spinner></span>
                    <span data-app-confirm-label>Confirm</span>
                </button>
            </div>
        </div>
    </div>
</div>
