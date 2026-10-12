@extends('layouts.portalNav')

@section('title', 'Archived Reports')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/schedule.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    {{-- The viewer is the Reports page viewer, so it wears its styles. --}}
    <link href="/css/super-admin/reports.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">Archived Reports</h4>
            <p class="text-secondary small mb-0">
                Archived reports. Nothing was deleted.
            </p>
        </div>

        <a href="{{ route('technician.reports') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Back to Reports
        </a>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table id="portalArchivedReportsTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Report ID</th>
                            <th>Reference No.</th>
                            <th>Client</th>
                            <th>Report Type</th>
                            <th>Date Submitted</th>
                            <th>Archived Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    {{-- Filled a page at a time by serverTable.js from
                         TechnicianPortalController::archivedReportsTable(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- The Restore dialogs for the rows on screen, swapped in with every
         page. Outside the table: a <div> is not valid inside a <tbody>. --}}
    <div data-archived-report-dialogs></div>

    {{-- ============================ VIEW REPORT MODAL ============================ --}}
    {{-- The Reports page viewer, cell for cell, plus the banner saying what this
         copy of it is showing. --}}
    <div class="modal fade" id="viewReportModal" tabindex="-1" aria-hidden="true" data-view-report-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header align-items-start">
                    <div class="schedule-modal-heading">
                        <span class="schedule-modal-eyebrow" data-report-view-type-eyebrow>Report</span>
                        <h5 class="modal-title mb-1" data-report-view-title>&nbsp;</h5>
                        <span class="schedule-modal-ref" data-report-view-project></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-secondary d-flex align-items-center gap-2" role="status">
                        <i class="bi bi-archive" aria-hidden="true"></i>
                        <span>
                            This report is archived. Archived on <strong data-report-view-archived-at></strong>.
                        </span>
                    </div>

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

    @push('scripts')
        <script>
            window.portalRoutes = Object.assign(window.portalRoutes || {}, {
                archivedReportsTable: @json(route('technician.reports.archived.table')),
            });
        </script>
        <script src="/js/technician/archivedReports.js"></script>
    @endpush
@endsection
