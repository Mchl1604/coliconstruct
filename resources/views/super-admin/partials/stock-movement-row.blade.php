{{-- One row of Inventory -> Movements, rendered by StockController::movements()
     for serverTable.js. A voided movement stays, struck through. --}}
@php
    $material = $movement->material;
    $unit = $material?->unit;
    $quantity = (float) $movement->quantity;
@endphp
<tr class="{{ $movement->isVoided() ? 'material-entry-voided' : '' }}">
    <td class="text-nowrap">{{ $movement->movement_date?->format(\App\Support\BusinessTime::DATE) }}</td>
    <td>
        <span class="material-name material-entry-strike">{{ $material?->name ?? 'Material' }}</span>
        @if ($material?->brandName())
            <span class="material-brand">{{ $material->brandName() }}</span>
        @endif
    </td>
    <td>
        <span class="stock-move-badge is-{{ $movement->movement_type }}">{{ $movement->typeLabel() }}</span>
        @if ($movement->isVoided())
            <div class="small text-secondary">Voided</div>
        @endif
    </td>
    <td class="text-nowrap">
        <span class="material-qty fw-semibold material-entry-strike stock-qty {{ $quantity > 0 ? 'is-in' : 'is-out' }}">
            {{ $quantity > 0 ? '+' : '−' }}{{ $unit?->format(abs($quantity)) ?? abs($quantity) }}
        </span>
        <span class="material-unit-chip">{{ $unit?->value }}</span>
    </td>
    <td class="small">
        @if ($movement->project)
            <a href="{{ \App\Support\RoleAccess::allows(auth()->user(), \App\Support\RoleAccess::SCHEDULING)
                ? route('super-admin.projects.show', $movement->project->project_id).'#project-materials'
                : route('inventory.projects.show', $movement->project->project_id) }}">
                <i class="bi bi-folder2-open me-1" aria-hidden="true"></i>{{ $movement->project->reference_no }}
            </a>
        @endif
        @if ($movement->notes)
            <div class="text-secondary">{{ $movement->notes }}</div>
        @endif
        @if (! $movement->project && ! $movement->notes)
            <span class="text-secondary">&ndash;</span>
        @endif
    </td>
    <td class="small">{{ $movement->recorderName() }}</td>
    <td class="text-center">
        @unless ($movement->isVoided() || $movement->is_reservation)
            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2"
                data-stock-void="{{ $movement->stock_movement_id }}"
                data-stock-void-label="{{ $movement->typeLabel() }} of {{ $unit?->format(abs($quantity)) }} {{ $unit?->value }} {{ $material?->displayName() }}"
                title="Void">
                <i class="bi bi-x-circle" aria-hidden="true"></i>
            </button>
        @endunless
    </td>
</tr>
