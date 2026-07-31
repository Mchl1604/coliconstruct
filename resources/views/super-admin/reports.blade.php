@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    <link href="/css/super-admin/reports.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Reports</h4>
            <p class="text-secondary small mb-0">Every technician report in one place, plus system-wide analytics.</p>
        </div>
    </div>

    <ul class="nav nav-tabs technician-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="technicianReportsTab" data-bs-toggle="tab"
                data-bs-target="#technicianReportsPane" type="button" role="tab"
                aria-controls="technicianReportsPane" aria-selected="true">
                <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>
                Technician Reports
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="systemReportsTab" data-bs-toggle="tab"
                data-bs-target="#systemReportsPane" type="button" role="tab" aria-controls="systemReportsPane"
                aria-selected="false">
                <i class="bi bi-graph-up-arrow me-1" aria-hidden="true"></i>
                System Reports
            </button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ==================== TAB 1: TECHNICIAN REPORTS ==================== --}}
        <div class="tab-pane fade show active" id="technicianReportsPane" role="tabpanel"
            aria-labelledby="technicianReportsTab">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0">Technician Reports</h5>
                    <span class="text-secondary small" data-reports-count></span>
                </div>

                <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                    data-bs-target="#createReportModal">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                    Create Report
                </button>
            </div>

            {{-- Filters --}}
            <div class="card shadow-sm border-0 rounded-2 mb-3">
                <div class="card-body p-3">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1" for="reportProjectFilter">Project</label>
                            <select id="reportProjectFilter" class="form-select form-select-sm"
                                data-filter-project>
                                <option value="">All Projects</option>
                                @foreach ($filterProjects as $project)
                                    <option value="{{ $project->project_id }}">{{ $project->reference_no }} &mdash; {{ $project->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1" for="reportTypeFilter">Report
                                Type</label>
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

                        {{-- Only shown for a custom range --}}
                        <div class="col-md-3 d-none" data-custom-range>
                            <label class="form-label small fw-semibold mb-1" for="reportStartDate">From</label>
                            <input type="text" id="reportStartDate" class="form-control form-control-sm"
                                placeholder="Start date" data-filter-start>
                        </div>

                        <div class="col-md-3 d-none" data-custom-range>
                            <label class="form-label small fw-semibold mb-1" for="reportEndDate">To</label>
                            <input type="text" id="reportEndDate" class="form-control form-control-sm"
                                placeholder="End date" data-filter-end>
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

            {{-- Reports table --}}
            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0" id="reportsTable">
                            <thead class="table-info">
                                <tr>
                                    <th>Report ID</th>
                                    <th>Project No.</th>
                                    <th>Project Name</th>
                                    <th>Report Type</th>
                                    <th>Submitted By</th>
                                    <th>Date Submitted</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody data-reports-body></tbody>
                        </table>
                    </div>

                    <div class="text-secondary small py-3 px-2" data-reports-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Loading reports&hellip;
                    </div>

                    <div class="schedule-empty-state m-2 d-none" data-reports-empty>
                        No technician reports match these filters.
                    </div>
                </div>
            </div>
        </div>

        {{-- ==================== TAB 2: SYSTEM REPORTS ==================== --}}
        <div class="tab-pane fade" id="systemReportsPane" role="tabpanel" aria-labelledby="systemReportsTab">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0">System Reports</h5>
                    <span class="text-secondary small" data-period-label></span>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="btn-group" role="group" aria-label="Reporting period">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-sm btn-outline-primary active"
                            data-period="monthly">Monthly</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-period="yearly">Yearly</button>
                    </div>

                    <button type="button" class="btn btn-success" data-bs-toggle="modal"
                        data-bs-target="#exportReportModal">
                        <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>
                        Export Report
                    </button>
                </div>
            </div>

            <div class="text-secondary small py-3 d-none" data-system-loading>
                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                Calculating&hellip;
            </div>

            <div class="alert alert-danger d-none" role="alert" data-system-error></div>

            {{-- Summary cards --}}
            <div class="row g-3 mb-4" data-summary-cards>
                @foreach ([
        ['key' => 'projects', 'title' => 'Projects', 'icon' => 'bi-folder2-open'],
        ['key' => 'quotations', 'title' => 'Quotations', 'icon' => 'bi-cash-coin'],
        ['key' => 'technicians', 'title' => 'Technicians', 'icon' => 'bi-tools'],
        ['key' => 'schedules', 'title' => 'Schedules', 'icon' => 'bi-calendar-event'],
        ['key' => 'tasks', 'title' => 'Tasks', 'icon' => 'bi-list-task'],
    ] as $card)
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="card shadow-sm border-0 rounded-2 h-100">
                            <div class="card-body p-3">
                                <div class="report-card-heading">
                                    <i class="bi {{ $card['icon'] }}" aria-hidden="true"></i>
                                    {{ $card['title'] }}
                                </div>
                                <dl class="report-stat-list mb-0" data-summary="{{ $card['key'] }}"></dl>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Charts --}}
            @foreach ([
        ['title' => 'Projects', 'charts' => [
            ['id' => 'projectsOverTime', 'title' => 'Projects Created Over Time', 'type' => 'line', 'col' => 'col-12 col-xl-8'],
            ['id' => 'projectsByStatus', 'title' => 'Projects by Status', 'type' => 'doughnut', 'col' => 'col-12 col-xl-4'],
            ['id' => 'projectCompletionTrend', 'title' => 'Project Completion Trend', 'type' => 'bar', 'col' => 'col-12'],
        ]],
        ['title' => 'Quotations', 'charts' => [
            ['id' => 'quotationValueOverTime', 'title' => 'Approved Quotation Value Over Time', 'type' => 'line', 'col' => 'col-12 col-xl-7', 'money' => true],
            ['id' => 'quotationCountOverTime', 'title' => 'Approved Quotations Created', 'type' => 'bar', 'col' => 'col-12 col-xl-5'],
            ['id' => 'topClientsByValue', 'title' => 'Top Clients by Total Quotation Value', 'type' => 'horizontalBar', 'col' => 'col-12', 'money' => true],
        ]],
        ['title' => 'Technicians', 'charts' => [
            ['id' => 'projectsPerTechnician', 'title' => 'Active Projects per Technician', 'type' => 'bar', 'col' => 'col-12 col-xl-7'],
            ['id' => 'technicianUtilization', 'title' => 'Technician Utilization', 'type' => 'doughnut', 'col' => 'col-12 col-xl-5'],
            ['id' => 'specialtyDistribution', 'title' => 'Specialty Distribution', 'type' => 'bar', 'col' => 'col-12'],
        ]],
        ['title' => 'Schedules', 'charts' => [
            ['id' => 'schedulesOverTime', 'title' => 'Schedules Starting Over Time', 'type' => 'bar', 'col' => 'col-12 col-xl-7'],
            ['id' => 'scheduleHealth', 'title' => 'Schedule Health', 'type' => 'doughnut', 'col' => 'col-12 col-xl-5'],
        ]],
        ['title' => 'Tasks', 'charts' => [
            ['id' => 'taskCompletionTrend', 'title' => 'Task Completion Trend', 'type' => 'line', 'col' => 'col-12 col-xl-7'],
            ['id' => 'tasksByStatus', 'title' => 'Tasks by Status', 'type' => 'doughnut', 'col' => 'col-12 col-xl-5'],
            ['id' => 'tasksByProject', 'title' => 'Task Distribution by Project', 'type' => 'horizontalBar', 'col' => 'col-12'],
        ]],
    ] as $section)
                <div class="report-section-title">{{ $section['title'] }}</div>

                <div class="row g-3 mb-4">
                    @foreach ($section['charts'] as $chart)
                        <div class="{{ $chart['col'] }}">
                            <div class="card shadow-sm border-0 rounded-2 h-100">
                                <div class="card-body p-3">
                                    <h6 class="report-chart-title">{{ $chart['title'] }}</h6>
                                    <div class="report-chart-wrap">
                                        <canvas data-chart="{{ $chart['id'] }}"
                                            data-chart-type="{{ $chart['type'] }}"
                                            data-chart-title="{{ $chart['title'] }}"
                                            @if (! empty($chart['money'])) data-chart-money="1" @endif></canvas>
                                    </div>
                                    <div class="schedule-empty-state mt-2 d-none"
                                        data-chart-empty="{{ $chart['id'] }}">
                                        No data for this period.
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    {{-- ==================== CREATE REPORT MODAL ==================== --}}
    <div class="modal fade" id="createReportModal" tabindex="-1" aria-hidden="true" data-create-report-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            {{-- Posts to the same endpoint the Project Details form uses; the
                 action is filled in once a project is chosen. --}}
            <form method="POST" enctype="multipart/form-data" data-create-report-form action="">
                @csrf

                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-file-earmark-plus me-2" aria-hidden="true"></i>
                            Create Technician Report
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        {{-- Step 1 --}}
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="createReportProject">
                                Project
                            </label>
                            <select id="createReportProject" class="form-select" data-create-project required>
                                <option value="" selected disabled>Select a project&hellip;</option>
                                @foreach ($reportableProjects as $project)
                                    <option value="{{ $project->project_id }}">{{ $project->reference_no }} &mdash; {{ $project->name }} ({{ $project->statusLabel() }})</option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Completed, cancelled and archived projects can no longer receive reports.
                            </div>
                            @if ($reportableProjects->isEmpty())
                                <div class="alert alert-warning mt-2 mb-0">
                                    There are no projects currently open for reporting.
                                </div>
                            @endif
                        </div>

                        {{-- Step 2: the existing report form, revealed after a project is chosen --}}
                        <div class="d-none" data-create-fields>
                            <hr class="mb-4">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Report Type</label>
                                <select class="form-select" name="report_type" required>
                                    <option value="">Select Report Type</option>
                                    @foreach ($reportTypes as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Report Title</label>
                                <input type="text" class="form-control" name="report_title"
                                    placeholder="Enter report title" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Report Description</label>
                                <textarea class="form-control" name="report_description" rows="5"
                                    placeholder="Describe the work completed or incident..." required></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Submitted By</label>
                                <select class="form-select" name="technician_id" data-create-technician>
                                    <option value="">Project lead technician</option>
                                </select>
                                <div class="form-text">
                                    Defaults to the project's lead technician.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Upload Images</label>
                                <input type="file" class="form-control" name="images[]" accept="image/*" multiple
                                    data-create-images>
                                <small class="text-muted">Optional. JPG, PNG or JPEG, up to 5 MB each.</small>
                            </div>

                            <div class="row g-2" data-create-preview></div>
                        </div>

                        <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-create-error></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" data-create-submit disabled>
                            <i class="bi bi-save me-1" aria-hidden="true"></i>
                            Submit Report
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ==================== REPORT VIEWER MODAL ==================== --}}
    <div class="modal fade" id="viewReportModal" tabindex="-1" aria-hidden="true" data-view-report-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow" data-view-type-eyebrow>Report</span>
                        <h5 class="modal-title mb-1" data-view-title>&nbsp;</h5>
                        <a href="#" class="schedule-modal-ref d-none" data-view-project-link target="_blank">
                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                            <span data-view-project-ref></span>
                        </a>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="schedule-section-heading">
                        <span><i class="bi bi-info-circle me-1" aria-hidden="true"></i> Report Information</span>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="technician-field-label">Project</div>
                            <div class="technician-field-value" data-view-project></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Client</div>
                            <div class="technician-field-value" data-view-client></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="technician-field-label">Report Type</div>
                            <div data-view-type></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="technician-field-label">Submitted By</div>
                            <div class="technician-field-value" data-view-submitted-by></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="technician-field-label">Date Submitted</div>
                            <div class="technician-field-value" data-view-date></div>
                        </div>
                    </div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-card-text me-1" aria-hidden="true"></i> Report Details</span>
                    </div>
                    {{-- Whitespace is preserved so the technician's line breaks survive. --}}
                    <div class="report-description mb-4" data-view-description></div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-images me-1" aria-hidden="true"></i> Report Images</span>
                        <span class="schedule-count-pill d-none" data-view-image-count></span>
                    </div>

                    <div class="report-gallery" data-view-gallery></div>

                    <div class="schedule-empty-state d-none" data-view-no-images>
                        No images attached.
                    </div>

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-view-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== IMAGE LIGHTBOX ==================== --}}
    <div class="modal fade" id="reportImageModal" tabindex="-1" aria-hidden="true" data-image-modal>
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0">
                    <img src="" alt="Report image" class="img-fluid rounded" data-image-target>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== EXPORT MODAL ==================== --}}
    <div class="modal fade" id="exportReportModal" tabindex="-1" aria-hidden="true" data-export-modal>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-file-earmark-arrow-down me-2" aria-hidden="true"></i>
                        Export Report
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="exportReportType">Report Type</label>
                        <select id="exportReportType" class="form-select" data-export-type>
                            @foreach ($exportTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="exportPeriod">Reporting Period</label>
                        <select id="exportPeriod" class="form-select" data-export-period>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="yearly">Yearly</option>
                            <option value="custom">Custom Date Range</option>
                        </select>
                    </div>

                    <div class="row g-2 d-none" data-export-custom>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1" for="exportStartDate">Start Date</label>
                            <input type="text" id="exportStartDate" class="form-control" placeholder="Start date"
                                data-export-start>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1" for="exportEndDate">End Date</label>
                            <input type="text" id="exportEndDate" class="form-control" placeholder="End date"
                                data-export-end>
                        </div>
                    </div>

                    <div class="mb-0 mt-3">
                        <label class="form-label fw-semibold" for="exportFormat">Format</label>
                        <select id="exportFormat" class="form-select" data-export-format>
                            <option value="pdf" selected>PDF</option>
                        </select>
                        <div class="form-text">Excel export can be added later without changing this dialog.</div>
                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="exportIncludeCharts" checked
                            data-export-include-charts>
                        <label class="form-check-label" for="exportIncludeCharts">
                            Include graphs
                        </label>
                    </div>

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-export-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" data-export-submit>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-export-spinner></span>
                        Generate PDF
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            window.reportRoutes = {
                technicianReports: @json(route('super-admin.reports.technician')),
                systemReports: @json(route('super-admin.reports.system')),
                export: @json(route('super-admin.reports.export')),
                reportBase: @json(url('super-admin/reports/technician-reports')),
                projectBase: @json(url('super-admin/projects')),
            };
            window.reportProjectTechnicians = @json($projectTechnicians);
        </script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="/js/super-admin/reports.js"></script>
    @endpush
@endsection
