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
                <span class="schedule-legend-item"><i class="schedule-dot" style="background:#198754"></i> Completed</span>
                <span class="schedule-legend-item"><i class="schedule-dot" style="background:#dc3545"></i> Cancelled</span>
                <span class="schedule-legend-item"><i class="schedule-dot" style="background:#6c757d"></i> On Hold</span>
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
                                    @if ($project->on_hold)
                                        <span class="badge bg-secondary">On Hold</span>
                                    @elseif ($project->status === 'pending')
                                        <span class="badge bg-warning">Pending</span>
                                    @elseif ($project->status === 'ongoing')
                                        <span class="badge bg-primary">Ongoing</span>
                                    @elseif ($project->status === 'completed')
                                        <span class="badge bg-success">Completed</span>
                                    @elseif ($project->status === 'cancelled')
                                        <span class="badge bg-danger">Cancelled</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                        data-bs-toggle="modal" data-bs-target="#scheduleEditModal{{ $project->project_id }}">
                                        <i class="bi bi-calendar2-week"></i>
                                        Edit Schedule
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Per-project schedule edit modals --}}
    @foreach ($projects as $project)
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

                        <div class="modal-header">
                            <h5 class="modal-title" id="scheduleEditModalLabel{{ $project->project_id }}">
                                Edit Schedule &mdash; {{ $project->reference_no }}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <p class="text-secondary small mb-3">
                                {{ $project->name }} &middot;
                                Assigned: {{ $project->projectTechnicians->pluck('technician.name')->filter()->join(', ') ?: 'No technicians assigned' }}
                            </p>

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
        </script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script src="/js/super-admin/schedule.js"></script>
    @endpush
@endsection