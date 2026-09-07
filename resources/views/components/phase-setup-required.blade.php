@props([
    'project',
    // Whether this viewer is one of the three roles that may do something
    // about it. A plain technician sees the state without being handed a
    // button they cannot use.
    'canSetUp' => false,
    'setupRoute' => 'super-admin.projects.phases.setup',
])

{{--
    State 1: the project exists but its phases have not been settled.

    Drawn in place of the monitoring panel, never alongside it - the two states
    are mutually exclusive and showing both would be the confusion the explicit
    phase_setup_status column exists to prevent.
--}}
<div class="card shadow-sm border-0 rounded-2 mb-4 phase-setup-required" id="phases">
    <div class="card-body p-3 p-md-4 d-flex flex-wrap gap-3 align-items-start">

        <span class="phase-setup-icon" aria-hidden="true">
            <i class="bi bi-diagram-3"></i>
        </span>

        <div class="flex-grow-1">
            <h4 class="fw-bold mb-1">Phase Setup Required</h4>
            <p class="text-secondary mb-2">
                This project has not been configured with its project phases.
            </p>
            <p class="text-secondary small mb-0">
                @if ($canSetUp)
                    Define the complete phase structure before work is booked against it. Until the
                    phases are finalized this project is not monitored by phase and cannot take new
                    tasks.
                @else
                    An Admin or the project's Lead Technician has to set the phases up before tasks
                    can be created on this project.
                @endif
            </p>
        </div>

        @if ($canSetUp)
            <a href="{{ route($setupRoute, $project->project_id) }}" class="btn btn-primary">
                <i class="bi bi-sliders me-1" aria-hidden="true"></i>
                Set Up Project Phases
            </a>
        @endif
    </div>
</div>
