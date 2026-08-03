@extends('layouts.portalNav')

@section('title', 'My Tasks')

@push('styles')
    <link href="/css/taskModal.css" rel="stylesheet">
@endpush

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
                            <th class="text-center">Action</th>
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
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                        data-bs-toggle="modal" data-bs-target="#taskModal{{ $task->task_id }}"
                                        title="View task">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-secondary text-center py-4">
                                    You have no tasks assigned.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- The same dialog the Super Admin portal opens, always view only here:
         a technician reads their work, they do not reassign it. --}}
    @foreach ($tasks as $task)
        <x-task-details-modal :task="$task"
            :schedule-ranges="$task->project
                ? $task->project->schedules->map(fn($schedule) => [
                    'start' => \Carbon\Carbon::parse($schedule->start_datetime)->format('Y-m-d'),
                    'end' => \Carbon\Carbon::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('Y-m-d'),
                ])->values()
                : collect()" />
    @endforeach
@endsection
