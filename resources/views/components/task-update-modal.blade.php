@props([
    'task',
])

@php
    // Posting again the same day edits that day's update, so the dialog opens
    // on what is already there.
    $today = \App\Support\BusinessTime::today();
    $todays = $task->updates->first(fn ($update) => $update->update_date?->isSameDay($today));
@endphp

{{--
    Today's progress on one task - by the technician holding it, or by the
    project's lead on their behalf. Drawn only for someone TaskProgress lets
    post; the endpoint asks again. A plain multipart form: the photos go with
    it.
--}}
<div class="modal fade" id="taskUpdateModal{{ $task->task_id }}" tabindex="-1" aria-hidden="true"
    aria-labelledby="taskUpdateTitle{{ $task->task_id }}">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" enctype="multipart/form-data"
            action="{{ route('technician.tasks.updates.store', $task->task_id) }}">
            @csrf

            <div class="modal-header">
                <h5 class="modal-title" id="taskUpdateTitle{{ $task->task_id }}">
                    {{ $todays ? "Edit Today's Update" : 'Add Update' }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="small text-secondary mb-3">
                    <span class="fw-semibold">{{ $task->task_title }}</span>
                    &middot; {{ $today->format(\App\Support\BusinessTime::DATE) }}
                    @if ($task->technician)
                        &middot; {{ $task->technician->name }}
                    @endif
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="taskUpdateNotes{{ $task->task_id }}">What was done today</label>
                    <textarea class="form-control" id="taskUpdateNotes{{ $task->task_id }}" name="notes" rows="4"
                        maxlength="2000" required>{{ $todays?->notes }}</textarea>
                </div>

                <div>
                    <label class="form-label fw-semibold" for="taskUpdatePhotos{{ $task->task_id }}">Photos</label>
                    <input type="file" class="form-control" id="taskUpdatePhotos{{ $task->task_id }}" name="images[]"
                        accept="image/jpeg,image/png" multiple>
                    <div class="form-text">Optional. Up to 5 JPG or PNG.</div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send me-1" aria-hidden="true"></i>
                    Post
                </button>
            </div>
        </form>
    </div>
</div>
