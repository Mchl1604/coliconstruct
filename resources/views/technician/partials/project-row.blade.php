{{-- One row of My Projects, rendered by
     TechnicianPortalController::projectsTable() for serverTable.js.
     $canCloseProjects and $flagsInactiveCrew come with it. --}}
@php
    $client = $project->client;
    // Somebody on this crew can no longer sign in.
    // Flagged on the row itself, exactly as the
    // administrative table flags it, so the lead
    // does not have to open every project to find
    // the one that is short-handed.
    $needsRecrew = $flagsInactiveCrew && $project->needsRecrew();
    // Exactly as the administrative table decides
    // it - Project::isActiveToday() - so a lead and
    // an administrator looking at the same project
    // see the same flag.
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
    class="{{ $needsPhaseSetup ? 'project-row-needs-phase-setup' : '' }} {{ $isActiveToday ? 'project-row-active-today' : '' }} {{ $needsRecrew ? 'project-row-needs-recrew' : '' }}">
    <td>
        {{ $project->reference_no }}

        <x-project-phase-setup-flag :project="$project" />

        <x-project-active-today-flag :project="$project" />

        @if ($needsRecrew)
            <span class="project-recrew-flag"
                title="{{ $project->hasLead()
                    ? $project->inactiveCrewNames().' cannot sign in. Move their tasks.'
                    : 'No lead technician assigned.' }}">
                <i class="bi bi-person-exclamation" aria-hidden="true"></i>
                {{ $project->recrewFlagLabel() }}
            </span>
        @endif
    </td>
    <td>
        {{ $client?->client_type === 'Commercial'
            ? ($client->company_name ?? $client->fullname)
            : ($client?->fullname ?? 'N/A') }}
    </td>
    <td>{{ $client?->client_type ?? 'N/A' }}</td>
    <td>
        @forelse ($project->projectTypes as $projectType)
            <span class="project-type-chip">{{ $projectType->type_name }}</span>
        @empty
            <span class="text-muted small">N/A</span>
        @endforelse
    </td>
    <td><x-project-status-badge :project="$project" /></td>
    <td><x-project-phase-progress :project="$project" /></td>
    <td class="text-center">
        @php
            // A lead on a project nobody has set up is offered that and
            // nothing else - see the administrators' row. A plain technician
            // cannot set anything up, so they keep View.
            $offersSetUpOnly = $project->awaitingInitialSetup()
                && app(\App\Services\ProjectPhaseRules::class)->canSetUp(auth()->user(), $project);
        @endphp
        <div class="projects-action-buttons">
            @if ($offersSetUpOnly)
                <a class="btn btn-sm btn-warning py-1 px-2 fw-semibold text-nowrap"
                    href="{{ route('technician.projects.phases.setup', $project->project_id) }}"
                    title="Set up phases and materials">
                    <i class="bi bi-sliders me-1" aria-hidden="true"></i>Set Up Now
                </a>
            @else
            <a class="btn btn-sm btn-primary py-1 px-2"
                href="{{ route('technician.projects.show', $project->project_id) }}"
                title="View project">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </a>

            {{-- Only work that is actually under
                 way. A Pending, Unscheduled or
                 paused project has had nobody on
                 site yet, so there is nothing to
                 close out and no button offered -
                 see Project::isCompletable(). --}}
            @if ($canCloseProjects && $project->isCompletable())
                <button type="button" class="btn btn-sm btn-success py-1 px-2"
                    data-complete-project="{{ $project->project_id }}"
                    data-project-reference="{{ $project->reference_no }}"
                    title="Complete project">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                </button>
            @endif
            @endif
        </div>
    </td>
</tr>
