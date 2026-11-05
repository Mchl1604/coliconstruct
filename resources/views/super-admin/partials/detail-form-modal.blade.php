{{--
    One fixed detail form, viewed from Configuration -> Project Types -> Detail
    Forms. Read-only: detailForms.js fills it from the form chosen.
--}}
<div class="modal fade" id="detailFormModal" tabindex="-1" aria-hidden="true" aria-labelledby="detailFormModalTitle"
    data-detail-form-modal>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailFormModalTitle" data-detail-form-title>Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-info">
                            <tr>
                                <th>Field</th>
                                <th>Filled</th>
                                <th>Options</th>
                            </tr>
                        </thead>
                        <tbody data-detail-field-list></tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
