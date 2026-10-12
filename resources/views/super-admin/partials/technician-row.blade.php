{{-- One row of the Technicians table, rendered by
     TechnicianController::table() for serverTable.js. $awaitingDecision
     says whether they are waiting on a specialty decision. --}}
@php
    $isLead = optional($technician->account)->role === 'lead_technician';
@endphp

<tr data-technician-row="{{ $technician->technician_id }}"
    class="{{ $awaitingDecision ? 'technician-row-pending' : '' }}">
    <td>{{ $technician->displayCode() }}</td>
    {{-- The code on their staff account,
         which is how they are referred to
         everywhere outside this page. --}}
    <td>{{ $technician->account?->user_code ?? '—' }}</td>
    <td>
        {{-- Picture beside the name, the
             same one they set on their own
             profile page. --}}
        <div class="d-flex align-items-center gap-2">
            <x-user-avatar :user="$technician->account" size="sm" />
            <div>
                <div class="fw-semibold">
                    {{ $technician->name }}

                    @if ($awaitingDecision)
                        <span class="badge technician-pending-badge ms-1">
                            <i class="bi bi-patch-question me-1"
                                aria-hidden="true"></i>
                            Specialty request
                        </span>
                    @endif
                </div>
                <div class="small text-muted">
                    {{ $technician->account?->email }}
                </div>
            </div>
        </div>
    </td>
    <td data-technician-specialties="{{ $technician->technician_id }}">
        @forelse ($technician->skills->sortBy('skill_name') as $skill)
            <span class="technician-chip">{{ $skill->skill_name }}</span>
        @empty
            <span class="text-muted small">No specialties assigned.</span>
        @endforelse
    </td>
    {{-- Ranked rather than spelled: "Lead
         Technician" sorts after
         "Technician" alphabetically. --}}
    <td>
        <span class="badge {{ $isLead ? 'bg-primary' : 'bg-secondary' }}">
            {{ $isLead ? 'Lead Technician' : 'Technician' }}
        </span>
    </td>
    <td>
        {{-- Whether the account can still be
             used, in the words and colours the
             profile page already prints it in.
             It belongs on this page because a
             deactivated technician keeps every
             booking they were holding, so the
             row looks entirely normal until
             somebody tries to give them work. --}}
        <span class="badge {{ $technician->account?->statusBadgeClass() ?? 'bg-dark' }}">
            {{ $technician->account?->statusLabel() ?? 'No account' }}
        </span>
    </td>
    <td class="text-center">
        <button type="button" class="btn btn-sm btn-primary py-1 px-2"
            data-view-technician="{{ $technician->technician_id }}"
            title="View technician details">
            <i class="bi bi-eye"></i>
        </button>
    </td>
</tr>
