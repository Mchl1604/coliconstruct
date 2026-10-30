{{-- One row of Inventory -> Stock, rendered by StockController::index() for
     serverTable.js. On hand comes from the row's own withOnHand() column;
     Reserved and Available from StockAvailability, as $figures. --}}
@php
    $onHand = $material->onHand();
    $reserved = (float) ($figures['reserved'] ?? 0);
    $available = (float) ($figures['available'] ?? $onHand);
    $status = $material->stockStatus($onHand);
    $unit = $material->unit;
    $payload = [
        'material_id' => (int) $material->material_id,
        'label' => $material->displayName(),
        'unit' => $unit->value,
        'allows_fractions' => $unit->allowsFractions(),
        'on_hand' => $onHand,
    ];
@endphp
<tr class="{{ $material->is_active ? '' : 'text-secondary' }}">
    <td>
        <span class="material-name">{{ $material->name }}</span>
        @if ($material->brandName())
            <span class="material-brand">{{ $material->brandName() }}</span>
        @endif
        @if ($material->description)
            <div class="material-meta">{{ $material->description }}</div>
        @endif
    </td>
    <td class="small">
        {{ $material->type?->type_name }}
        @if ($material->subtypeName())
            <div class="material-meta">{{ $material->subtypeName() }}</div>
        @endif
    </td>
    <td class="text-nowrap">
        <span class="material-qty fw-semibold {{ $onHand <= 0 ? 'is-zero' : '' }}">{{ $unit->format($onHand) }}</span>
        <span class="material-unit-chip" title="{{ $unit->label() }}">{{ $unit->value }}</span>
    </td>
    <td class="text-nowrap small {{ $reserved > 0 ? '' : 'text-secondary' }}">{{ $unit->format($reserved) }}</td>
    <td class="text-nowrap">
        <span class="material-qty {{ $available < 0 ? 'text-danger fw-semibold' : '' }}">{{ $unit->format($available) }}</span>
    </td>
    <td class="text-nowrap small">
        @if ($material->reorder_level !== null)
            {{ $unit->format($material->reorder_level) }} {{ $unit->value }}
        @else
            <span class="text-secondary">&ndash;</span>
        @endif
    </td>
    <td><span class="stock-status is-{{ $status }}">{{ \App\Models\Material::STOCK_STATUSES[$status] }}</span></td>
    <td class="text-center text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-success py-1 px-2"
            data-stock-action="in" data-stock-material='@json($payload)' title="Stock In">
            <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-warning py-1 px-2"
            data-stock-action="out" data-stock-material='@json($payload)'
            @if ($onHand <= 0) disabled title="None on hand." @else title="Stock Out" @endif>
            <i class="bi bi-box-arrow-up" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2"
            data-stock-action="adjust" data-stock-material='@json($payload)' title="Adjust Count">
            <i class="bi bi-sliders" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-info py-1 px-2"
            data-stock-history="{{ $material->material_id }}" data-stock-label="{{ $material->displayName() }}"
            title="History">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
        </button>
    </td>
</tr>
