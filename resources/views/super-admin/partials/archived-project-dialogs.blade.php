{{-- The Restore dialogs for the rows on one page of the archive, rendered by
     ProjectController::archivedTable() beside the rows. Swapped in whole with
     every page, so only the rows on screen have one. --}}
@foreach ($projects as $project)
    <div class="modal fade" id="restoreProjectModal{{ $project->project_id }}" tabindex="-1"
        aria-labelledby="restoreProjectModalLabel{{ $project->project_id }}" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                @php
                    // What it will come back as. Null for anything
                    // archived before archiving preserved state:
                    // there is no schedule or team behind those, so
                    // Unscheduled is the honest answer and the one
                    // they have always restored to.
                    $returnsAs = $project->statusToRestore();
                @endphp

                <div class="modal-header">
                    <h5 class="modal-title" id="restoreProjectModalLabel{{ $project->project_id }}">
                        Restore {{ $project->reference_no ?? $project->name }}?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                {{-- One line. Whether the calendar allows it is not a
                     warning to read here - it is a question with an
                     answer, asked the moment Restore is pressed, and
                     shown in full in the Schedule Conflict dialog when
                     the answer is no. --}}
                <div class="modal-body">
                    @if ($returnsAs)
                        It returns as
                        <strong>{{ \App\Models\Project::statusLabelFor($returnsAs) }}</strong>
                        with its original schedule and team.
                    @else
                        It returns as <strong>Unscheduled</strong>; set dates and team again.
                    @endif

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert"
                        data-recovery-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    {{-- Sent with fetch rather than as a plain submit so
                         a refused restore can open the Schedule Conflict
                         dialog with the clash in it, rather than bouncing
                         the page and reducing it to a toast. This is
                         still a real form: a browser running no script
                         submits it, and the endpoint answers both. --}}
                    <form method="POST" data-recovery-form
                        data-project-id="{{ $project->project_id }}"
                        data-reference="{{ $project->reference_no ?? $project->name }}"
                        data-conflicts-url="{{ route('super-admin.projects.restore-conflicts', $project->project_id) }}"
                        data-recovery-failure="Unable to restore project. Nothing was changed."
                        action="{{ route('super-admin.projects.restore', $project->project_id) }}">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-success" data-recovery-submit>
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            Restore Project
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
@endforeach
