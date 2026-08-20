@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/calendar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Schedules</h4>
            <p class="text-secondary small mb-0">View every scheduled project on the calendar, and click a project
                to edit its date ranges. Completed projects open as a view-only record.</p>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2 mb-3">
        <div class="card-body p-3">
            {{-- The legend sits left of the one thing the calendar cannot show
                 you: the projects that have no dates to draw. --}}
            <div class="schedule-legend-bar mb-3">
                <div class="schedule-legend">
                    {{-- Read from the model so the key and the bookings cannot
                         disagree - written out by hand it went stale. --}}
                    @foreach (\App\Models\Project::calendarLegend() as $entry)
                        <span class="schedule-legend-item">
                            <i class="schedule-dot" style="background:{{ $entry['colour'] }}"></i>
                            {{ $entry['label'] }}
                        </span>
                    @endforeach
                </div>

                <button type="button" class="btn btn-sm btn-outline-primary schedule-unscheduled-btn"
                    data-bs-toggle="modal" data-bs-target="#unscheduledProjectsModal">
                    <i class="bi bi-calendar-plus" aria-hidden="true"></i>
                    Unscheduled Projects
                    <span class="schedule-unscheduled-count">{{ $unscheduledProjects->count() }}</span>
                </button>
            </div>

            <div id="schedulesCalendar" class="calendar-standard"></div>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table id="schedulesTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Project ID</th>
                            <th>Reference No.</th>
                            <th>Project</th>
                            <th>Assigned Technicians</th>
                            <th>Date Ranges</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    {{-- Only projects that actually hold dates. One with none
                         is Unscheduled and belongs on neither this table nor
                         the calendar until it is booked again. --}}
                    <tbody>
                        @foreach ($scheduledProjects as $project)
                            <tr>
                                <td>{{ $project->displayCode() }}</td>
                                <td>{{ $project->reference_no }}</td>
                                <td>{{ $project->name }}</td>
                                <td>
                                    {{ $project->projectTechnicians->pluck('technician.name')->filter()->join(', ') ?: 'Unassigned' }}
                                </td>
                                <td>
                                    {{-- describe() is the one place that decides how a
                                         schedule reads, so a single day says one date and
                                         a partial day carries its hours. --}}
                                    @foreach ($project->schedules as $schedule)
                                        <div class="small">{{ $schedule->describe() }}</div>
                                    @endforeach
                                </td>
                                <td>
                                    <x-project-status-badge :project="$project" />
                                </td>
                                <td class="text-center">
                                    {{-- A closed record and a paused project are
                                         both fixed until something else changes,
                                         so both get the view-only panel. Asked of
                                         the model so this button and the endpoint
                                         behind it cannot disagree. --}}
                                    @if (! $project->scheduleIsEditable())
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2"
                                            data-bs-toggle="modal"
                                            data-bs-target="#scheduleViewModal{{ $project->project_id }}">
                                            <i class="bi bi-eye"></i>
                                            View Schedule
                                        </button>
                                    @else
                                        <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                            data-bs-toggle="modal" data-bs-target="#scheduleEditModal{{ $project->project_id }}">
                                            <i class="bi bi-calendar2-week"></i>
                                            Edit Schedule
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Per-project schedule edit modals. A completed project is a historical
         record and a held one is paused, so neither gets an edit modal at all -
         both get the view-only one below instead. Rendered from every listed
         project rather than only the scheduled ones, so the Update Schedule
         link on a project's own page still opens the editor for a project that
         currently holds no dates. --}}
    @foreach ($projects->filter(fn ($project) => $project->scheduleIsEditable()) as $project)
        <div class="modal fade" id="scheduleEditModal{{ $project->project_id }}" tabindex="-1"
            aria-labelledby="scheduleEditModalLabel{{ $project->project_id }}" aria-hidden="true"
            data-schedule-edit-modal
            data-project-id="{{ $project->project_id }}"
            data-partial-day-allowed="{{ $project->isResidential() ? '1' : '0' }}"
            data-technician-ids="{{ $project->projectTechnicians->pluck('technician_id')->implode(',') }}">

            <div class="modal-dialog modal-lg">
                <div class="modal-content">

                    <form method="POST" action="{{ route('super-admin.schedules.update', $project->project_id) }}">
                        @csrf
                        @method('PUT')

                        <div class="modal-header align-items-start">
                            <div class="schedule-modal-heading">
                                <span class="schedule-modal-eyebrow">Edit Schedule</span>
                                <h5 class="modal-title mb-1" id="scheduleEditModalLabel{{ $project->project_id }}">
                                    {{ $project->name }}
                                </h5>
                                <a href="{{ route('super-admin.projects.show', $project->project_id) }}"
                                    class="schedule-modal-ref" title="Open project details">
                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                    {{ $project->reference_no }}
                                </a>
                            </div>

                            {{-- Where the project stands, in the corner. The same
                                 badge the projects table and the calendar draw, so
                                 the exact status is on screen while its dates are
                                 being changed rather than a page away. --}}
                            <div class="schedule-modal-header-end">
                                <x-project-status-badge :project="$project" />
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                        </div>

                        <div class="modal-body">
                            <div class="schedule-modal-team">
                                <span class="schedule-modal-team-label">
                                    <i class="bi bi-people-fill" aria-hidden="true"></i>
                                    Assigned Technicians
                                </span>

                                <div class="schedule-modal-team-chips">
                                    @forelse ($project->projectTechnicians as $projectTechnician)
                                        @continue(! $projectTechnician->technician)

                                        {{-- Picture in the chip: who is booked
                                             is easier to scan as faces than as
                                             a row of names. --}}
                                        <span class="schedule-tech-chip">
                                            <x-user-avatar :user="$projectTechnician->technician->account"
                                                size="xs" class="me-1" />
                                            {{ $projectTechnician->technician->name }}
                                        </span>
                                    @empty
                                        <span class="text-muted small">No technicians assigned</span>
                                    @endforelse
                                </div>
                            </div>

                            <div class="schedule-section-heading">
                                <span>
                                    <i class="bi bi-calendar2-week me-1" aria-hidden="true"></i>
                                    Date Ranges
                                </span>
                                <span class="schedule-count-pill" data-ranges-count>
                                    {{ $project->schedules->count() }}
                                    scheduled
                                </span>
                            </div>

                            {{-- A project may hold no dates at all, so this
                                 list starts empty rather than with a blank
                                 row nobody asked for. --}}
                            <div class="schedule-range-list" data-ranges-container
                                data-next-index="{{ $project->schedules->count() }}">
                                @foreach ($project->schedules as $index => $schedule)
                                    <x-schedule-range-row :schedule="$schedule" :index="$index"
                                        :working-hours="$workingHours"
                                        :partial-day-allowed="$project->isResidential()" />
                                @endforeach
                            </div>

                            <div class="schedule-empty-state {{ $project->schedules->isEmpty() ? '' : 'd-none' }}"
                                data-ranges-empty>
                                This project holds no dates. It stays Unscheduled, and off the calendar and the
                                table, until a schedule is added.
                            </div>

                            <button type="button" class="schedule-add-range" data-add-range>
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                Add New Schedule
                            </button>

                            <div class="schedule-range-error d-none" data-range-error></div>

                            <p class="schedule-modal-note">
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <span>
                                    Removing every schedule leaves this project Unscheduled, and a new one can't
                                    overlap time this project's technicians are already booked for elsewhere.
                                    @if ($project->isResidential())
                                        A Partial Day schedule books set hours on one date, leaving the rest of
                                        that day free.
                                    @endif
                                </span>
                            </p>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" data-schedule-submit>
                                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                                Save Schedule
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>

    @endforeach

    {{-- The same panel, read rather than written. A completed project - and a
         held one - is clickable everywhere an editable one is, on the calendar
         and from the table, and shows exactly what it is scheduled for with
         nothing to change. --}}
    @foreach ($scheduledProjects->reject(fn ($project) => $project->scheduleIsEditable()) as $project)
        <div class="modal fade" id="scheduleViewModal{{ $project->project_id }}" tabindex="-1"
            aria-labelledby="scheduleViewModalLabel{{ $project->project_id }}" aria-hidden="true"
            data-schedule-view-modal data-project-id="{{ $project->project_id }}">

            <div class="modal-dialog modal-lg">
                <div class="modal-content">

                    <div class="modal-header align-items-start">
                        <div class="schedule-modal-heading">
                            <span class="schedule-modal-eyebrow">Schedule &middot; View Only</span>
                            <h5 class="modal-title mb-1" id="scheduleViewModalLabel{{ $project->project_id }}">
                                {{ $project->name }}
                            </h5>
                            <a href="{{ route('super-admin.projects.show', $project->project_id) }}"
                                class="schedule-modal-ref" title="Open project details">
                                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                {{ $project->reference_no }}
                            </a>
                        </div>

                        {{-- The exact status, in the corner: this panel is read
                             precisely because the project is in a state whose
                             schedule cannot be changed, so it has to say which. --}}
                        <div class="schedule-modal-header-end">
                            <x-project-status-badge :project="$project" />
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                    </div>

                    <div class="modal-body">
                        <div class="schedule-modal-team">
                            <span class="schedule-modal-team-label">
                                <i class="bi bi-people-fill" aria-hidden="true"></i>
                                Assigned Technicians
                            </span>

                            <div class="schedule-modal-team-chips">
                                @forelse ($project->projectTechnicians as $projectTechnician)
                                    @continue(! $projectTechnician->technician)

                                    <span class="schedule-tech-chip">
                                        <x-user-avatar :user="$projectTechnician->technician->account"
                                            size="xs" class="me-1" />
                                        {{ $projectTechnician->technician->name }}
                                    </span>
                                @empty
                                    <span class="text-muted small">No technicians assigned</span>
                                @endforelse
                            </div>
                        </div>

                        <div class="schedule-section-heading">
                            <span>
                                <i class="bi bi-calendar2-week me-1" aria-hidden="true"></i>
                                Date Ranges
                            </span>
                            <span class="schedule-count-pill">
                                {{ $project->schedules->count() }}
                                scheduled
                            </span>
                        </div>

                        <div class="schedule-range-list">
                            {{-- describe() again, so a date reads here exactly
                                 as it does in the table and on the calendar. --}}
                            @foreach ($project->schedules->sortBy('start_datetime') as $schedule)
                                <div class="schedule-range-static">
                                    <span class="schedule-range-index"></span>
                                    <span class="schedule-range-static-label">
                                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                                        {{ $schedule->describe() }}
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        <p class="schedule-modal-note">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <span>
                                @if ($project->on_hold)
                                    This project is on hold. The dates below are the days that were worked before
                                    it was paused; the dates ahead of it were released. Resume the project before
                                    scheduling it again.
                                @else
                                    This project is {{ strtolower($project->statusLabel()) }}, so its schedule is a
                                    record of what happened and can no longer be changed.
                                @endif
                            </span>
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                            Close
                        </button>
                        <a href="{{ route('super-admin.projects.show', $project->project_id) }}"
                            class="btn btn-primary">
                            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                            Open Project
                        </a>
                    </div>

                </div>
            </div>
        </div>
    @endforeach

    {{-- The projects the calendar cannot show, and the flow for giving one of
         them its first dates.

         Two panels in one dialog, swapped in place: picking a project replaces
         the list with its form and the Back button brings the list straight
         back. Stacking a second modal on this one would leave two dialogs and
         two backdrops fighting over the same screen. --}}
    <div class="modal fade" id="unscheduledProjectsModal" tabindex="-1"
        aria-labelledby="unscheduledProjectsModalLabel" aria-hidden="true" data-unscheduled-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow" data-unscheduled-eyebrow>Unscheduled</span>
                        <h5 class="modal-title mb-0" id="unscheduledProjectsModalLabel" data-unscheduled-title>
                            Projects with no dates
                        </h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    {{-- Panel one: everything waiting for dates. --}}
                    <div data-unscheduled-list-panel>
                        <p class="text-secondary small">
                            These projects hold no dates, so they appear on neither the calendar nor the table.
                            Schedule one to put it on both.
                        </p>

                        <div class="schedule-date-list">
                            @forelse ($unscheduledProjects as $project)
                                <div class="schedule-date-card">
                                    <div class="schedule-date-card-top">
                                        <div>
                                            <div class="schedule-date-card-name">{{ $project->name }}</div>
                                            <div class="schedule-date-card-meta">
                                                <a href="{{ route('super-admin.projects.show', $project->project_id) }}"
                                                    class="schedule-modal-ref">
                                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                                    {{ $project->reference_no ?? 'No reference' }}
                                                </a>
                                                @php
                                                    $client = $project->clients->first()?->fullname
                                                        ?? $project->clients->first()?->company_name;
                                                @endphp
                                                @if ($client)
                                                    &middot; {{ $client }}
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Everything the form needs travels on
                                             the button, so picking a project is
                                             one click and no round trip. --}}
                                        <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                            data-unscheduled-pick="{{ $project->project_id }}"
                                            data-project-name="{{ $project->name }}"
                                            data-reference-no="{{ $project->reference_no }}"
                                            data-partial-day-allowed="{{ $project->isResidential() ? '1' : '0' }}"
                                            data-technician-ids="{{ $project->projectTechnicians->pluck('technician_id')->implode(',') }}">
                                            <i class="bi bi-calendar2-week" aria-hidden="true"></i>
                                            Schedule
                                        </button>
                                    </div>

                                    <div class="schedule-pick-techs">
                                        @forelse ($project->projectTechnicians as $projectTechnician)
                                            @continue(! $projectTechnician->technician)

                                            <span class="schedule-tech-chip">
                                                {{ $projectTechnician->technician->name }}
                                            </span>
                                        @empty
                                            <span class="text-muted small">No technicians assigned</span>
                                        @endforelse
                                    </div>
                                </div>
                            @empty
                                <div class="schedule-empty-state">
                                    Every project has its dates. Nothing is waiting to be scheduled.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    {{-- Panel two: the dates for the project just picked. --}}
                    <div class="d-none" data-unscheduled-form-panel>
                        <button type="button" class="schedule-back-link" data-unscheduled-back>
                            <i class="bi bi-arrow-left" aria-hidden="true"></i>
                            All unscheduled projects
                        </button>

                        <div class="schedule-add-panel">
                            <div class="mb-3 d-none" data-unscheduled-mode-wrap>
                                <label class="form-label small mb-1" for="unscheduledMode">Scheduling Mode</label>
                                <select class="form-select" id="unscheduledMode" data-unscheduled-mode>
                                    <option value="{{ \App\Models\Schedule::MODE_DATE_BASED }}">Date-Based</option>
                                    <option value="{{ \App\Models\Schedule::MODE_PARTIAL_DAY }}">Partial Day</option>
                                </select>
                                <div class="form-text" data-unscheduled-mode-hint>
                                    Books the whole of every day in the range.
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6" data-unscheduled-date-based>
                                    <label class="form-label small mb-1" for="unscheduledStartDate">Start Date</label>
                                    <input type="text" class="form-control" id="unscheduledStartDate"
                                        data-unscheduled-start placeholder="Select start date">
                                </div>

                                <div class="col-md-6" data-unscheduled-date-based>
                                    <label class="form-label small mb-1" for="unscheduledEndDate">End Date</label>
                                    <input type="text" class="form-control" id="unscheduledEndDate"
                                        data-unscheduled-end placeholder="Select end date">
                                </div>

                                <div class="col-md-4" data-unscheduled-partial-day hidden>
                                    <label class="form-label small mb-1" for="unscheduledDate">Project Date</label>
                                    <input type="text" class="form-control" id="unscheduledDate"
                                        data-unscheduled-date placeholder="Select date">
                                </div>

                                <div class="col-md-4" data-unscheduled-partial-day hidden>
                                    <label class="form-label small mb-1" for="unscheduledStartTime">Start Time</label>
                                    <select class="form-select" id="unscheduledStartTime" data-unscheduled-start-time>
                                        <option value="">Select start time</option>
                                        @foreach ($workingHours as $hour)
                                            <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-4" data-unscheduled-partial-day hidden>
                                    <label class="form-label small mb-1" for="unscheduledEndTime">End Time</label>
                                    <select class="form-select" id="unscheduledEndTime" data-unscheduled-end-time>
                                        <option value="">Select end time</option>
                                        @foreach ($workingHours as $hour)
                                            <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <p class="schedule-modal-note mb-0">
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <span data-unscheduled-note>
                                    Dates its technicians are already booked for elsewhere cannot be picked.
                                    Scheduling the project moves it onto the calendar and the table.
                                </span>
                            </p>
                        </div>

                        <div class="alert alert-danger mt-3 d-none" role="alert" data-unscheduled-error></div>
                        <div class="alert alert-success mt-3 d-none" role="alert" data-unscheduled-success></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success d-none" data-unscheduled-save>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status"
                            aria-hidden="true" data-unscheduled-save-spinner></span>
                        Save Schedule
                    </button>
                </div>

            </div>
        </div>
    </div>

    {{-- Clicking any calendar date opens this: what's booked that day, plus
         the flow for scheduling another project starting from it. --}}
    <div class="modal fade" id="scheduleDateModal" tabindex="-1" aria-labelledby="scheduleDateModalLabel"
        aria-hidden="true" data-schedule-date-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow">Schedule</span>
                        <h5 class="modal-title mb-0" id="scheduleDateModalLabel" data-date-title>Selected date</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    {{-- Projects already booked on the clicked date --}}
                    <div data-date-projects-section>
                        <div class="schedule-section-heading">
                            <span><i class="bi bi-calendar2-check me-1" aria-hidden="true"></i> Scheduled on this
                                date</span>
                            <span class="schedule-count-pill d-none" data-date-count></span>
                        </div>

                        <div data-date-loading class="text-secondary small py-3">
                            <span class="spinner-border spinner-border-sm me-2" role="status"
                                aria-hidden="true"></span>
                            Loading projects&hellip;
                        </div>

                        <div data-date-projects class="schedule-date-list"></div>

                        <div data-date-empty class="schedule-empty-state d-none">
                            No projects are scheduled on this date.
                        </div>

                        {{-- Taking this date off one of the bookings above.
                             Completed, cancelled and archived work is listed
                             but not editable, so it carries no action. --}}
                        <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-date-error></div>
                        <div class="alert alert-success mt-3 mb-0 d-none" role="alert" data-date-success></div>
                    </div>

                    <hr class="my-4">

                    {{-- Step 1: reveal the add-project flow --}}
                    <button type="button" class="btn btn-primary" data-add-project-toggle>
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                        Add Project
                    </button>

                    {{-- Step 2 onward --}}
                    <div class="schedule-add-panel d-none" data-add-project-panel>
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="scheduleAddMode">Scheduling Mode</label>
                            <select class="form-select" id="scheduleAddMode" data-add-mode>
                                <option value="{{ \App\Models\Schedule::MODE_DATE_BASED }}">Date-Based</option>
                                <option value="{{ \App\Models\Schedule::MODE_PARTIAL_DAY }}">Partial Day</option>
                            </select>
                            <div class="form-text" data-add-mode-hint>
                                Books the whole of every day in the range.
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6" data-add-date-based>
                                <label class="form-label small mb-1" for="scheduleAddStartDate">Start Date</label>
                                <input type="text" class="form-control" id="scheduleAddStartDate"
                                    data-add-start readonly disabled>
                                <div class="form-text">Set by the date you clicked.</div>
                            </div>

                            <div class="col-md-6" data-add-date-based>
                                <label class="form-label small mb-1" for="scheduleAddEndDate">End Date</label>
                                <input type="text" class="form-control" id="scheduleAddEndDate"
                                    data-add-end placeholder="Select end date" required>
                                <div class="form-text">Pick this to see which projects are free.</div>
                            </div>

                            <div class="col-md-4" data-add-partial-day hidden>
                                <label class="form-label small mb-1" for="scheduleAddDate">Project Date</label>
                                <input type="text" class="form-control" id="scheduleAddDate"
                                    data-add-date readonly disabled>
                                <div class="form-text">Set by the date you clicked.</div>
                            </div>

                            <div class="col-md-4" data-add-partial-day hidden>
                                <label class="form-label small mb-1" for="scheduleAddStartTime">Start Time</label>
                                <select class="form-select" id="scheduleAddStartTime" data-add-start-time>
                                    <option value="">Select start time</option>
                                    @foreach ($workingHours as $hour)
                                        <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-4" data-add-partial-day hidden>
                                <label class="form-label small mb-1" for="scheduleAddEndTime">End Time</label>
                                <select class="form-select" id="scheduleAddEndTime" data-add-end-time>
                                    <option value="">Select end time</option>
                                    @foreach ($workingHours as $hour)
                                        <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="schedule-eligible-wrap d-none" data-eligible-wrap>
                            <div class="schedule-section-heading mt-4">
                                <span><i class="bi bi-list-check me-1" aria-hidden="true"></i> Available
                                    projects</span>
                                <span class="schedule-count-pill d-none" data-eligible-count></span>
                            </div>

                            <div data-eligible-loading class="text-secondary small py-3 d-none">
                                <span class="spinner-border spinner-border-sm me-2" role="status"
                                    aria-hidden="true"></span>
                                Checking technician availability&hellip;
                            </div>

                            <div class="schedule-eligible-list" data-eligible-list></div>

                            <div class="schedule-empty-state d-none" data-eligible-empty>
                                No projects can take this date range. Every candidate either already has a booking
                                or has a technician who isn't free for the whole range.
                            </div>

                            <div class="schedule-blocked-wrap d-none" data-blocked-wrap>
                                <button type="button" class="schedule-blocked-toggle" data-blocked-toggle>
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                    <span data-blocked-label>Show unavailable projects</span>
                                </button>
                                <div class="schedule-blocked-list d-none" data-blocked-list></div>
                            </div>
                        </div>

                        <div class="alert alert-danger mt-3 d-none" role="alert" data-add-error></div>
                        <div class="alert alert-success mt-3 d-none" role="alert" data-add-success></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success d-none" data-add-save disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status"
                            aria-hidden="true" data-add-save-spinner></span>
                        Save Schedule
                    </button>
                </div>

            </div>
        </div>
    </div>

    {{-- Row templates used by JS when adding a schedule. Two of them, because
         whether the mode selector belongs in a row depends on the project, and
         a single page-level template is shared by every modal. --}}
    <template data-range-template>
        <x-schedule-range-row :working-hours="$workingHours" :partial-day-allowed="false" />
    </template>

    <template data-range-template-residential>
        <x-schedule-range-row :working-hours="$workingHours" :partial-day-allowed="true" />
    </template>

    @push('scripts')
        <script>
            window.scheduleCalendarEvents = @json($calendarEvents);
            window.scheduleTechnicianAvailability = @json($technicianSchedules);
            window.scheduleTechnicianNames = @json($technicianNames);
            {{-- Which job is holding a technician, so a clash names it rather
                 than leaving somebody to guess whether it is their own. --}}
            window.scheduleProjectLabels = @json($projectLabels);
        </script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script src="/js/calendarHeader.js"></script>
        <script src="/js/super-admin/schedule.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const params = new URLSearchParams(window.location.search);
                const openScheduleId = params.get('openSchedule');

                if (!openScheduleId) {
                    return;
                }

                // A completed project has the view-only panel instead.
                const modalEl = document.getElementById('scheduleEditModal' + openScheduleId)
                    || document.getElementById('scheduleViewModal' + openScheduleId);

                if (modalEl && window.bootstrap) {
                    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }
            });
        </script>
    @endpush
@endsection