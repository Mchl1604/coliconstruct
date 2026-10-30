{{--
    Configuration -> System Settings -> Project Types -> Detail Forms.

    The forms a project type asks for in the create wizard's Units step -
    Installation, Repair, Cleaning, or one the Super Admin builds. Driven by
    detailForms.js from detail-forms.index; the builder itself is
    detail-form-modal, with the page's other dialogs.
--}}
<div class="border rounded-3 p-3 mt-4" id="detailFormsPane">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h6 class="fw-bold mb-1">
                <i class="bi bi-ui-checks me-1" aria-hidden="true"></i>
                Detail Forms
            </h6>
            <p class="text-secondary small mb-0">The project description per type.</p>
        </div>

        <button type="button" class="btn btn-sm btn-primary" data-detail-form-new>
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
            New Form
        </button>
    </div>

    <div class="alert alert-danger small py-2 d-none" role="alert" data-detail-forms-error></div>
    <div class="alert alert-success small py-2 d-none" role="alert" data-detail-forms-success></div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-info">
                <tr>
                    <th>Form</th>
                    <th>Fields</th>
                    <th>Used by</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody data-detail-forms-body>
                <tr>
                    <td colspan="4" class="text-secondary small">Loading forms&hellip;</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
