{{--
    One unit of a detail form: its title, remove button and fields.

    $detailId names the saved row being edited, which is what keeps the values
    of fields archived since - see ProjectDetailForms.
--}}
@props([
    'fields',
    'prefix',
    'values' => [],
    'detailId' => null,
    'title' => null,
    'removable' => false,
])

<div class="detail-form-row border rounded-3 p-3 mb-3" data-detail-row>
    @if ($title || $removable)
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold small" data-detail-row-title>{{ $title }}</span>

            @if ($removable)
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" data-detail-row-remove
                    title="Remove unit">
                    <i class="bi bi-trash3" aria-hidden="true"></i>
                </button>
            @endif
        </div>
    @endif

    @if ($detailId)
        <input type="hidden" name="{{ $prefix }}[{{ \App\Services\ProjectDetailForms::DETAIL_ID_KEY }}]"
            value="{{ $detailId }}">
    @endif

    <x-detail-form-fields :fields="$fields" :prefix="$prefix" :values="$values" />
</div>
