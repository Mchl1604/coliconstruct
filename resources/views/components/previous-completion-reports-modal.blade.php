@props([
    'project',
    'reports',
])

{{--
    The completion cycles a project has already been through.

    A dialog rather than a page of its own: these are the same fields the
    completion section prints, read-only, and a superseded report is never the
    answer to "what is this project now" - so it is deliberately something a
    person has to open rather than something the page shows them.

    Every cycle is labelled Superseded and dated, so none of them can be
    mistaken for the current report. Nothing here can change the project: there
    is no restore, no promote and no delete. The current report, when there is
    one, lives on the project row and is printed by the page itself.
--}}
<div class="modal fade" id="previousCompletionReportsModal" tabindex="-1"
    aria-labelledby="previousCompletionReportsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="previousCompletionReportsModalLabel">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>
                    Previous Completion Reports &mdash; {{ $project->reference_no ?? $project->name }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-secondary border-0 small" role="alert">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    These reports are historical. Each one was superseded when the project was reopened,
                    and none of them is this project's current completion report.
                </div>

                @foreach ($reports as $previousReport)
                    <div class="card border shadow-sm mb-3">
                        <div
                            class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span class="fw-bold">{{ $previousReport->cycleLabel() }}</span>
                            <span class="badge bg-secondary">{{ $previousReport->statusLabel() }}</span>
                        </div>

                        <div class="card-body">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <span class="fw-semibold d-block">Completion Date:</span>
                                    <span>
                                        {{ \App\Support\BusinessTime::format($previousReport->completed_at, \App\Support\BusinessTime::DATE, 'N/A') }}
                                    </span>
                                </div>

                                <div class="col-md-6">
                                    <span class="fw-semibold d-block">Submitted By:</span>
                                    <span>{{ $previousReport->submitterName() }}</span>
                                </div>

                                <div class="col-md-6">
                                    <span class="fw-semibold d-block">Report Status:</span>
                                    <span>
                                        {{ $previousReport->statusLabel() }}
                                        @if ($previousReport->project_status)
                                            &mdash; the project was
                                            {{ \App\Models\Project::statusLabelFor($previousReport->project_status) }}
                                        @endif
                                    </span>
                                </div>

                                <div class="col-md-6">
                                    <span class="fw-semibold d-block">Superseded On:</span>
                                    <span>
                                        {{ \App\Support\BusinessTime::format($previousReport->superseded_at, \App\Support\BusinessTime::DATE, 'N/A') }}
                                        @if ($previousReport->supersededByUser)
                                            by {{ $previousReport->supersededByUser->fullName() }}
                                        @endif
                                    </span>
                                </div>

                                @if ($previousReport->completionMethodLabel())
                                    <div class="col-12">
                                        <span class="fw-semibold d-block">Signed Off:</span>
                                        <span>{{ $previousReport->completionMethodLabel() }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="mb-2">
                                <span class="fw-semibold d-block">Completion Summary:</span>
                                <p class="mb-0">{{ $previousReport->completion_summary ?? 'N/A' }}</p>
                            </div>

                            @if ($previousReport->completion_remarks)
                                <div class="mb-2">
                                    <span class="fw-semibold d-block">Completion Remarks:</span>
                                    <p class="mb-0">{{ $previousReport->completion_remarks }}</p>
                                </div>
                            @endif

                            @if ($previousReport->supersede_reason)
                                <div class="mb-2">
                                    <span class="fw-semibold d-block">Reason The Project Was Reopened:</span>
                                    <p class="mb-0">{{ $previousReport->supersede_reason }}</p>
                                </div>
                            @endif

                            {{-- Carried over with the report it belongs to: a cycle
                                 signed off over its own blockers has to go on saying
                                 so once it is history. --}}
                            @if ($previousReport->completionWasOverridden())
                                <div class="alert alert-warning mt-3 mb-0" role="alert">
                                    <p class="fw-semibold mb-2">
                                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                        Completed over its blockers by
                                        {{ $previousReport->completionOverriddenByUser?->fullName() ?? 'an administrator' }}
                                    </p>

                                    @if (! empty($previousReport->completion_override_blockers))
                                        <ul class="mb-2 ps-3">
                                            @foreach ($previousReport->completion_override_blockers as $blocker)
                                                <li>{{ $blocker }}</li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <span class="fw-semibold d-block">Reason given:</span>
                                    <p class="mb-0">{{ $previousReport->completion_override_reason }}</p>
                                </div>
                            @endif

                            @if ($previousReport->photos->isNotEmpty())
                                <div class="mt-3">
                                    <span class="fw-semibold d-block mb-2">Completion Photos:</span>
                                    <div class="row g-3">
                                        @foreach ($previousReport->photos as $photo)
                                            <div class="col-md-4 col-6">
                                                <a href="{{ $photo->url() }}" target="_blank" rel="noopener noreferrer">
                                                    <img src="{{ $photo->url() }}" class="img-fluid rounded border"
                                                        alt="Completion photo"
                                                        style="height:140px;width:100%;object-fit:cover;">
                                                </a>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>
