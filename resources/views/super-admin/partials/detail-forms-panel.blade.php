{{--
    Configuration -> System Settings -> Project Types -> Detail Forms.

    The fixed forms a project type asks for, read-only. Driven by detailForms.js
    from detail-forms.index; a form opens in detail-form-modal.
--}}
<div class="border rounded-3 p-3 mt-4" id="detailFormsPane">
    <div class="mb-2">
        <h6 class="fw-bold mb-1">
            <i class="bi bi-ui-checks me-1" aria-hidden="true"></i>
            Detail Forms
        </h6>
        <p class="text-secondary small mb-0">Fixed forms for each type.</p>
    </div>

    <div class="alert alert-danger small py-2 d-none" role="alert" data-detail-forms-error></div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-info">
                <tr>
                    <th>Form</th>
                    <th>Fields</th>
                    <th>Used by</th>
                    <th class="text-center">View</th>
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
