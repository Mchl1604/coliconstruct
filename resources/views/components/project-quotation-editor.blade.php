{{--
    The quotation lines, as typed in the create wizard and the edit dialog.
    Driven by quotationLines.js, which posts each line as quotation_items[n].

    $rows: ProjectQuotation::editorRows().
    $materialUrl: where the material search looks.
    $discountPercent, $specialDiscount: the discounts, blank for none.
    $terms: the printed terms and signatories, by ProjectQuotation::TERM_FIELDS.
--}}
@props([
    'rows' => [],
    'materialUrl',
    'discountPercent' => '',
    'specialDiscount' => '',
    'terms' => [],
])

@php
    $fieldId = 'quotationDiscount'.\Illuminate\Support\Str::random(6);
@endphp

<div data-quotation-lines data-material-url="{{ $materialUrl }}" data-rows='@json($rows)'>
    <div class="alert alert-danger small py-2 d-none" role="alert" data-quotation-error></div>

    <div class="row g-2 align-items-end mb-3">
        <div class="col-md">
            <label class="form-label">Add Material</label>
            <input type="text" class="form-control" autocomplete="off" placeholder="Search by name, brand or type"
                aria-label="Add material" data-quotation-material-search>
        </div>
        <div class="col-md-auto">
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
                    <td colspan="4" class="text-end">Amount</td>
                    <td class="text-end text-nowrap">&#8369; <span data-quotation-subtotal>0.00</span></td>
                    <td></td>
                </tr>
                <tr>
                    <td colspan="4" class="text-end">
                        <label for="{{ $fieldId }}Percent" class="col-form-label py-0">Discount %</label>
                    </td>
                    <td>
                        <input type="number" id="{{ $fieldId }}Percent" name="quotation_discount_percent"
                            class="form-control form-control-sm text-end" min="0" max="100" step="0.01"
                            value="{{ $discountPercent }}" placeholder="0" data-quotation-discount-percent>
                    </td>
                    <td></td>
                </tr>
                <tr>
                    <td colspan="4" class="text-end">Discount Amount</td>
                    <td class="text-end text-nowrap">&#8369; <span data-quotation-discount-amount>0.00</span></td>
                    <td></td>
                </tr>
                <tr>
                    <td colspan="4" class="text-end">
                        <label for="{{ $fieldId }}Special" class="col-form-label py-0">Special Discount</label>
                    </td>
                    <td>
                        <input type="number" id="{{ $fieldId }}Special" name="quotation_special_discount"
                            class="form-control form-control-sm text-end" min="0" step="0.01"
                            value="{{ $specialDiscount }}" placeholder="0.00" data-quotation-special-discount>
                    </td>
                    <td></td>
                </tr>
                <tr>
                    <th colspan="4" class="text-end">Total Amount</th>
                    <th class="text-end text-nowrap">&#8369; <span data-quotation-total>0.00</span></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="text-secondary small mb-0" data-quotation-empty>No lines yet.</p>

    <div class="row g-3 mt-1">
        @foreach ([
            'quotation_notes' => 'Notes',
            'quotation_inclusions' => 'Inclusions',
            'quotation_exclusions' => 'Exclusions',
            'quotation_payment_terms' => 'Terms of Payment',
        ] as $field => $label)
            <div class="col-md-6">
                <label for="{{ $fieldId.$field }}" class="form-label">{{ $label }}</label>
                <textarea id="{{ $fieldId.$field }}" name="{{ $field }}" class="form-control" rows="2"
                    maxlength="{{ \App\Services\ProjectQuotation::TERM_FIELDS[$field] }}">{{ $terms[$field] ?? '' }}</textarea>
            </div>
        @endforeach

        @foreach (['quotation_prepared_by' => 'Prepared By', 'quotation_approved_by' => 'Approved By'] as $field => $label)
            <div class="col-md-6">
                <label for="{{ $fieldId.$field }}" class="form-label">{{ $label }}</label>
                <input type="text" id="{{ $fieldId.$field }}" name="{{ $field }}" class="form-control"
                    maxlength="{{ \App\Services\ProjectQuotation::TERM_FIELDS[$field] }}" value="{{ $terms[$field] ?? '' }}">
            </div>
        @endforeach
    </div>
</div>
