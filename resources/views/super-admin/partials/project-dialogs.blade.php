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
