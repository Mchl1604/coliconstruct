@props([
    'project',
    // The ProjectPhaseProgress::summary() payload. Passed in rather than
    // computed here so a page that already has it does not read it twice.
    'summary',
    // Whether the viewer may press Complete Phase at all. Whether the phase in
    // front of them can actually close is a separate question, already
    // answered per phase inside the summary.
    'canComplete' => false,
    // Super Admin only: the two things nobody else may do.
    'canOverrideStructure' => false,
    'canOverrideCompletion' => false,
    // The portal's own routes, so one component serves both.
    'completeRoute' => 'super-admin.projects.phases.complete',
    'overrideRoute' => 'super-admin.projects.phases.override',
])

{{--
    Project Phases, once the structure is finalized.

    The monitoring half of the feature: the count, the bar, and one card per
    phase. Shared by the Super Admin project details page and the lead
    technician's copy of it, so a project reads the same in both.

    Every figure here comes from ProjectPhaseProgress and none of it is
    recomputed in the markup. That is what makes "2/4 Phases" and the cards
    below it the same statement rather than two statements that usually agree.
--}}
<div class="card shadow-sm border-0 rounded-2 mb-4 project-phases" id="phases">
    <div class="card-body p-3 p-md-4">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h4 class="fw-bold mb-1">
                    <i class="bi bi-diagram-3 me-2 text-brand-blue" aria-hidden="true"></i>
                    Project Phases
                </h4>
                <p class="text-secondary small mb-0">
                    The stages this project is monitored through. The structure was locked when it
                    was finalized.
                </p>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary fs-6" data-phase-progress-label>{{ $summary['label'] }}</span>

                @if ($canOverrideStructure)
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#overridePhaseStructureModal">
                        <i class="bi bi-unlock me-1" aria-hidden="true"></i>
                        Override Phase Structure
                    </button>
                @endif
            </div>
        </div>

        <div class="progress project-phases-bar mb-1" role="progressbar"
            aria-label="Phases completed" aria-valuenow="{{ $summary['completed'] }}"
            aria-valuemin="0" aria-valuemax="{{ $summary['total'] }}">
            <div class="progress-bar bg-success" style="width: {{ $summary['percent'] }}%"></div>
        </div>

        <p class="text-secondary small mb-3">
            {{ $summary['completed'] }} of {{ $summary['total'] }}
            {{ \Illuminate\Support\Str::plural('phase', $summary['total']) }} completed.
        </p>

        @if ($project->phaseStructureWasOverridden())
            {{-- Left on the page permanently once it has happened. Somebody
                 reading this project's progress is entitled to know the
                 denominator was changed after work had started. --}}
            <div class="alert alert-warning small d-flex gap-2 align-items-start py-2">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <div>
                    <strong>This phase structure was changed after it was finalized.</strong>
                    Overridden by
                    {{ $project->phaseStructureOverriddenByUser?->fullName() ?? 'a Super Admin' }}
                    on
                    {{ \App\Support\BusinessTime::format($project->phase_structure_overridden_at) }}.
                    {{-- Older overrides carry the reason that used to be asked
                         for. Still printed where there is one; nothing is
                         collected now. --}}
                    @if ($project->phase_structure_override_reason)
                        <span class="fst-italic d-block mt-1">
                            &ldquo;{{ $project->phase_structure_override_reason }}&rdquo;
                        </span>
                    @endif
                </div>
            </div>
        @endif

        <div class="project-phase-list">
            @foreach ($summary['phases'] as $row)
                @php
                    $phase = $row['phase'];
                @endphp

                <div class="project-phase-card {{ $row['isCurrent'] ? 'is-current' : '' }} {{ $row['status'] === \App\Models\ProjectPhase::STATUS_COMPLETED ? 'is-complete' : '' }}"
                    data-phase-card="{{ $phase->phase_id }}">

                    <div class="project-phase-head">
                        <div>
                            <span class="project-phase-number">{{ $phase->numberLabel() }}</span>
                            <span class="project-phase-title">{{ $phase->title }}</span>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            @if ($row['isCurrent'])
                                <span class="badge project-phase-current">Current Phase</span>
                            @endif
                            <span class="badge {{ $row['badge'] }}">{{ $row['label'] }}</span>
                        </div>
                    </div>

                    <p class="project-phase-description">{{ $phase->description }}</p>

                    <div class="project-phase-tasks">
                        <div class="progress project-phase-task-bar" role="progressbar"
                            aria-label="Tasks completed in {{ $phase->numberLabel() }}"
                            aria-valuenow="{{ $row['completedTasks'] }}" aria-valuemin="0"
                            aria-valuemax="{{ $row['totalTasks'] }}">
                            <div class="progress-bar" style="width: {{ $row['taskPercent'] }}%"></div>
                        </div>

                        <span class="project-phase-task-count">
                            {{ $row['completedTasks'] }}/{{ $row['totalTasks'] }}
                            {{ \Illuminate\Support\Str::plural('Task', $row['totalTasks']) }}
                        </span>
                    </div>

                    @if ($phase->isCompleted())
                        <p class="project-phase-meta">
                            <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>
                            {{-- Nobody pressed Complete Phase on this one: it
                                 was still open when the project was closed
                                 out, and completion closed it. Said plainly
                                 rather than crediting whoever finished the
                                 project with a call they never made. --}}
                            @if ($phase->wasClosedWithProject())
                                Closed when the project was completed,
                                {{ \App\Support\BusinessTime::format($phase->completed_at) }}
                            @else
                                Completed
                                {{ \App\Support\BusinessTime::format($phase->completed_at) }}
                                @if ($phase->completedByUser)
                                    by {{ $phase->completedByUser->fullName() }}
                                @endif
                            @endif
                        </p>

                        @if ($phase->completionWasOverridden())
                            <p class="project-phase-meta text-danger mb-0">
                                <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
                                Completed with work outstanding.
                                @if ($phase->completion_override_reason)
                                    &ldquo;{{ $phase->completion_override_reason }}&rdquo;
                                @endif
                            </p>
                        @endif
                    @endif

                    @if ($canComplete && $row['isCurrent'] && ! $phase->isCompleted())
                        <div class="project-phase-actions">
                            @if ($row['canBeCompleted'])
                                <form method="POST"
                                    action="{{ route($completeRoute, ['project' => $project->project_id, 'phase' => $phase->phase_id]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                                        Complete Phase
                                    </button>
                                </form>
                            @else
                                {{-- The button is not drawn, and the reason it
                                     is not is printed instead. A disabled
                                     button that says nothing is a dead end. --}}
                                <p class="project-phase-blocked mb-0">
                                    <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>
                                    @if ($row['totalTasks'] === 0)
                                        This phase has no tasks yet, so there is nothing to complete.
                                    @else
                                        This phase cannot be completed because {{ $row['openTasks'] }}
                                        {{ \Illuminate\Support\Str::plural('task', $row['openTasks']) }}
                                        {{ $row['openTasks'] === 1 ? 'is' : 'are' }} still incomplete.
                                    @endif
                                </p>

                                @if ($canOverrideCompletion && $row['openTasks'] > 0)
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#overridePhaseCompletionModal{{ $phase->phase_id }}">
                                        <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
                                        Complete Anyway
                                    </button>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>

                @if ($canOverrideCompletion && $row['isCurrent'] && ! $phase->isCompleted() && $row['openTasks'] > 0)
                    <div class="modal fade" id="overridePhaseCompletionModal{{ $phase->phase_id }}"
                        tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form method="POST"
                                action="{{ route($completeRoute, ['project' => $project->project_id, 'phase' => $phase->phase_id]) }}"
                                class="modal-content">
                                @csrf

                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title">
                                        <i class="bi bi-shield-exclamation me-2" aria-hidden="true"></i>
                                        Complete {{ $phase->numberLabel() }} Anyway
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white"
                                        data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body">
                                    {{-- One sentence, and nothing to fill in.
                                         The decision is recorded against the
                                         phase and in the activity log with the
                                         name of whoever took it. --}}
                                    <p class="mb-0">
                                        Completing {{ $phase->label() }} now leaves
                                        {{ $row['openTasks'] }}
                                        {{ \Illuminate\Support\Str::plural('task', $row['openTasks']) }}
                                        open and moves the project on to the next phase.
                                    </p>

                                    <input type="hidden" name="override" value="1">
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary"
                                        data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-danger">
                                        Complete Phase Anyway
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>

@if ($canOverrideStructure)
    {{-- Unlocking a locked structure. Super Admin only, and deliberately
         wordy: this is the action the rest of the feature exists to prevent
         anybody else taking. --}}
    <div class="modal fade" id="overridePhaseStructureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route($overrideRoute, $project->project_id) }}"
                class="modal-content">
                @csrf

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-unlock-fill me-2" aria-hidden="true"></i>
                        Override Phase Structure
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    {{-- One sentence, and nothing to fill in - but it has to be
                         the sentence that matters, so it names the count this
                         project's progress is currently measured against. --}}
                    <p class="mb-0">
                        Unlocking sends this project back to phase setup and stops it accepting new
                        tasks until you finalize it again, and changing its {{ $summary['total'] }}
                        {{ \Illuminate\Support\Str::plural('phase', $summary['total']) }} changes what
                        every progress figure on it is measured against.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-unlock me-1" aria-hidden="true"></i>
                        Unlock and Edit Phases
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif
