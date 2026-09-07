@props(['project'])

{{--
    "Nobody has settled this project's phases."

    The row-level counterpart of the Phase Setup Required notice on the project
    itself, and drawn the same way ACTIVE TODAY is - one component, so the
    Super Admin, Admin and both technician tables say it identically.

    This replaced an attention tab. A tab is only seen by somebody who thinks
    to click it, and the whole point of this state is that it is easy to miss:
    a project created this morning looks like any other until somebody tries to
    put a task on it.

    Nothing is stored. It disappears the moment the phases are finalized - see
    Project::needsPhaseSetup().
--}}
@if ($project->needsPhaseSetup())
    <span {{ $attributes->merge(['class' => 'project-phase-setup-flag']) }}
        title="This project has not been configured with its project phases, so it cannot take tasks yet.">
        <i class="bi bi-diagram-3" aria-hidden="true"></i>
        PHASE SETUP REQUIRED
    </span>
@endif
