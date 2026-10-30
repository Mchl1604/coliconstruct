{{--
    The dialogs Configuration -> Inventory Settings opens: one type's
    sub-types, and the materials filed under one type. Out here with the
    page's other dialogs rather than inside the panel, which sits in a tab.
--}}

{{-- Sub-types: the kinds within one type, kept the way the types and brands
     are - renamed and removed in place, added from the header. Filled by
     materialSettings.js from the type list it already holds. --}}
<div class="modal fade" id="materialSubtypeModal" tabindex="-1" aria-hidden="true"
    aria-labelledby="materialSubtypeTitle" data-material-subtype-modal>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="materialSubtypeTitle" data-material-subtype-title>Sub-types</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger small py-2 d-none" role="alert" data-material-subtype-error></div>

                <form class="d-flex gap-2 mb-3" data-material-subtype-add-form novalidate>
                    <input type="text" class="form-control form-control-sm" maxlength="100"
                        placeholder="New sub-type" aria-label="New sub-type" data-material-subtype-name>
                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        Add
                    </button>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sub-type</th>
                                <th>Materials</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody data-material-subtype-rows></tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- View Type: the materials in one type, filled by materialSettings.js
     from materials.types.show. --}}
<div class="modal fade" id="materialTypeViewModal" tabindex="-1" aria-hidden="true"
    aria-labelledby="materialTypeViewTitle" data-material-type-view-modal>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="materialTypeViewTitle" data-material-type-view-title>Materials</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Material</th>
                                <th>Brand</th>
                                <th>Unit</th>
                            </tr>
                        </thead>
                        <tbody data-material-type-view-rows></tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
