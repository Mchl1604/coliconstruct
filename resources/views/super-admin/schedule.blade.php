@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Schedules</h4>
            <p class="text-secondary small mb-0">View every project's schedule on the calendar, and click a project
                to edit its date ranges.</p>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2 mb-3">
        <div class="card-body p-3">
            <div class="schedule-legend mb-3">
                <span class="schedule-legend-item"><i class="schedule-dot" style="background:#f0ad4e"></i> Pending</span>
                <span class="schedule-legend-item"><i class="schedule-dot" style="background:#0d6efd"></i> Ongoing</span>
                <span class="schedule-legend-item"><i class="schedule-dot" style="background:#fd7e14"></i> Overdue</span>
                <span class="schedule-legend-item"><i class="schedule-dot" style="background:#198754"></i> Completed</span>
            </div>

            <div id="schedulesCalendar"></div>
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

                    <tbody>
                        @foreach ($projects as $project)
                            <tr>
                                <td>{{ $project->project_id }}</td>
                                <td>{{ $project->reference_no }}</td>
                                <td>{{ $project->name }}</td>
                                <td>
                                    {{ $project->projectTechnicians->pluck('technician.name')->filter()->join(', ') ?: 'Unassigned' }}
                                </td>
                                <td>
                                    @forelse ($project->schedules as $schedule)
                                        <div class="small">
                                            {{ \Carbon\Carbon::parse($schedule->start_datetime)->format('M d, Y') }}
                                            &ndash;
                                            {{ \Carbon\Carbon::parse($schedule->end_datetime)->format('M d, Y') }}
                                        </div>
                                    @empty
                                        <span class="text-muted small">No date range set</span>
                                    @endforelse
                                </td>
                                <td>
                                    <x-project-status-badge :project="$project" />
                                </td>
                                <td class="text-center">
                                    @if (in_array($project->status, ['completed', 'cancelled', 'archived'], true))
                                        <span class="text-muted small">View only</span>
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

    {{-- Per-project schedule edit modals. Completed, cancelled and archived
         projects are view-only, so no edit modal is rendered for them at all
         and the calendar has nothing to open. --}}
    @foreach ($projects->reject(fn ($project) => $project->isReadOnly()) as $project)
        <div class="modal fade" id="scheduleEditModal{{ $project->project_id }}" tabindex="-1"
            aria-labelledby="scheduleEditModalLabel{{ $project->project_id }}" aria-hidden="true"
            data-schedule-edit-modal
            data-project-id="{{ $project->project_id }}"
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
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <div class="schedule-modal-team mb-3">
                                <span class="schedule-modal-team-label">
                                    <i class="bi bi-people-fill me-1" aria-hidden="true"></i>
                                    Assigned Technicians
                                </span>

                                <div class="schedule-modal-team-chips">
                                    @forelse ($project->projectTechnicians->pluck('technician.name')->filter() as $technicianName)
                                        <span class="schedule-tech-chip">{{ $technicianName }}</span>
                                    @empty
                                        <span class="text-muted small">No technicians assigned</span>
                                    @endforelse
                                </div>
                            </div>

                            <p class="text-muted small mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                At least one date range is required. New ranges can't overlap with a date already
                                booked by this project's technicians on another project.
                            </p>

                            <div data-ranges-container data-next-index="{{ max($project->schedules->count(), 1) }}">
                                @forelse ($project->schedules as $index => $schedule)
                                    <div class="schedule-range-row row g-2 align-items-end mb-2" data-range-row>
                                        <input type="hidden" name="ranges[{{ $index }}][schedule_id]"
                                            value="{{ $schedule->schedule_id }}">

                                        <div class="col-5">
                                            <label class="form-label small mb-1">Start Date</label>
                                            <input type="date" class="form-control form-control-sm"
                                                name="ranges[{{ $index }}][start_date]"
                                                value="{{ \Carbon\Carbon::parse($schedule->start_datetime)->format('Y-m-d') }}"
                                                data-range-start required>
                                        </div>

                                        <div class="col-5">
                                            <label class="form-label small mb-1">End Date</label>
                                            <input type="date" class="form-control form-control-sm"
                                                name="ranges[{{ $index }}][end_date]"
                                                value="{{ \Carbon\Carbon::parse($schedule->end_datetime)->format('Y-m-d') }}"
                                                data-range-end required>
                                        </div>

                                        <div class="col-2">
                                            <button type="button" class="btn btn-sm btn-outline-danger w-100"
                                                data-remove-range title="Remove date range">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                @empty
                                    <div class="schedule-range-row row g-2 align-items-end mb-2" data-range-row>
                                        <div class="col-5">
                                            <label class="form-label small mb-1">Start Date</label>
                                            <input type="date" class="form-control form-control-sm"
                                                name="ranges[0][start_date]" data-range-start required>
                                        </div>

                                        <div class="col-5">
                                            <label class="form-label small mb-1">End Date</label>
                                            <input type="date" class="form-control form-control-sm"
                                                name="ranges[0][end_date]" data-range-end required>
                                        </div>

                                        <div class="col-2">
                                            <button type="button" class="btn btn-sm btn-outline-danger w-100"
                                                data-remove-range title="Remove date range" disabled>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                @endforelse
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" data-add-range>
                                <i class="bi bi-plus-lg me-1"></i>
                                Add Date Range
                            </button>

                            <div class="schedule-range-error text-danger small mt-2 d-none" data-range-error></div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" data-schedule-submit>
                                Save Schedule
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>

    @endforeach

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
                    </div>

                    <hr class="my-4">

                    {{-- Step 1: reveal the add-project flow --}}
                    <button type="button" class="btn btn-primary" data-add-project-toggle>
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                        Add Project
                    </button>

                    {{-- Step 2 onward --}}
                    <div class="schedule-add-panel d-none" data-add-project-panel>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small mb-1" for="scheduleAddStartDate">Start Date</label>
                                <input type="text" class="form-control" id="scheduleAddStartDate"
                                    data-add-start readonly disabled>
                                <div class="form-text">Set by the date you clicked.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small mb-1" for="scheduleAddEndDate">End Date</label>
                                <input type="text" class="form-control" id="scheduleAddEndDate"
                                    data-add-end placeholder="Select end date" required>
                                <div class="form-text">Pick this to see which projects are free.</div>
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

    {{-- Range row template used by JS when adding a new date range (shared markup) --}}
    <template data-range-template>
        <div class="schedule-range-row row g-2 align-items-end mb-2" data-range-row>
            <div class="col-5">
                <label class="form-label small mb-1">Start Date</label>
                <input type="date" class="form-control form-control-sm" data-range-start required>
            </div>

            <div class="col-5">
                <label class="form-label small mb-1">End Date</label>
                <input type="date" class="form-control form-control-sm" data-range-end required>
            </div>

            <div class="col-2">
                <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-range
                    title="Remove date range">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </template>

    @push('scripts')
        <script>
            window.scheduleCalendarEvents = @json($calendarEvents);
            window.scheduleTechnicianAvailability = @json($technicianSchedules);
            window.scheduleTechnicianNames = @json($technicianNames);
        </script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script src="/js/super-admin/schedule.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const params = new URLSearchParams(window.location.search);
                const openScheduleId = params.get('openSchedule');

                if (!openScheduleId) {
                    return;
                }

                const modalEl = document.getElementById('scheduleEditModal' + openScheduleId);

                if (modalEl && window.bootstrap) {
                    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }
            });
        </script>
    @endpush
@endsection