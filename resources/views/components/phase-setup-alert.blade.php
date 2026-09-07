@props([
    // The projects this viewer can see that have no agreed phase structure.
    'projects',
    'setupRoute' => 'technician.projects.phases.setup',
])

{{--
    Urgent Actions for a lead technician: the projects they are on that nobody
    has set the phases up for.

    A lead has no dashboard of their own and is not getting one for this, so
    this sits at the top of My Projects - the same argument
    x-task-attention-alerts makes for living on the Tasks page.

    Nothing is drawn when there is nothing wrong, and each project leaves the
    panel the moment its phases are finalized: this is the current state read on
    every load, not a stored alert somebody has to dismiss.
--}}
@if ($projects->isNotEmpty())
    <div class="card shadow-sm border-0 rounded-2 mb-3 phase-setup-required">
        <div class="card-body p-3">

            <div class="d-flex align-items-start gap-2 flex-wrap">
                <span class="phase-setup-icon" aria-hidden="true">
                    <i class="bi bi-diagram-3"></i>
                </span>

                <div class="flex-grow-1">
                    <h6 class="fw-bold mb-1">Project Phase Setup Required</h6>
                    <p class="text-secondary small mb-0">
                        @if ($projects->count() === 1)
                            This project has not been configured with its project phases. It cannot
                            take tasks until the structure is finalized.
                        @else
                            {{ $projects->count() }} of your projects have not been configured with
                            their project phases. They cannot take tasks until their structures are
                            finalized.
                        @endif
                    </p>
                </div>
            </div>

            <ul class="list-unstyled mt-2 mb-0 d-flex flex-column gap-2">
                @foreach ($projects as $project)
                    <li class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="small">
                            <strong>{{ $project->reference_no }}</strong>
                            &mdash; {{ $project->name }}
                        </span>

                        {{-- Straight to the setup-only screen, not to the
                             project page: the project has no finalized
                             structure, so there is nothing to monitor there
                             yet. --}}
                        <a href="{{ route($setupRoute, $project->project_id) }}"
                            class="btn btn-sm btn-warning">
                            <i class="bi bi-sliders me-1" aria-hidden="true"></i>
                            Set Up Phases
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
