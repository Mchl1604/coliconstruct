@extends($layout)

@section('title', 'Set Up Project Phases')

@push('styles')
    <link rel="stylesheet" href="/css/projectPhases.css">
@endpush

@section('content')
    @php
        // The rows the editor starts with, in order of authority: the person's
        // own refused submission, then what they saved earlier, then the
        // structure their project's types imply. Old input wins over both of
        // the others, so a refused save comes back with their own work rather
        // than with the defaults on top of it.
        //
        // `tasks` is the draft task list under each phase, and `task_count` is
        // how many REAL tasks are already filed there - two different things
        // that must not be conflated. task_count is only ever non-zero after a
        // Super Admin override, and it is what decides whether removing a phase
        // has to ask where its work goes.
        $initialRows =
            old('phases') ??
            ($phases->isNotEmpty()
                ? $phases
                    ->map(
                        fn($phase) => [
                            'phase_id' => $phase->phase_id,
                            'stage_id' => $phase->stage_id,
                            'title' => $phase->title,
                            'description' => $phase->description,
                            'sources' => [],
                            'task_count' => (int) $phase->tasks_count,
                            'tasks' => $phase->draftTasks
                                ->map(
                                    fn($task) => [
                                        'title' => $task->title,
                                        'description' => $task->description,
                                        'technician_id' => $task->technician_id,
                                        'start_date' => $task->start_date,
                                        'due_date' => $task->due_date,
                                        'sources' => [],
                                    ],
                                )
                                ->values()
                                ->all(),
                        ],
                    )
                    ->values()
                    ->all()
                : $suggested);

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

        {{-- Says plainly which of the two states this project is in. One line:
             the page title already says what this screen is for, so this only
             has to say why nothing is being monitored yet. --}}
        <div class="phase-setup-notice" role="status">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>
                <strong>Phase Setup Required.</strong>
                This project is not monitored and cannot take tasks until its phases are finalized.
            </span>
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
            data-max-tasks="{{ $maxTasksPerPhase }}"
            data-schedule-ranges='@json($scheduleRanges)'
            data-technicians='@json($technicians)'
            data-reassign-targets='@json($reassignTargets)'>
            @csrf

            <div class="phase-structure-card">
                <div class="phase-structure-head">
                    <h5 class="fw-bold mb-1">Phase Structure</h5>
                    <p class="text-secondary small mb-0">
                        Give each phase a title and one-sentence description, then list the work it
                        needs &mdash; a technician and dates are optional.
                    </p>
                </div>

                @error('phases')
                    <div class="alert alert-danger small py-2">{{ $message }}</div>
                @enderror

                {{-- Everything the server refused, listed where the person
                     can see it.

                     The editor checks what it can before submitting, but
                     two of the task rules are not knowable in the browser:
                     whether a technician is still on this project's team,
                     and whether the dates still fall inside a booked range.
                     Both can change in another tab while this screen is
                     open. Without this block such a refusal would bounce
                     the page back looking untouched, which reads as the
                     button being broken. --}}
                @php
                    $taskErrors = collect($errors->getMessages())
                        ->filter(fn($messages, $key) => str_starts_with($key, 'phases.'))
                        ->flatten()
                        ->unique()
                        ->values();
                @endphp

                @if ($taskErrors->isNotEmpty())
                    <div class="alert alert-danger small py-2">
                        <p class="fw-semibold mb-1">This structure was not saved:</p>
                        <ul class="mb-0 ps-3">
                            @foreach ($taskErrors as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div data-phase-rows></div>

                <button type="button" class="phase-add-phase" data-phase-add>
                    <i class="bi bi-plus-circle" aria-hidden="true"></i>
                    Add Phase
                </button>

                <p class="phase-error d-none" role="alert" data-phase-error></p>
            </div>

            {{-- Stays on screen however long the structure gets, so the buttons that
                 actually write anything are never a scroll away. --}}
            <div class="phase-setup-actions">
                <span class="phase-setup-summary" data-phase-count-badge></span>

                <div class="phase-setup-buttons">
                    {{-- Only where there is a saved draft to throw away, and only
                         while no real work has been filed against it. The case it
                         is for: a project type was added to this project after its
                         setup was started, so the merged suggestion is now out of
                         date and there is otherwise no way back to it. --}}
                    @if ($phases->isNotEmpty() && $phasesHoldingTasks->isEmpty())
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal"
                            data-bs-target="#reloadPhasesModal">
                            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Start Again From </span>Defaults
                        </button>
                    @endif

                    <button type="button" class="btn btn-outline-secondary" data-phase-save>
                        <i class="bi bi-save me-1" aria-hidden="true"></i>
                        Save<span class="d-none d-sm-inline"> Without Locking</span>
                    </button>

                    <button type="button" class="btn btn-success" data-bs-toggle="modal"
                        data-bs-target="#finalizePhasesModal">
                        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
                        Finalize<span class="d-none d-sm-inline"> Phases</span>
                    </button>
                </div>
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

        {{-- Discards the saved draft and recomputes the suggestion from this
             project's types. Its own form, because it cannot be nested inside
             the structure form it is about to throw away. --}}
        <div class="modal fade" id="reloadPhasesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-arrow-counterclockwise me-2" aria-hidden="true"></i>
                            Start Again From Defaults
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-0">
                            This deletes the phases and tasks saved so far and rebuilds them from the
                            default phases for
                            {{ $project->projectTypes->pluck('type_name')->join(', ', ' and ') }}.
                            Nothing else about the project changes.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Go Back</button>

                        <form method="POST" action="{{ $reloadUrl }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-danger">
                                Discard and Start Again
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- The editor's starting rows, read once by the script. --}}
        <span class="d-none" data-phase-initial='@json($initialRows)'></span>
    </div>

    @push('scripts')
        {{-- The same booked-days-only pickers the task board uses, so a task
             dated here is held to exactly the rule it will be held to there. --}}
        <script src="/js/super-admin/taskDatePickers.js"></script>
        <script src="/js/phaseSetup.js"></script>
    @endpush
@endsection
