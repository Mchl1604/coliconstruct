{{-- One row of the table under the Schedules calendar, rendered by
     ScheduleController::table() for serverTable.js. The dialogs its buttons
     open are on the page already - the calendar opens the same ones. --}}
<tr>
    <td>{{ $project->displayCode() }}</td>
    <td>{{ $project->reference_no }}</td>
    <td>{{ $project->name }}</td>
    <td>
        {{ $project->projectTechnicians->pluck('technician.name')->filter()->join(', ') ?: 'Unassigned' }}
    </td>
    <td>
        {{-- describe() is the one place that decides how a
             schedule reads, so a single day says one date and
             a partial day carries its hours. --}}
        @foreach ($project->schedules as $schedule)
            <div class="small">{{ $schedule->describe() }}</div>
        @endforeach
    </td>
    <td>
        <x-project-status-badge :project="$project" />
    </td>
    <td class="text-center">
        <div class="d-flex flex-wrap gap-1 justify-content-center">
        {{-- A closed record and a paused project are
             both fixed until something else changes,
             so both get the view-only panel. Asked of
             the model so this button and the endpoint
             behind it cannot disagree. --}}
        {{-- Icon only, like the Projects page's row of
             actions: four of them side by side in a
             table cell is a column that pushes the
             dates out of the row. Each one carries its
             name for anybody hovering it and for
             anybody reading the page rather than
             looking at it, which an icon on its own
             does not say. --}}
        @if (! $project->scheduleIsEditable())
            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2"
                data-bs-toggle="modal"
                data-bs-target="#scheduleViewModal{{ $project->project_id }}"
                title="View Schedule" aria-label="View Schedule">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
        @else
            <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                data-bs-toggle="modal" data-bs-target="#scheduleEditModal{{ $project->project_id }}"
                title="Edit Schedule" aria-label="Edit Schedule">
                <i class="bi bi-calendar2-week" aria-hidden="true"></i>
            </button>
        @endif

        {{-- Pausing and resuming, from the page the
             dates are read on - the reason a hold is
             usually decided here in the first place.
             Both post to the endpoints the Projects
             page posts to, under the same conditions
             that page draws its buttons by, so all
             three entry points are one action. See
             ProjectController::putOnHold() and
             ::resume(). --}}
        @if (! $project->isReadOnly())
            @if ($project->on_hold !== true && $project->status !== 'unscheduled')
                <button type="button" class="btn btn-sm btn-warning py-1 px-2"
                    data-bs-toggle="modal"
                    data-bs-target="#onHoldModal{{ $project->project_id }}"
                    title="Put on Hold" aria-label="Put on Hold">
                    <i class="bi bi-pause" aria-hidden="true"></i>
                </button>
            @endif

            @if ($project->on_hold === true)
                <button type="button" class="btn btn-sm btn-success py-1 px-2"
                    data-bs-toggle="modal"
                    data-bs-target="#resumeModal{{ $project->project_id }}"
                    title="Resume Project" aria-label="Resume Project">
                    <i class="bi bi-play" aria-hidden="true"></i>
                </button>
            @endif
        @endif
        </div>
    </td>
</tr>
