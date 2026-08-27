@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    {{-- The Assign To picker cards and the completion record, shared with the
         technician portal's task dialogs. --}}
    <link href="/css/taskModal.css" rel="stylesheet">
    {{-- The Urgent Actions panel and the row badges, shared with the lead
         technician's Tasks page. --}}
    <link href="/css/taskAttention.css" rel="stylesheet">
@endpush

@section('content')
    @php
        $totalTasks = $tasksByProject->flatten()->count();
    @endphp

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">Task</h4>
            <p class="text-secondary small mb-0">Every project's task board, grouped by project.</p>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary">
                {{ $totalTasks }} {{ \Illuminate\Support\Str::plural('task', $totalTasks) }}
            </span>

            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                data-bs-target="#addTaskModal">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                Add New Task
            </button>
        </div>
    </div>

    {{-- Above the project filter, so the work that cannot proceed is read
         before the board it is buried in. Reached from the dashboard's Urgent
         Actions, which arrives with ?attention=all already set. --}}
    <x-task-attention-alerts :summary="$attentionSummary" />

    <div class="card shadow-sm border-0 rounded-2 mb-3">
        <div class="card-body p-3">
            <div class="d-flex align-items-center gap-2" style="max-width: 420px;">
                <label for="taskProjectFilter" class="fw-semibold small text-nowrap mb-0">Project:</label>
                <select id="taskProjectFilter" class="form-select form-select-sm" data-project-filter>
                    <option value="all">All Projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->project_id }}">
                            {{ $project->reference_no }} &mdash; {{ $project->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <x-task-board :projects="$projects" :tasks-by-project="$tasksByProject"
        :technicians-by-project="$techniciansByProject" :ranges-by-project="$rangesByProject"
        :active-task-counts-by-project="$technicianActiveTaskCounts" :manageable="$manageable"
        update-route="super-admin.tasks.update" complete-route="super-admin.tasks.complete"
        delete-route="super-admin.tasks.destroy"
        empty-message="No active projects to show." />

    <x-task-create-modal :projects="$schedulableProjects"
        :form-data-url="route('super-admin.projects.task-form-data', ['id' => '__ID__'])"
        :store-url="route('super-admin.task.store', ['id' => '__ID__'])" />

    @push('scripts')
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="/js/super-admin/taskDatePickers.js"></script>
        <script src="/js/imagePreview.js"></script>
        <script src="/js/taskBoard.js"></script>
        {{-- Lets a task notification land on the task itself. --}}
        <script src="/js/openTaskFromQuery.js"></script>
        <script src="/js/taskCreate.js"></script>
    @endpush
@endsection
