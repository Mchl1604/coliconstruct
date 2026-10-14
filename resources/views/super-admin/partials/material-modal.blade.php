{{--
    Add / Edit Material, for System Settings -> Materials. One dialog for both:
    materials.js fills it from the row being edited, or empties it for a new
    one. Sent as JSON, so the fields carry data attributes rather than relying
    on a form submit.
--}}
<div class="modal fade" id="materialModal" tabindex="-1" aria-hidden="true" aria-labelledby="materialModalTitle"
    data-material-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form novalidate data-material-form>
                <input type="hidden" data-material-field="material_id">

                <div class="modal-header">
                    <h5 class="modal-title" id="materialModalTitle" data-material-modal-title>Add Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-danger small py-2 d-none" role="alert" data-material-modal-error></div>

                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1" for="materialName">Name</label>
                            <input type="text" class="form-control" id="materialName" maxlength="150" required
                                placeholder="e.g. Split-Type Inverter 1.5 HP" data-material-field="name">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="materialBrand">Brand</label>
                            <input type="text" class="form-control" id="materialBrand" maxlength="100"
                                placeholder="Optional" data-material-field="brand">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="materialType">Type</label>
                            <select class="form-select" id="materialType" required data-material-field="material_type_id">
                                <option value="">Choose type</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="materialUnit">Unit</label>
                            <select class="form-select" id="materialUnit" required data-material-field="unit">
                                <option value="">Choose unit</option>
                                @foreach (\App\Support\MaterialUnit::options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text d-none" data-material-unit-locked>Locked once used.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="materialDescription">Description</label>
                            <input type="text" class="form-control" id="materialDescription" maxlength="255"
                                placeholder="Size, model, notes" data-material-field="description">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-material-save>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-material-save-spinner></span>
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
