{{--
    Configuration -> Inventory Settings.

    The lists every material is filed under: its types, the sub-types within
    each, and its brands. Kept here, beside the rest of the system's settings,
    by Super Admin, Admin Officer and Purchasing Admin - the people who reach
    the Inventory page. The materials themselves, and their stock, stay on
    Inventory.

    Driven by materialSettings.js, from materials.types.index and
    materials.brands.index. The Sub-types and View Type dialogs live with the
    page's other dialogs - see material-settings-modals.
--}}
<div class="materials-panel" id="materialSettingsPane">

    <div class="mb-3">
        <h5 class="fw-bold mb-1 project-types-heading">
            <i class="bi bi-box-seam me-1" aria-hidden="true"></i>
            Inventory Settings
        </h5>
        <p class="text-secondary small mb-0">Material types, sub-types and brands.</p>
    </div>

    <div class="alert alert-danger d-none" role="alert" data-material-settings-error></div>
    <div class="alert alert-success d-none" role="alert" data-material-settings-success></div>

    {{-- The groups: renamed and removed in place, added from the header.
         View lists a type's materials in materialTypeViewModal; Sub-types
         keeps the kinds within one in materialSubtypeModal. --}}
    <div class="border rounded-3 p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h6 class="fw-bold mb-0">Material Types</h6>
            <form class="d-flex gap-2" data-material-type-add-form novalidate>
                <input type="text" class="form-control form-control-sm" maxlength="100"
                    placeholder="New type" aria-label="New material type" data-material-type-name>
                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Add
                </button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="materialTypesTable">
                <thead class="table-info">
                    <tr>
                        <th>Type</th>
                        <th>Sub-types</th>
                        <th>Materials</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody data-material-types>
                    <tr>
                        <td colspan="4" class="text-secondary small">Loading types&hellip;</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- The brands, kept the same way as the types. --}}
    <div class="border rounded-3 p-3 mt-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h6 class="fw-bold mb-0">Brands</h6>
            <form class="d-flex gap-2" data-brand-add-form novalidate>
                <input type="text" class="form-control form-control-sm" maxlength="100"
                    placeholder="New brand" aria-label="New brand" data-brand-name>
                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Add
                </button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="brandsTable">
                <thead class="table-info">
                    <tr>
                        <th>Brand</th>
                        <th>Materials</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody data-brands>
                    <tr>
                        <td colspan="3" class="text-secondary small">Loading brands&hellip;</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
