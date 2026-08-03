@props([
    'task',
    'action',
    'method' => 'PATCH',
])

@php
    // The technician who did the work is asked what it was. An administrator
    // or lead closing it on their behalf has no first-hand account to give.
    $isOwnTask = $task->isAssignedTo(auth()->user());
@endphp

{{--
    Closing a task: what was done, and a photo of it.

    Shared by both portals so a completed task always carries the same record,
    whoever closed it - the view modal reads exactly these fields back.
--}}
<div class="modal fade" id="completeTaskModal{{ $task->task_id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" action="{{ $action }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method($method)

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
                <h5 class="fw-bold mb-3">{{ $task->task_title }}</h5>

                @unless ($isOwnTask)
                    <div class="alert alert-info d-flex gap-2" role="alert">
                        <i class="bi bi-info-circle-fill flex-shrink-0" aria-hidden="true"></i>
                        <div>
                            This task is assigned to
                            <strong>{{ $task->technician?->name ?? 'someone else' }}</strong>.
                            You can close it on their behalf without filling anything in - the task
                            will record that you closed it, and note that no completion details were
                            submitted.
                        </div>
                    </div>
                @endunless

                <div class="mb-3">
                    <label class="form-label fw-semibold"
                        for="completionNotes{{ $task->task_id }}">
                        Task Description / Completion Notes
                        @unless ($isOwnTask)
                            <span class="text-muted fw-normal">(optional)</span>
                        @endunless
                    </label>
                    <textarea class="form-control" id="completionNotes{{ $task->task_id }}"
                        name="completion_notes" rows="4"
                        placeholder="{{ $isOwnTask ? 'Describe the work that was carried out...' : 'Anything worth recording about this closure (optional)' }}"
                        @required($isOwnTask)></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold"
                        for="completionImages{{ $task->task_id }}">
                        Upload Completion Image
                        <span class="text-muted fw-normal">(optional)</span>
                    </label>
                    <input type="file" class="form-control" id="completionImages{{ $task->task_id }}"
                        name="images[]" accept=".jpg,.jpeg,.png" multiple data-image-input
                        data-image-preview-target="#completionPreview{{ $task->task_id }}">
                    <div class="form-text">JPG, JPEG or PNG, up to 5 MB each.</div>
                </div>

                <div class="row g-2" id="completionPreview{{ $task->task_id }}"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                    Mark as Completed
                </button>
            </div>
        </form>
    </div>
</div>
