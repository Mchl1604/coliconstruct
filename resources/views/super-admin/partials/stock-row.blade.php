{{-- One row of Inventory -> Stock, rendered by StockController::index() for
     serverTable.js. Reserved and Available come from StockAvailability, as
     $figures, and Total is the two together. The status reads Available. --}}
@php
    $onHand = $material->onHand();
    $reserved = (float) ($figures['reserved'] ?? 0);
    $available = (float) ($figures['available'] ?? $onHand);
    $total = $reserved + $available;
    $status = $material->stockStatus($available);
    $unit = $material->unit;
    $payload = [
        'material_id' => (int) $material->material_id,
        'label' => $material->displayName(),
        'unit' => $unit->value,
        'allows_fractions' => $unit->allowsFractions(),
        'on_hand' => $onHand,
        'reserved' => $reserved,
        'available' => $available,
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
        <span class="material-qty fw-semibold {{ $total <= 0 ? 'is-zero' : '' }}">{{ $unit->format($total) }}</span>
        <span class="material-unit-chip" title="{{ $unit->label() }}">{{ $unit->value }}</span>
    </td>
    <td class="text-nowrap small {{ $reserved > 0 ? '' : 'text-secondary' }}">{{ $unit->format($reserved) }}</td>
    <td class="text-nowrap">
        <span class="material-qty {{ $available <= 0 ? 'text-danger fw-semibold' : '' }}">{{ $unit->format($available) }}</span>
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
        <a class="btn btn-sm btn-outline-info py-1 px-2"
            href="{{ route('inventory.movements', ['material' => $material->material_id]) }}" title="History">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
        </a>
    </td>
</tr>
