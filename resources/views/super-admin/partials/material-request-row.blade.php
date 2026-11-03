{{-- One row of the Material Requests page, rendered by
     MaterialRequestController::table() for serverTable.js. --}}
@php
    $material = $materialRequest->material;
    $amount = $material?->unit->format($materialRequest->quantity).' '.$material?->unit->value;
    $label = $amount.' of '.$material?->displayName().' for '.$materialRequest->project?->reference_no;
@endphp
<tr>
    <td class="text-nowrap">{{ \App\Support\BusinessTime::at($materialRequest->created_at)?->format(\App\Support\BusinessTime::DATE) }}</td>
    <td>
        <a href="{{ route('inventory.projects.show', $materialRequest->project_id) }}">{{ $materialRequest->project?->reference_no }}</a>
        <div class="material-meta">{{ $materialRequest->project?->name }}</div>
    </td>
    <td><span class="material-name">{{ $material?->displayName() }}</span></td>
    <td class="text-nowrap material-qty">{{ $amount }}</td>
    <td class="small">{{ $materialRequest->requester?->fullName() }}</td>
    <td>
        <span class="material-request-status is-{{ $materialRequest->status }}">{{ $materialRequest->statusLabel() }}</span>
        @if ($materialRequest->rejection_reason)
            <div class="material-meta">{{ $materialRequest->rejection_reason }}</div>
        @endif
    </td>
    <td class="text-center text-nowrap">
        @if ($materialRequest->isPending())
            <button type="button" class="btn btn-sm btn-success py-1 px-2" title="Approve"
                data-request-approve="{{ $materialRequest->material_request_id }}" data-request-label="{{ $label }}">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2" title="Reject"
                data-request-reject="{{ $materialRequest->material_request_id }}" data-request-label="{{ $label }}">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        @else
            <span class="text-secondary">&ndash;</span>
        @endif
    </td>
</tr>
