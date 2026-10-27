{{--
    Configuration -> System Settings -> Materials.

    The list a project's materials are picked from - names, brands, groups and
    units. Deliberately not an inventory: nothing here counts stock. A used
    material cannot be removed, so the projects that used it can still say
    what it was.

    Driven by materials.js: the table loads a page at a time from
    materials.index (serverTable.js), and the types below it from
    materials.types.index. The Add / Edit and View Type dialogs live with the
    page's other dialogs - see material-modal.
--}}
<div class="materials-panel" id="materialsPane">

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-1 project-types-heading">
                <i class="bi bi-box-seam me-1" aria-hidden="true"></i>
                Materials
            </h5>
            <p class="text-secondary small mb-0">
                A list to pick from, not stock.
            </p>
        </div>

        <button type="button" class="btn btn-primary" data-material-new>
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
            Add Material
        </button>
    </div>

    <div class="alert alert-danger d-none" role="alert" data-materials-error></div>
    <div class="alert alert-success d-none" role="alert" data-materials-success></div>

    <div class="row g-2 mb-2">
        <div class="col-sm-6 col-md-4">
            <label class="form-label small fw-semibold mb-1" for="materialsTypeFilter">Type</label>
            <select class="form-select form-select-sm" id="materialsTypeFilter" data-materials-type-filter>
                <option value="">All types</option>
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

    {{-- The groups: renamed and removed in place, added from the header.
         View lists a type's materials in materialTypeViewModal. --}}
    <div class="border rounded-3 p-3 mt-4">
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
                        <th>Materials</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody data-material-types>
                    <tr>
                        <td colspan="3" class="text-secondary small">Loading types&hellip;</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
