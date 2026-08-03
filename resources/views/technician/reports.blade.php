@extends('layouts.portalNav')

@section('title', 'Reports')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
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
                <table id="leadReportsTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Report ID</th>
                            <th>Project</th>
                            <th>Report Title</th>
                            <th>Date Submitted</th>
                            <th>Type</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>

                    <tbody data-reports-body>
                        {{-- No blade fallback row here: a single colspan cell has fewer
                             cells than the header, which DataTables cannot parse. Its
                             own emptyTable message covers it. --}}
                        @foreach ($reports as $report)
                            <tr>
                                <td>#{{ $report->id }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $report->project?->name ?? 'Project removed' }}</div>
                                    <small class="text-muted">{{ $report->project?->reference_no ?? '—' }}</small>
                                </td>
                                <td>{{ $report->report_title }}</td>
                                <td data-order="{{ $report->report_date?->timestamp ?? 0 }}">
                                    {{ $report->report_date?->format('M j, Y') ?? '—' }}
                                </td>
                                <td>
                                    <span class="badge {{ $report->typeBadgeClass() }}">{{ $report->typeLabel() }}</span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                            data-view-report="{{ $report->id }}" title="View report">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>

                                        @if ($report->images->isNotEmpty())
                                            <a class="btn btn-sm btn-outline-secondary py-1 px-2"
                                                href="{{ asset('storage/' . $report->images->first()->image_path) }}"
                                                download title="Download attachment">
                                                <i class="bi bi-download" aria-hidden="true"></i>
                                            </a>
                                        @endif
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

                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow" data-report-view-project></span>
                        <h5 class="modal-title mb-1" data-report-view-title>&nbsp;</h5>
                        <span class="technician-meta" data-report-view-meta></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3" data-report-view-description></p>

                    <div class="schedule-section-heading d-none" data-report-view-images-heading>
                        <span><i class="bi bi-images me-1" aria-hidden="true"></i> Attachments</span>
                    </div>

                    <div class="row g-3" data-report-view-images></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    @include('technician.lead.partials.report-form-modal', [
        'projects' => $reportableProjects,
        'reportTypes' => $reportTypes,
    ])

    @push('scripts')
        <script>
            window.leadReports = @json($reportPayloads);

            window.leadRoutes = {
                storeReport: @json(route('technician.lead.reports.store', ['project' => '__ID__'])),
            };
        </script>
        <script src="/js/technician/leadModals.js"></script>
        <script src="/js/technician/leadReports.js"></script>
    @endpush
@endsection
