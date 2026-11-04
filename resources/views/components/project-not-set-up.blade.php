@props([
    'project',
    'canSetUp' => false,
    'setupRoute' => 'super-admin.projects.phases.setup',
])

{{--
    The project can be read but not changed until it has been set up. Who may
    set it up is ProjectPhaseRules; the endpoints refuse changes on their own -
    see EnsureProjectIsSetUp.
--}}
@if ($project->awaitingInitialSetup() && ! $project->isReadOnly() && ! $project->isArchived())
    <div class="alert alert-warning border-0 shadow-sm d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
        <div>
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            <strong>This project is not set up yet.</strong>
            @if ($canSetUp)
                Set it up to make changes.
            @else
                A Super Admin or Lead Technician must set it up.
            @endif
        </div>

        @if ($canSetUp)
            <a href="{{ route($setupRoute, $project->project_id) }}" class="btn btn-warning btn-sm fw-semibold">
                <i class="bi bi-sliders me-1" aria-hidden="true"></i>
                Set Up Now
            </a>
        @endif
    </div>
@endif
