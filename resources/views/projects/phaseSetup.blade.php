@extends($layout)

@section('title', 'Set Up Project Phases')

@push('styles')
    <link rel="stylesheet" href="/css/projectPhases.css">
@endpush

@section('content')
    @php
        // The rows the editor starts with: what has already been saved, or the
        // suggested structure on a project nobody has typed anything for yet.
        // Old input wins over both, so a refused save comes back with the
        // person's own work rather than with the defaults.
        $initialRows =
            old('phases') ??
            ($phases->isNotEmpty()
                ? $phases
                    ->map(
                        fn($phase) => [
                            'phase_id' => $phase->phase_id,
                            'title' => $phase->title,
                            'description' => $phase->description,
                            'tasks' => (int) $phase->tasks_count,
                        ],
                    )
                    ->values()
                    ->all()
                : collect($suggested)
                    ->map(fn($phase) => ['phase_id' => null, 'title' => $phase['title'], 'description' => $phase['description'], 'tasks' => 0])
                    ->all());

        // Only ever non-empty on a project a Super Admin has unlocked: a
        // project that has never been finalized cannot have taken a task.
        $reassignTargets = $phases
            ->map(fn($phase) => ['phase_id' => $phase->phase_id, 'label' => $phase->label()])
            ->values();
    @endphp

    <div class="container-fluid py-4 phase-setup-page">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h2 class="fw-bold text-brand-blue mb-1">Set Up Project Phases</h2>
                <p class="text-secondary small mb-0">
                    {{ $project->reference_no }} &mdash; {{ $project->name }}
                </p>
            </div>

            <a href="{{ $projectUrl }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                Back to Project
            </a>
        </div>

        {{-- Says plainly which of the two states this project is in, and why
             the monitoring interface is not on the screen. --}}
        <div class="alert alert-warning d-flex gap-2 align-items-start" role="status">
            <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>
            <div>
                <h5 class="alert-heading mb-1">Phase Setup Required</h5>
                <p class="mb-0 small">
                    This project has not been configured with its project phases, so it is not being
                    monitored yet and cannot take tasks. Define the complete phase structure below,
                    then finalize it.
                </p>
            </div>
        </div>

        @if ($project->phaseStructureWasOverridden() && $project->phase_structure_override_reason)
            {{-- Only ever seen by a Super Admin who has just unlocked a
                 finalized structure: it puts their own stated reason back in
                 front of them while they make the change. --}}
            <div class="alert alert-danger d-flex gap-2 align-items-start" role="status">
                <i class="bi bi-unlock-fill fs-5" aria-hidden="true"></i>
                <div>
                    <h5 class="alert-heading mb-1">This structure was unlocked by a Super Admin</h5>
                    <p class="mb-1 small">
                        It was finalized with {{ $project->phase_count }}
                        {{ \Illuminate\Support\Str::plural('phase', (int) $project->phase_count) }}.
                        Changing the count changes what every progress figure on this project is read
                        against.
                    </p>
                    <p class="mb-0 small fst-italic">
                        &ldquo;{{ $project->phase_structure_override_reason }}&rdquo;
                    </p>
                </div>
            </div>
        @endif

        @if ($phasesHoldingTasks->isNotEmpty())
            <div class="alert alert-info d-flex gap-2 align-items-start" role="status">
                <i class="bi bi-list-check fs-5" aria-hidden="true"></i>
                <div>
                    <h6 class="alert-heading mb-1">Some phases already have work on them</h6>
                    <p class="mb-1 small">
                        Removing one of these will ask you where its tasks should go. Tasks are never
                        deleted with a phase.
                    </p>
                    <ul class="small mb-0">
                        @foreach ($phasesHoldingTasks as $entry)
                            <li>
                                {{ $entry['phase']->label() }} &mdash;
                                {{ $entry['tasks'] }}
                                {{ \Illuminate\Support\Str::plural('task', $entry['tasks']) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ $finalizeUrl }}" data-phase-setup-form
            data-save-url="{{ $saveUrl }}" data-finalize-url="{{ $finalizeUrl }}"
            data-min-phases="{{ $minPhases }}" data-max-phases="{{ $maxPhases }}"
            data-reassign-targets='@json($reassignTargets)'>
            @csrf

            <div class="card shadow-sm border-0 rounded-2 mb-3">
                <div class="card-body p-3 p-md-4">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Phase Structure</h5>
                            <p class="text-secondary small mb-0">
                                Each phase needs a title and a short description. Drag the handle, or use
                                the arrows, to reorder them &mdash; the numbers follow.
                            </p>
                        </div>

                        <span class="badge bg-secondary" data-phase-count-badge></span>
                    </div>

                    @error('phases')
                        <div class="alert alert-danger small py-2">{{ $message }}</div>
                    @enderror

                    <div data-phase-rows></div>

                    <button type="button" class="btn btn-outline-primary mt-3" data-phase-add>
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                        Add Phase
                    </button>

                    <p class="text-danger small mt-2 mb-0 d-none" data-phase-error></p>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-secondary" data-phase-save>
                    <i class="bi bi-save me-1" aria-hidden="true"></i>
                    Save Without Locking
                </button>

                <button type="button" class="btn btn-success" data-bs-toggle="modal"
                    data-bs-target="#finalizePhasesModal">
                    <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
                    Finalize Phases
                </button>
            </div>

            {{-- The one irreversible action on this screen, for everybody but a
                 Super Admin. The wording is the warning the specification asks
                 for, verbatim, because it is what a person needs to have read
                 before they press the button. --}}
            <div class="modal fade" id="finalizePhasesModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title">
                                <i class="bi bi-lock-fill me-2" aria-hidden="true"></i>
                                Finalize Phases
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            <p class="mb-3">
                                Once phases are finalized, the phase structure will be locked. Admins and
                                Lead Technicians will no longer be able to add, remove, or reorder phases.
                                Make sure all project phases have been entered correctly before continuing.
                            </p>

                            <div class="alert alert-light border small mb-0" data-phase-summary></div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Go Back
                            </button>
                            <button type="button" class="btn btn-success" data-phase-finalize>
                                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                                Finalize Phases
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- Where a removed phase's tasks go. Opened by the editor when a
             removal would strand work, and never otherwise. --}}
        <div class="modal fade" id="reassignPhaseTasksModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">
                            <i class="bi bi-arrow-left-right me-2" aria-hidden="true"></i>
                            Move This Phase's Tasks
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <p class="small mb-3" data-reassign-message></p>

                        <label class="form-label fw-semibold" for="reassignPhaseTarget">Move the tasks to</label>
                        <select class="form-select" id="reassignPhaseTarget" data-reassign-target>
                        </select>
                        <div class="form-text">
                            The tasks are moved, never deleted. Only phases you are keeping are listed.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning" data-reassign-confirm>
                            Move Tasks and Remove Phase
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- The editor's starting rows, read once by the script. --}}
        <span class="d-none" data-phase-initial='@json($initialRows)'></span>
    </div>

    @push('scripts')
        <script src="/js/phaseSetup.js"></script>
    @endpush
@endsection
