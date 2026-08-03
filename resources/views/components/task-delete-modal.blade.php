@props([
    'task',
    'action',
])

{{--
    Deleting a task. Shared by both portals.

    A completed task can be deleted too, and its completion notes and photos go
    with it, so the warning says so rather than letting that come as a
    surprise.
--}}
<div class="modal fade" id="deleteTaskModal{{ $task->task_id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" action="{{ $action }}" method="POST">
            @csrf
            @method('DELETE')

            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-trash me-2" aria-hidden="true"></i>
                    Delete Task
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="mb-1">This will permanently delete</p>
                <h5 class="fw-bold">"{{ $task->task_title }}"</h5>

                @if ($task->isCompleted())
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                        This task is already completed. Its completion notes
                        @if ($task->images->isNotEmpty())
                            and {{ $task->images->count() }}
                            {{ \Illuminate\Support\Str::plural('photo', $task->images->count()) }}
                        @endif
                        will be deleted with it.
                    </div>
                @endif

                <p class="text-danger mb-0 mt-3">This action cannot be undone.</p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i>
                    Delete Task
                </button>
            </div>
        </form>
    </div>
</div>
