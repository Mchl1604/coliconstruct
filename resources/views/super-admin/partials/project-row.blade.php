{{-- One row of the Projects table, rendered by ProjectController::table()
     for serverTable.js. $confirmability and $canArchive come with it. --}}
@php
    // Somebody on this team can no longer sign in.
    // Surfaced on the row itself rather than only
    // inside Project Details: an administrator
    // should not have to open every project to find
    // the ones that need re-crewing.
    $needsRecrew = $project->needsRecrew();
    // A crew is on this job today. Derived from the
    // booked ranges rather than the status column -
    // see Project::isActiveToday(), the one rule all
    // four portals ask.
    $isActiveToday = $project->isActiveToday();
    // Nobody has settled this project's phases,
    // so it takes no tasks. Flagged on the row
    // rather than behind a tab, the way ACTIVE
    // TODAY is - see Project::needsPhaseSetup().
    $needsPhaseSetup = $project->needsPhaseSetup();
@endphp
<tr data-tab="{{ $project->tabKey() }}"
    data-status="{{ $project->status }}"
    data-overdue="{{ $project->isOverdue() ? '1' : '0' }}"
    data-on-hold="{{ $project->on_hold ? '1' : '0' }}"
    data-active-today="{{ $isActiveToday ? '1' : '0' }}"
    {{-- The attention tabs this row also answers -
         overlapping, so a list rather than a second
         status. See Project::attentionTabKeys(). --}}
    data-tab-extra="{{ implode(' ', $project->attentionTabKeys()) }}"
    class="{{ $needsPhaseSetup ? 'project-row-needs-phase-setup' : '' }} {{ $isActiveToday ? 'project-row-active-today' : '' }} {{ $needsRecrew ? 'project-row-needs-recrew' : '' }}">
    <td>
        {{ $project->displayCode() }}

        <x-project-new-flag :project="$project" />

        <x-project-phase-setup-flag :project="$project" />

        <x-project-active-today-flag :project="$project" />

        @if ($needsRecrew)
            <span class="project-recrew-flag"
                title="{{ $project->hasLead()
                    ? $project->inactiveCrewNames().' cannot sign in. Reassign the work.'
                    : 'No lead technician assigned.' }}">
                <i class="bi bi-person-exclamation" aria-hidden="true"></i>
                {{ $project->recrewFlagLabel() }}
            </span>
        @endif
    </td>
    <td>{{ $project->reference_no }}</td>
    <td>
        @php
            $client = $project->clients->first();
        @endphp
        {{ $client?->client_type === 'Commercial' ? ($client->company_name ?? $client->fullname) : $client?->fullname ?? 'N/A' }}
    </td>
    <td>{{ $client?->client_type }}</td>
    <td>
        @forelse ($project->projectTypes as $projectType)
            <span class="project-type-chip">{{ $projectType->type_name }}</span>
        @empty
            <span class="text-muted small">N/A</span>
        @endforelse
    </td>
    <td class="text-success fw-semibold">₱ {{ number_format($project->quotation, 2) }}</td>
    <td>
        <x-project-status-badge :project="$project" />

        {{-- A project waiting on a reply that cannot arrive
             reads exactly like one whose client is still
             thinking about it, and for the length of the
             confirmation window it sat here indistinguishable
             from it. This is the difference, said in one word
             beside the status rather than by changing it: the
             project really is Awaiting Client Confirmation,
             and nothing about the underlying state is being
             bent to carry a display concern.

             It disappears on its own the moment the client
             registers - see CompletionConfirmability. --}}
        @if ($project->isAwaitingClientConfirmation()
            && ($confirmability[$project->project_id] ?? null) === \App\Services\CompletionConfirmability::UNREACHABLE)
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle d-inline-flex align-items-center gap-1 mt-1"
                title="It will auto-complete.">
                <i class="bi bi-person-slash" aria-hidden="true"></i>
                No client to confirm
            </span>
        @endif
    </td>
    {{-- How far through its phases the project is,
         read by everybody who can see the row. --}}
    <td>
        <x-project-phase-progress :project="$project" />
    </td>
    <td class="text-center">
        @php
            // Asked of the model rather than restated here, which is
            // how this list came to be missing a status the rest of
            // the system already treats as locked.
            $isReadOnly = $project->isReadOnly();
        @endphp
        <div class="projects-action-buttons">
            <button class="btn btn-sm btn-primary py-1 px-2"
                onclick="window.location='{{ route('super-admin.projects.show', $project->project_id) }}'">
                <i class="bi bi-eye"></i>
            </button>

            @if (! $isReadOnly)
                @if ($project->on_hold !== true && $project->status !== 'unscheduled')
                    <button class="btn btn-sm btn-warning py-1 px-2" data-bs-toggle="modal"
                        data-bs-target="#onHoldModal{{ $project->project_id }}">
                        <i class="bi bi-pause"></i>
                    </button>
                @endif
                @if ($project->on_hold === true)
                    <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                        data-bs-target="#resumeModal{{ $project->project_id }}">
                        <i class="bi bi-play"></i>
                    </button>
                @endif

                {{-- Only work that is actually under way. An
                     Unscheduled or paused project has had nobody on site
                     yet, so there is nothing to close out - the same rule
                     the technician portal draws the button by. A Super
                     Admin additionally gets Pending work, which is theirs
                     to close out early. See Project::isCompletableBy(),
                     which ProjectController::complete() asks again. --}}
                @if ($project->isCompletableBy(auth()->user()))
                    <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                        data-bs-target="#completeProjectModal{{ $project->project_id }}"
                        title="Complete Project">
                        <i class="bi bi-check-lg"></i>
                    </button>
                @endif

            @endif

            {{-- Outside the read-only branch on purpose.
                 A Completed or Cancelled project is exactly
                 what an archive is for: there is nothing
                 left to do about it, and leaving it in the
                 active listing forever is why that list
                 grew without end. The model decides which
                 statuses qualify, and the endpoint asks it
                 the same question - see
                 Project::isArchivable(). --}}
            @if ($canArchive && $project->isArchivable())
                <button class="btn btn-sm btn-dark py-1 px-2" data-bs-toggle="modal"
                    data-bs-target="#archiveProjectModal{{ $project->project_id }}"
                    title="Archive Project">
                    <i class="bi bi-archive"></i>
                </button>
            @endif
        </div>
    </td>
</tr>
