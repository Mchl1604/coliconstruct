{{-- One row of Inventory -> Materials, rendered by
     MaterialController::index() for serverTable.js. --}}
@php
    $isUsed = (int) $material->project_materials_count > 0;
    $payload = $material->toOption() + [
        'material_type_id' => (int) $material->material_type_id,
        'is_used' => $isUsed,
        'unit_price' => $material->unit_price === null ? null : (float) $material->unit_price,
    ];
@endphp
<tr class="{{ $material->is_active ? '' : 'text-secondary' }}">
    <td>
        <span class="material-name">{{ $material->name }}</span>
        @if ($material->description)
            <div class="material-meta">{{ $material->description }}</div>
        @endif
    </td>
    <td>
        @if ($material->brandName())
            <span class="material-brand ms-0">{{ $material->brandName() }}</span>
        @else
            <span class="text-secondary small">&ndash;</span>
        @endif
    </td>
    <td class="small">
        {{ $material->type?->type_name }}
        @if ($material->subtypeName())
            <div class="material-meta">{{ $material->subtypeName() }}</div>
        @endif
    </td>
    <td><span class="material-unit-chip" title="{{ $material->unit->label() }}">{{ $material->unit->value }}</span></td>
    <td class="text-end text-nowrap">
        @if ($material->unit_price !== null)
            &#8369; {{ number_format((float) $material->unit_price, 2) }}
        @else
            <span class="text-secondary small">&ndash;</span>
        @endif
    </td>
    <td class="text-center text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2"
            data-material-edit='@json($payload)' title="Edit">
            <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2"
            data-material-delete="{{ $material->material_id }}"
            data-material-label="{{ $material->displayName() }}"
            @if ($isUsed) disabled title="Used on projects." @else title="Remove" @endif>
            <i class="bi bi-trash3" aria-hidden="true"></i>
        </button>
    </td>
</tr>
