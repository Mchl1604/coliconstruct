{{--
    The detail form builder, for Configuration -> Project Types -> Detail
    Forms. One dialog for New and Edit: detailForms.js fills it from the form
    being edited, or empties it, and draws the field rows and the preview.
--}}
<div class="modal fade" id="detailFormModal" tabindex="-1" aria-hidden="true" aria-labelledby="detailFormModalTitle"
    data-detail-form-modal data-field-types='@json(\App\Support\DetailFieldType::options())'
    data-field-scopes='@json(\App\Support\DetailFieldScope::options())'>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form novalidate data-detail-form-builder>
                <div class="modal-header">
                    <h5 class="modal-title" id="detailFormModalTitle" data-detail-form-title>New Form</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-danger small py-2 d-none" role="alert" data-detail-form-error></div>

                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold mb-1" for="detailFormName">Form name</label>
                                <input type="text" class="form-control" id="detailFormName" maxlength="100" required
                                    placeholder="e.g. Ducting Details" data-detail-form-name>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold mb-0">Fields</h6>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-detail-field-add>
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                    Add Field
                                </button>
                            </div>

                            <div class="detail-field-list" data-detail-field-list></div>

                            <p class="form-text mb-0">Per unit fields repeat for each unit.</p>
                        </div>

                        <div class="col-lg-5">
                            <h6 class="fw-bold mb-2">Preview</h6>
                            <div class="detail-form-preview border rounded-3 p-3 bg-light" data-detail-form-preview></div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-detail-form-save>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-detail-form-save-spinner></span>
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
