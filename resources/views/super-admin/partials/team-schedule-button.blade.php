{{--
    Opens a technician's schedule dialog on the Assigned Team card - see
    team-schedule-modal. The count says there is something booked without the
    dates crowding the row.

    @param Technician $technician
    @param int $count  scheduled changes still to come
--}}
<button type="button" class="btn btn-sm btn-outline-secondary team-schedule-button flex-shrink-0"
    data-bs-toggle="modal" data-bs-target="#teamSchedule{{ $technician->technician_id }}"
    title="{{ $technician->name }}'s schedule"
    aria-label="View {{ $technician->name }}'s schedule{{ $count ? ', '.$count.' scheduled '.\Illuminate\Support\Str::plural('change', $count) : '' }}">
    <i class="bi bi-calendar-week" aria-hidden="true"></i>

    @if ($count)
        <span class="team-schedule-count" aria-hidden="true">{{ $count }}</span>
    @endif
</button>
