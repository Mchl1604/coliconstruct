{{--
    The quotation lines, as typed in the create wizard and the edit dialog.
    Driven by quotationLines.js, which posts each line as quotation_items[n].

    $rows: ProjectQuotation::editorRows().
    $materialUrl: where the material search looks.
--}}
@props([
    'rows' => [],
    'materialUrl',
])

<div data-quotation-lines data-material-url="{{ $materialUrl }}" data-rows='@json($rows)'>
    <div class="alert alert-danger small py-2 d-none" role="alert" data-quotation-error></div>

    <div class="row g-2 align-items-end mb-3">
        <div class="col-md">
            <label class="form-label">Add Material</label>
            <input type="text" class="form-control" autocomplete="off" placeholder="Search by name, brand or type"
                aria-label="Add material" data-quotation-material-search>
        </div>
        <div class="col-md-auto">
            <button type="button" class="btn btn-outline-primary" data-quotation-add="labor">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                Add Labor
            </button>
            <button type="button" class="btn btn-outline-primary" data-quotation-add="other">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                Add Other
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-2">
            <thead class="table-light">
                <tr>
                    <th>Description</th>
                    <th style="width: 7rem">Quantity</th>
                    <th style="width: 6rem">Unit</th>
                    <th style="width: 9rem">Unit Price</th>
                    <th class="text-end" style="width: 9rem">Amount</th>
                    <th class="text-center"><span class="visually-hidden">Remove</span></th>
                </tr>
            </thead>
            <tbody data-quotation-rows></tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="text-end">Total</th>
                    <th class="text-end text-nowrap">&#8369; <span data-quotation-total>0.00</span></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="text-secondary small mb-0" data-quotation-empty>No lines yet.</p>
</div>
