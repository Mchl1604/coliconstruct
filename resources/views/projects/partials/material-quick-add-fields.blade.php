{{--
    The fields for adding a material to the master list from a project.

    No `name` attributes on purpose: these sit inside other forms (the Add
    Material dialog) and are sent by materialPicker.quickAdd() as JSON, never
    with the form around them. Read through data-quick-add-field.

    Expects $types (from ProjectMaterialController::quickAddOptions()), $units
    and $idPrefix, which keeps two copies on one page apart.
--}}
<div class="row g-2" data-quick-add-fields>
    @if ($types === [])
        <div class="col-12">
            <div class="alert alert-warning small py-2 mb-0">
                No material types yet. Ask a Super Admin.
            </div>
        </div>
    @endif

    <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="{{ $idPrefix }}Name">Name</label>
        <input type="text" class="form-control" id="{{ $idPrefix }}Name" maxlength="150"
            placeholder="e.g. Split-Type Inverter 1.5 HP" data-quick-add-field="name">
    </div>

    <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1" for="{{ $idPrefix }}Brand">Brand</label>
        <input type="text" class="form-control" id="{{ $idPrefix }}Brand" maxlength="100"
            placeholder="Optional" data-quick-add-field="brand">
    </div>

    <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1" for="{{ $idPrefix }}Type">Type</label>
        <select class="form-select" id="{{ $idPrefix }}Type" data-quick-add-field="material_type_id">
            <option value="">Choose type</option>
            @foreach ($types as $type)
                <option value="{{ $type['material_type_id'] }}">{{ $type['type_name'] }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1" for="{{ $idPrefix }}Unit">Unit</label>
        <select class="form-select" id="{{ $idPrefix }}Unit" data-quick-add-field="unit">
            <option value="">Choose unit</option>
            @foreach ($units as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1" for="{{ $idPrefix }}Description">Description</label>
        <input type="text" class="form-control" id="{{ $idPrefix }}Description" maxlength="255"
            placeholder="Optional" data-quick-add-field="description">
    </div>

    <div class="col-12">
        <div class="alert alert-danger small py-2 mb-0 d-none" role="alert" data-quick-add-error></div>
    </div>
</div>
