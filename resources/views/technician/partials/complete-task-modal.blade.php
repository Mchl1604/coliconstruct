{{--
    Completing a task: what was done, and a photo of it.

    One instance per page, driven by modals.js - the schedule panel, the
    project view and the task board all open this same dialog rather than each
    stamping out a copy per task.
--}}
<div class="modal fade" id="completeTaskModal" tabindex="-1" aria-hidden="true" data-complete-task-modal>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" data-complete-task-form enctype="multipart/form-data">
            @csrf

            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle me-2" aria-hidden="true"></i>
                    Complete Task
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="mb-1 text-secondary small">You are completing</p>
                <h5 class="fw-bold mb-3" data-complete-task-title>&nbsp;</h5>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="completionNotes">
                        Task Description / Completion Notes
                    </label>
                    <textarea class="form-control" id="completionNotes" name="completion_notes" rows="4"
                        placeholder="Describe the work that was carried out..." required></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="completionImages">
                        Upload Completion Image
                    </label>
                    <input type="file" class="form-control" id="completionImages" name="images[]"
                        accept=".jpg,.jpeg,.png" multiple data-complete-task-images>
                    <div class="form-text">JPG, JPEG or PNG, up to 5 MB each. Optional.</div>
                </div>

                <div class="row g-2" data-complete-task-preview></div>

                <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-complete-task-error></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" data-complete-task-submit>
                    <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                        data-spinner></span>
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>
