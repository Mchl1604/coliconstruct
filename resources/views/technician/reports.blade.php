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
            <p class="text-secondary small mb-0">Every report you have filed.</p>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary">
                {{ $reports->count() }} {{ \Illuminate\Support\Str::plural('report', $reports->count()) }}
            </span>

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

                            <tr>
                                <td>#{{ $report->id }}</td>
                                <td>{{ $report->project?->reference_no ?? '—' }}</td>
                                <td class="fw-semibold">
                                    {{ $reportClient?->company_name ?: ($reportClient?->fullname ?: '—') }}
                                    <div class="small text-muted fw-normal">{{ $report->report_title }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $report->typeBadgeClass() }}">{{ $report->typeLabel() }}</span>
                                </td>
                                <td>{{ $report->submitterName() }}</td>
                                <td data-order="{{ $report->report_date?->timestamp ?? 0 }}">
                                    {{ $report->report_date?->format('M j, Y') ?? '—' }}
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                        data-view-report="{{ $report->id }}" title="View report">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
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

    @include('technician.partials.report-form-modal', [
        'projects' => $reportableProjects,
        'reportTypes' => $reportTypes,
    ])

    @push('scripts')
        <script>
            window.portalReports = @json($reportPayloads);

            window.portalRoutes = {
                storeReport: @json(route('technician.reports.store', ['project' => '__ID__'])),
            };
        </script>
        <script src="/js/technician/modals.js"></script>
        <script src="/js/technician/reports.js"></script>
    @endpush
@endsection
