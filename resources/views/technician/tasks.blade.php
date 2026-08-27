@extends('layouts.portalNav')

@section('title', 'Tasks')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    <link href="/css/taskModal.css" rel="stylesheet">
    {{-- The Urgent Actions panel and the row badges, shared with the Super
         Admin Tasks page. --}}
    <link href="/css/taskAttention.css" rel="stylesheet">
@endpush

@section('content')
    @php
        $totalTasks = $tasksByProject->flatten()->count();
    @endphp

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">Tasks</h4>
            <p class="text-secondary small mb-0">
                The whole task board for every project you are on, grouped by project.
            </p>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary">
                {{ $totalTasks }} {{ \Illuminate\Support\Str::plural('task', $totalTasks) }}
            </span>

            @if ($creatableProjects->isNotEmpty())
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                    data-bs-target="#addTaskModal">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                    New Task
                </button>
            @endif
        </div>
    </div>

    {{-- A lead has no dashboard, so what needs arranging is said here, above
         the board and above the project filter. Empty for a plain technician,
         whose board is their own work and holds nothing they could assign -
         see TechnicianPortalController::tasks(). --}}
    <x-task-attention-alerts :summary="$attentionSummary" :read-only-note="$attentionReadOnly" />

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
        :viewer-technician-id="$technicianId" update-route="technician.tasks.update"
        complete-route="technician.tasks.complete" delete-route="technician.tasks.destroy"
        update-method="POST" complete-method="POST"
        empty-message="You are not assigned to any projects yet, so there is no task board to show." />

    @if ($creatableProjects->isNotEmpty())
        <x-task-create-modal :projects="$creatableProjects"
            :form-data-url="route('technician.projects.task-form-data', ['project' => '__ID__'])"
            :store-url="route('technician.tasks.store', ['project' => '__ID__'])" />
    @endif

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
