{{--
    The Materials page.

    The list a project's materials are picked from - names, brands, types and
    sub-types, units and reorder levels. The stock held of each is the
    Inventory page's. A used material cannot be removed, so the projects that
    used it can still say what it was.

    The types, sub-types and brands a material is filed under are kept in
    Configuration -> Inventory Settings; this page only picks from them.

    Driven by materials.js: the table loads a page at a time from
    materials.index (serverTable.js), and the filters and the dialog's choices
    from materials.types.index and materials.brands.index. The Add / Edit
    dialog lives with the page's other dialogs - see material-modal.
--}}
<div class="materials-panel" id="materialsPane">

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">Materials</h4>
            <p class="text-secondary small mb-0">
                What projects pick from.
                <a href="{{ route('super-admin.configuration.index') }}#inventorySettingsPane">Edit types and brands</a>
            </p>
        </div>

        <button type="button" class="btn btn-primary" data-material-new>
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
            Add Material
        </button>
    </div>

    <div class="alert alert-danger d-none" role="alert" data-materials-error></div>
    <div class="alert alert-success d-none" role="alert" data-materials-success></div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-4">
            <div class="row g-2 mb-2">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1" for="materialsTypeFilter">Type</label>
                    <select class="form-select form-select-sm" id="materialsTypeFilter" data-materials-type-filter>
                        <option value="">All types</option>
                    </select>
                </div>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1" for="materialsSubtypeFilter">Sub-type</label>
                    {{-- The chosen type's sub-types; disabled until a type is chosen. --}}
                    <select class="form-select form-select-sm" id="materialsSubtypeFilter" data-materials-subtype-filter disabled>
                        <option value="">All sub-types</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="materialsTable">
                    <thead class="table-info">
                        <tr>
                            <th>Material</th>
                            <th>Brand</th>
                            <th>Type</th>
                            <th>Unit</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    {{-- Filled a page at a time by serverTable.js from
                         MaterialController::index(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
