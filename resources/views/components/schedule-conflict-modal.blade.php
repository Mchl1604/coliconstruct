{{-- SCHEDULE CONFLICT MODAL

     One dialog for the page rather than one per row, and one dialog for every
     flow that brings a project back - restoring an archived one, resuming a
     held one. It is opened by a refusal, never by a button, and only one
     recovery can be refused at a time.

     Everything inside it is drawn by scheduleRecovery.js from what the server
     answered, because what is in the way is a question about the calendar
     right now - not something that can be rendered with the page and still be
     true when somebody presses the button. That includes the words: which
     action this is, what it is called, and where it posts all arrive in the
     report, so this markup never has to know which flow it is serving. --}}
<div class="modal fade" id="scheduleConflictModal" tabindex="-1" aria-labelledby="scheduleConflictModalLabel"
    aria-hidden="true" data-conflict-modal>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header conflict-modal-header">
                <div>
                    <span class="conflict-eyebrow" data-conflict-eyebrow>Blocked</span>
                    <h5 class="modal-title mb-0" id="scheduleConflictModalLabel">Schedule Conflict</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                {{-- The verdict on the schedule as a whole, rewritten by the
                     script every time it is rechecked. --}}
                <div class="alert alert-danger" role="alert" data-conflict-summary>
                    This project's schedule conflicts with the current availability of its team.
                    Review the affected schedule ranges before continuing.
                </div>

                <div class="conflict-restoring" data-conflict-restoring></div>

                <div class="alert d-none" role="alert" data-conflict-feedback></div>

                {{-- The project's schedule, one row per range it actually
                     holds - which is the thing being reviewed and edited here,
                     not a list of days and not a list of people. --}}
                <div data-conflict-list></div>
            </div>

            <div class="modal-footer conflict-modal-footer">
                <span class="conflict-checked text-secondary small me-auto" data-conflict-checked></span>

                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>

                <button type="button" class="btn btn-outline-primary" data-conflict-recheck>
                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                    Recheck availability
                </button>

                {{-- Enabled only by a recheck that came back clean, and even
                     then the action behind it asks the whole question again
                     before it writes anything. Its label is set by the script
                     from the flow the report names. --}}
                <button type="button" class="btn btn-success" data-conflict-commit disabled>
                    Continue
                </button>
            </div>

        </div>
    </div>
</div>
