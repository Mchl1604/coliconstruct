{{-- One row of a project's Material History, rendered by
     ProjectMaterialController::table() for serverTable.js. $canVoid and
     $voidUrl come with it. --}}
@php
    $material = $entry->projectMaterial?->material;
    $unit = $material?->unit;
    $quantity = $unit ? $unit->format($entry->quantity) : $entry->quantity;
@endphp
<tr class="{{ $entry->isVoided() ? 'material-entry-voided' : '' }}">
    <td class="text-nowrap">{{ $entry->entry_date?->format(\App\Support\BusinessTime::DATE) }}</td>
    <td>
        <span class="material-name material-entry-strike">{{ $material?->name ?? 'Material' }}</span>
        @if ($material?->brand)
            <span class="material-brand">{{ $material->brand }}</span>
        @endif
    </td>
    <td>
        <span class="material-entry-badge is-{{ $entry->entry_type }}">{{ $entry->typeLabel() }}</span>
        @if ($entry->isVoided())
            <span class="badge text-bg-light border ms-1">Voided</span>
        @endif
    </td>
    <td class="text-end material-qty material-entry-strike">
        {{ $quantity }} {{ $unit?->value }}
    </td>
    <td class="small">{{ $entry->recorderName() }}</td>
    <td class="small text-secondary">{{ $entry->notes }}</td>
    <td class="text-center">
        @if ($canVoid)
            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2"
                data-material-void-url="{{ $voidUrl }}"
                data-material-void-summary="{{ $entry->typeLabel() }}: {{ $quantity }} {{ $unit?->value }} of {{ $material?->displayName() }}"
                title="Void entry">
                <i class="bi bi-x-circle" aria-hidden="true"></i>
            </button>
        @endif
    </td>
</tr>
