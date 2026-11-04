{{--
    One phase's tasks on the overall progress page, each with its updates from
    the first day on.

    Expects $tasks (from ProjectProgressOverview::build()) and $viewablePhotoIds.
--}}
@forelse ($tasks as $task)
    <div class="overall-task {{ $task['tone'] }}">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                <span class="overall-task-dot" aria-hidden="true"></span>
                <span class="fw-semibold">{{ $task['task'] }}</span>
                <span class="text-secondary">&middot; {{ $task['technician'] ?? 'Unassigned' }}</span>
            </span>
            <span>
                @if ($task['overdue'])
                    <span class="text-danger small">due {{ $task['due'] }}</span>
                @endif
                <span class="overall-pill {{ $task['tone'] }} ms-1">{{ $task['status'] }}</span>
            </span>
        </div>

        @foreach ($task['updates'] as $update)
            <div class="overall-update">
                <span class="overall-update-date">{{ $update['date'] }}</span>
                <span class="text-secondary">{{ $update['technician'] }}</span>
                <div style="white-space: pre-line">{{ $update['notes'] }}</div>
                @include('reports.partials.task-update-photos', ['imageIds' => $update['images']])
            </div>
        @endforeach
    </div>
@empty
    <p class="small text-secondary mt-2 mb-0">No tasks in progress.</p>
@endforelse
