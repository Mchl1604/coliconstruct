{{--
    Call off a team change that has not taken effect - a scheduled removal or a
    scheduled start. Confirmed in the page's own dialog before it is sent (see
    projectDetails.js), because cancelling one half of a lead handover cancels
    the other half too, and the dialog says so.

    @param ProjectTechnician $membership
    @param string $label
    @param string|null $part  'removal' to cancel only the removal at the end
                              of a span still to come
--}}
<form method="POST" class="flex-shrink-0"
    action="{{ route('super-admin.projects.team.scheduled.cancel', ['id' => $membership->project_id, 'membership' => $membership->project_technician_id]) }}"
    data-team-cancel-form data-technician-name="{{ $membership->technician?->name }}"
    data-label="{{ $label }}"
    data-is-lead="{{ $membership->technician?->isLead() ? '1' : '0' }}">
    @csrf
    @method('DELETE')

    @if (! empty($part))
        <input type="hidden" name="part" value="{{ $part }}">
    @endif

    <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>
        {{ $label }}
    </button>
</form>
