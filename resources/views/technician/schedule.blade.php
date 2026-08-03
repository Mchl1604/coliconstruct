@extends('layouts.portalNav')

@section('title', 'My Schedule')

@push('styles')
    {{-- The Super Admin technician schedule's stylesheets, reused wholesale:
         the calendar id and every panel class below are already styled there. --}}
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    <link href="/css/calendar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">My Schedule</h4>
            <p class="text-secondary small mb-0">
                Every project you are booked on. Pick one to see its details and your tasks on it.
            </p>
        </div>

        <div class="d-flex gap-2">
            <span class="badge bg-primary">{{ $activeCount }} active now</span>
            <span class="badge bg-warning text-dark">{{ $upcomingCount }} upcoming</span>
        </div>
    </div>

    <div class="row g-3 technician-split">

        {{-- ---------------- LEFT: calendar ---------------- --}}
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border-0 rounded-2 h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <div class="technician-eyebrow">Calendar View</div>
                            <h5 class="fw-bold mb-0">My assigned projects</h5>
                        </div>
                        <span class="schedule-count-pill">
                            {{ count($events) }} scheduled {{ \Illuminate\Support\Str::plural('block', count($events)) }}
                        </span>
                    </div>

                    <div class="schedule-legend mb-3">
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#f0ad4e"></i> Pending</span>
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#0d6efd"></i> Ongoing</span>
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#fd7e14"></i> Overdue</span>
                        <span class="schedule-legend-item"><i class="schedule-dot" style="background:#198754"></i> Completed</span>
                    </div>

                    <div id="technicianCalendar" class="calendar-standard"></div>

                    @if (count($events) === 0)
                        <div class="schedule-empty-state mt-3">
                            You have no scheduled work at the moment.
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ---------------- RIGHT: project + my tasks ---------------- --}}
        <div class="col-12 col-xl-4">
            <div class="card shadow-sm border-0 rounded-2 h-100" data-details-panel>
                <div class="card-body p-3">

                    {{-- State 1: nothing clicked yet --}}
                    <div data-panel-empty>
                        <div class="technician-eyebrow mb-2">Project Information</div>
                        <div class="schedule-empty-state">
                            Select a project from the calendar to view its details.
                        </div>
                    </div>

                    {{-- State 2: loading --}}
                    <div class="d-none" data-panel-loading>
                        <div class="technician-eyebrow mb-2">Project Information</div>
                        <div class="text-secondary small py-3">
                            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                            Loading project&hellip;
                        </div>
                    </div>

                    {{-- State 3: a project is selected --}}
                    <div class="d-none" data-panel-project>
                        <div class="technician-eyebrow mb-2">Project Information</div>

                        <span class="panel-reference" data-panel-ref></span>

                        <h5 class="panel-project-name" data-panel-name></h5>

                        <div class="panel-meta">
                            <i class="bi bi-hash" aria-hidden="true"></i>
                            <span>Project ID: <span data-panel-id></span></span>
                        </div>

                        <div class="panel-meta">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <span data-panel-client></span>
                        </div>

                        <div class="panel-meta">
                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                            <span data-panel-address></span>
                        </div>

                        <div class="panel-meta">
                            <i class="bi bi-calendar3" aria-hidden="true"></i>
                            <span data-panel-schedule></span>
                        </div>

                        <div class="mt-2" data-panel-status></div>

                        <hr class="panel-divider">

                        <div class="panel-section-heading">
                            <i class="bi bi-people-fill" aria-hidden="true"></i>
                            Assigned Technicians
                        </div>

                        <div class="panel-lead-row">
                            <span class="panel-role-label">Lead</span>
                            <span class="panel-role-value" data-panel-lead></span>
                        </div>

                        <div class="panel-support-row">
                            <span class="panel-role-label">Supporting</span>
                            <div class="schedule-modal-team-chips" data-panel-supporting></div>
                        </div>

                        <hr class="panel-divider">

                        <div class="panel-section-heading">
                            <i class="bi bi-list-task" aria-hidden="true"></i>
                            Assigned Tasks
                            <span class="schedule-count-pill ms-auto d-none" data-panel-task-count></span>
                        </div>

                        <p class="text-muted small mb-2">Your own tasks on this project.</p>

                        <div class="panel-task-list" data-panel-tasks></div>

                        <div class="schedule-empty-state d-none" data-panel-tasks-empty>
                            You have no tasks on this project.
                        </div>

                        <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-panel-error></div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    @include('technician.lead.partials.complete-task-modal')

    @push('scripts')
        <script>
            window.leadScheduleEvents = @json($events);
            window.leadRoutes = {
                projectDetails: @json(route('technician.lead.projects.details', ['project' => '__ID__'])),
                completeTask: @json(route('technician.lead.tasks.complete', ['task' => '__ID__'])),
            };
        </script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script src="/js/technician/leadModals.js"></script>
        <script src="/js/calendarHeader.js"></script>
        <script src="/js/technician/leadSchedule.js"></script>
    @endpush
@endsection
