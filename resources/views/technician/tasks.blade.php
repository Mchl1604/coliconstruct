@extends('layouts.portalNav')

@section('title', 'My Tasks')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">My Tasks</h4>
            <p class="text-secondary small mb-0">Work assigned to you, most urgent first.</p>
        </div>

        <span class="badge bg-secondary">{{ $tasks->count() }} tasks</span>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Task</th>
                            <th>Project</th>
                            <th>Start Date</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tasks as $task)
                            @php
                                $due = $task->due_date ? \Carbon\CarbonImmutable::parse($task->due_date) : null;
                                // A task only reads as late while it is still open.
                                $isLate = $due && $due->lt($today) && ! in_array($task->status, ['completed', 'cancelled'], true);

                                $badge = match ($task->status) {
                                    'completed' => 'bg-success',
                                    'ongoing' => 'bg-primary',
                                    'cancelled' => 'bg-danger',
                                    'unassigned' => 'bg-warning text-dark',
                                    default => 'bg-secondary',
                                };
                            @endphp
                            <tr>
                                <td class="fw-semibold">
                                    {{ $task->task_title }}
                                    <div class="small text-muted fw-normal">{{ $task->task_description }}</div>
                                </td>
                                <td>{{ $task->project?->name ?? '—' }}</td>
                                <td>{{ $task->start_date ? \Carbon\CarbonImmutable::parse($task->start_date)->format('M j, Y') : '—' }}</td>
                                <td>
                                    {{ $due?->format('M j, Y') ?? '—' }}
                                    @if ($isLate)
                                        <span class="badge badge-overdue ms-1">Overdue</span>
                                    @endif
                                </td>
                                <td><span class="badge {{ $badge }}">{{ ucfirst($task->status) }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-secondary text-center py-4">
                                    You have no tasks assigned.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
