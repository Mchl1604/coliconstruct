@extends('layouts.portalNav')

@section('title', 'Reports')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    {{-- The report viewer is the Super Admin one, so it wears its styles. --}}
    <link href="/css/super-admin/reports.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">Reports</h4>
            <p class="text-secondary small mb-0">
                Reports you submitted. To read every report on a project, whoever filed it, open the project.
            </p>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary">
                {{ $reports->count() }} {{ \Illuminate\Support\Str::plural('report', $reports->count()) }}
            </span>

            {{-- The other half of the list: archiving a report moves it there
                 rather than deleting it. --}}
            <a href="{{ route('technician.reports.archived') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-archive me-1" aria-hidden="true"></i>
                View Archived Reports
            </a>

            <button type="button" class="btn btn-sm btn-primary" data-submit-report
                @disabled($reportableProjects->isEmpty())>
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                Submit Report
            </button>
        </div>
    </div>

    @if ($reportableProjects->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            None of your projects can receive a report right now. Completed, cancelled and archived
            projects are closed records.
        </div>
    @endif

    {{-- Filters, matching the Super Admin Reports page: the same narrowings,
         applied to the rows already on the page rather than over the wire,
         because a lead's own reports are a short list. --}}
    <div class="card shadow-sm border-0 rounded-2 mb-3">
        <div class="card-body p-3">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1" for="reportProjectFilter">Project</label>
                    <select id="reportProjectFilter" class="form-select form-select-sm" data-filter-project>
                        <option value="all">All Projects</option>
                        @foreach ($filterProjects as $filterProject)
                            <option value="{{ $filterProject->project_id }}">
                                {{ $filterProject->reference_no }} &mdash; {{ $filterProject->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1" for="reportTypeFilter">Report Type</label>
                    <select id="reportTypeFilter" class="form-select form-select-sm" data-filter-type>
                        <option value="all">All Report Types</option>
                        @foreach ($reportTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1" for="reportDateFilter">Date</label>
                    <select id="reportDateFilter" class="form-select form-select-sm" data-filter-date>
                        <option value="all">All Dates</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1" for="reportSearch">Search</label>
                    <input type="search" id="reportSearch" class="form-control form-control-sm"
                        placeholder="Project, title, technician&hellip;" data-filter-search>
                </div>

                {{-- Only meaningful once Custom Date Range is chosen. --}}
                <div class="col-md-3 d-none" data-custom-range>
                    <label class="form-label small fw-semibold mb-1" for="reportStartDate">From</label>
                    <input type="date" id="reportStartDate" class="form-control form-control-sm" data-filter-start>
                </div>

                <div class="col-md-3 d-none" data-custom-range>
                    <label class="form-label small fw-semibold mb-1" for="reportEndDate">To</label>
                    <input type="date" id="reportEndDate" class="form-control form-control-sm" data-filter-end>
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-filter-reset>
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                        Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table id="portalReportsTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Report ID</th>
                            <th>Reference No.</th>
                            <th>Client</th>
                            <th>Report Type</th>
                            <th>Submitted By</th>
                            <th>Date Submitted</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>

                    <tbody data-reports-body>
                        {{-- No blade fallback row here: a single colspan cell has fewer
                             cells than the header, which DataTables cannot parse. Its
                             own emptyTable message covers it. --}}
                        @foreach ($reports as $report)
                            @php
                                $reportClient = $report->project?->clients->first();
                            @endphp

                            {{-- Everything the filter bar matches on travels
                                 with the row, so narrowing costs no request. --}}
                            <tr data-report-date="{{ $report->report_date?->toDateString() }}"
                                data-project-id="{{ $report->project_id }}"
                                data-report-type="{{ $report->report_type }}">
                                <td>{{ $report->displayCode() }}</td>
                                <td>{{ $report->project?->reference_no ?? '—' }}</td>
                                <td class="fw-semibold">
                                    {{ $reportClient?->company_name ?: ($reportClient?->fullname ?: '—') }}
                                    <div class="small text-muted fw-normal">{{ $report->report_title }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $report->typeBadgeClass() }}">{{ $report->typeLabel() }}</span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        @if ($report->submitterAvatarUrl())
                                            <img class="user-avatar user-avatar-xs"
                                                src="{{ $report->submitterAvatarUrl() }}" alt="" loading="lazy">
                                        @endif
                                        <span>{{ $report->submitterName() }}</span>
                                    </div>
                                </td>
                                <td data-order="{{ $report->report_date?->timestamp ?? 0 }}">
                                    {{ $report->report_date?->format('M j, Y') ?? '—' }}
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                            data-view-report="{{ $report->id }}" title="View report">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>

                                        {{-- Only the reports this lead filed
                                             themselves, decided by the same policy
                                             the endpoint enforces. --}}
                                        @can('archive', $report)
                                            <button type="button" class="btn btn-sm btn-warning py-1 px-2"
                                                data-archive-report="{{ $report->id }}"
                                                data-report-label="{{ $report->displayCode() }} - {{ $report->report_title }}"
                                                title="Archive report">
                                                <i class="bi bi-archive" aria-hidden="true"></i>
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================ VIEW REPORT MODAL ============================ --}}
    <div class="modal fade" id="viewReportModal" tabindex="-1" aria-hidden="true" data-view-report-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                {{-- The Super Admin report viewer, cell for cell, so a report
                     reads the same whoever opens it. --}}
                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow" data-report-view-type-eyebrow>Report</span>
                        <h5 class="modal-title mb-1" data-report-view-title>&nbsp;</h5>
                        <span class="schedule-modal-ref" data-report-view-project></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="schedule-section-heading">
                        <span><i class="bi bi-info-circle me-1" aria-hidden="true"></i> Report Information</span>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="technician-field-label">Client</div>
                            <div class="technician-field-value" data-report-view-client></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Report Type</div>
                            <div data-report-view-type></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Submitted By</div>
                            <div class="technician-field-value" data-report-view-submitted-by></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Date Submitted</div>
                            <div class="technician-field-value" data-report-view-date></div>
                        </div>
                    </div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-card-text me-1" aria-hidden="true"></i> Report Details</span>
                    </div>

                    <div class="report-description mb-4" data-report-view-description></div>

                    <div class="schedule-section-heading d-none" data-report-view-images-heading>
                        <span><i class="bi bi-images me-1" aria-hidden="true"></i> Report Images</span>
                    </div>

                    <div class="row g-3" data-report-view-images></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================ ARCHIVE REPORT ============================ --}}
    <div class="modal fade" id="archiveReportModal" tabindex="-1" aria-hidden="true" data-archive-report-modal>
        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Archive Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    Archive <strong data-archive-report-label></strong>?

                    <p class="text-secondary small mb-0 mt-2">
                        It comes off this list and off its project's report list. The report, its images and
                        its attachments are kept, and it can be restored from View Archived Reports.
                    </p>

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-archive-report-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" data-archive-report-confirm>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-spinner></span>
                        <i class="bi bi-archive me-1" aria-hidden="true"></i>
                        Archive Report
                    </button>
                </div>

            </div>
        </div>
    </div>

    @include('technician.partials.report-form-modal', [
        'projects' => $reportableProjects,
        'reportTypes' => $reportTypes,
    ])

    @push('scripts')
        <script>
            window.portalReports = @json($reportPayloads);

            window.portalRoutes = {
                storeReport: @json(route('technician.reports.store', ['project' => '__ID__'])),
                archiveReport: @json(route('technician-reports.archive', ['report' => '__ID__'])),
            };
        </script>
        <script src="/js/technician/modals.js"></script>
        <script src="/js/technician/reports.js"></script>
    @endpush
@endsection
