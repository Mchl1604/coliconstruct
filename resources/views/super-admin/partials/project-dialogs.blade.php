{{-- The dialogs behind the rows on one page of the Projects table, rendered
     by ProjectController::table() beside the rows themselves - only the page
     on screen, never the whole list. Kept outside the table: a <div> is not
     valid inside a <tbody>, and serverTable.js swaps these in whole with
     every page. --}}
@foreach ($projects as $project)
    <!-- ON HOLD MODAL -->
    <div class="modal fade" id="onHoldModal{{ $project->project_id }}" tabindex="-1"
        aria-labelledby="onHoldModalLabel{{ $project->project_id }}" aria-hidden="true">

        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="onHoldModalLabel{{ $project->project_id }}">
                        Put Project On Hold
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>
                </div>

                <div class="modal-body">
                    Put <strong>{{ $project->reference_no }}</strong> on hold?
                    Dates from tomorrow are released.
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <form method="POST"
                        action="{{ route('super-admin.projects.hold', $project->project_id) }}">

                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-warning">
                            Put on Hold
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
    <!-- RESUME MODAL -->
    <div class="modal fade" id="resumeModal{{ $project->project_id }}"
        tabindex="-1" aria-labelledby="resumeModalLabel{{ $project->project_id }}"
        aria-hidden="true">

        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="resumeModalLabel{{ $project->project_id }}">
                        Resume Project
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>
                </div>

                <div class="modal-body">
                    Resume <strong>{{ $project->reference_no }}</strong>?
                    Its kept dates are rechecked.

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert"
                        data-recovery-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    {{-- Sent with fetch rather than as a plain submit so a
                         refused resume can open the Schedule Conflict dialog
                         with the clash in it, rather than bouncing the page
                         and reducing it to a toast. This is still a real
                         form: a browser running no script submits it, and
                         the endpoint answers both. --}}
                    <form method="POST" data-recovery-form
                        data-conflicts-url="{{ route('super-admin.projects.resume-conflicts', $project->project_id) }}"
                        data-recovery-failure="Unable to resume project. Nothing was changed."
                        action="{{ route('super-admin.projects.resume', $project->project_id) }}">

                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-success"
                            data-recovery-submit>
                            Resume Project
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <!-- COMPLETE PROJECT MODAL -->
    @if ($project->isCompletableBy(auth()->user()))
    <div class="modal fade" id="completeProjectModal{{ $project->project_id }}" tabindex="-1"
        aria-labelledby="completeProjectModalLabel{{ $project->project_id }}" aria-hidden="true">

        <div class="modal-dialog modal-lg">
            <div class="modal-content">

                <form method="POST"
                    action="{{ route('super-admin.projects.complete', $project->project_id) }}"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="completeProjectModalLabel{{ $project->project_id }}">
                            <i class="bi bi-check-circle me-2"></i>
                            Complete Project &mdash; {{ $project->reference_no }}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-3">
                            Mark <strong>{{ $project->reference_no }}</strong> as completed?
                        </p>

                        {{-- Said before the project starts waiting, because
                             afterwards it is a window too late to be useful.
                             Not a blocker, and deliberately not styled as one:
                             completion is never refused for want of a
                             registered client. The same notice the project's
                             own page shows, because both dialogs complete a
                             project on identical terms. --}}
                        @if (($confirmability[$project->project_id] ?? null) === \App\Services\CompletionConfirmability::UNREACHABLE)
                            <div class="alert alert-warning d-flex gap-2" role="status">
                                <i class="bi bi-person-slash fs-5 lh-1" aria-hidden="true"></i>
                                <div>
                                    <p class="fw-semibold mb-1">
                                        No registered client can currently confirm this project.
                                    </p>
                                    <p class="mb-0 small">
                                        Auto-completes after {{ \App\Models\Project::completionConfirmationDays() }} days.
                                    </p>
                                </div>
                            </div>
                        @endif

                        {{-- What the completion rules object to. A lead technician
                             is refused outright; an administrator may go ahead, but
                             the reason is written onto the project and into the
                             activity log, so it is never a silent decision. --}}
                        @php($blockers = $completionBlockers[$project->project_id] ?? [])

                        @if (! empty($blockers))
                            <div class="alert alert-warning" role="alert">
                                <p class="fw-semibold mb-2">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    This project is not ready to be completed
                                </p>
                                <ul class="mb-2 ps-3">
                                    @foreach ($blockers as $blocker)
                                        <li>{{ $blocker }}</li>
                                    @endforeach
                                </ul>
                                <label class="form-label fw-semibold mb-1"
                                    for="overrideReason{{ $project->project_id }}">
                                    Reason for completing it anyway
                                </label>
                                <textarea class="form-control"
                                    id="overrideReason{{ $project->project_id }}"
                                    name="completion_override_reason" rows="2" minlength="10"
                                    maxlength="500" required
                                    placeholder="Why complete it now?"></textarea>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Completion Date</label>
                            <input type="text" class="form-control" value="{{ \App\Support\BusinessTime::today()->format(\App\Support\BusinessTime::DATE) }} (today)" readonly>
                            <div class="form-text">A project is always completed as of today.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Completion Summary</label>
                            <textarea class="form-control" name="completion_summary" rows="3"
                                placeholder="Summarize the work that was completed..." required></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Completion Remarks</label>
                            <textarea class="form-control" name="completion_remarks" rows="2"
                                placeholder="Any additional remarks (optional)"></textarea>
                        </div>

                        <div class="mb-1">
                            <label class="form-label fw-semibold">Upload Completion Photos</label>
                            <input type="file" class="form-control" name="completion_photos[]"
                                accept=".jpg,.jpeg,.png" multiple>
                            <div class="form-text">JPG or PNG. Multiple allowed.</div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg me-1"></i>
                            Confirm Completion
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
    @endif

    <!-- ARCHIVE PROJECT MODAL -->
    @if ($canArchive && $project->isArchivable())
    <div class="modal fade" id="archiveProjectModal{{ $project->project_id }}" tabindex="-1"
        aria-labelledby="archiveProjectModalLabel{{ $project->project_id }}" aria-hidden="true">

        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="archiveProjectModalLabel{{ $project->project_id }}">
                        Archive {{ $project->reference_no ?? $project->name }}?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                {{-- The same short confirmation the Project
                     Details page asks, so archiving reads the
                     same wherever it is started from. --}}
                <div class="modal-body">
                    <strong>Nothing is deleted.</strong> Its technicians are freed.
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <form method="POST"
                        action="{{ route('super-admin.projects.archive', $project->project_id) }}">
                        @csrf
                        <button type="submit" class="btn btn-dark">
                            <i class="bi bi-archive me-1"></i>
                            Archive Project
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
    @endif
@endforeach
