@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    {{-- The viewer here is the Reports page viewer, so it wears its styles. --}}
    <link href="/css/super-admin/reports.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Archived Reports</h4>
            <p class="text-secondary small mb-0">
                Kept in full, hidden from active reports.
            </p>
        </div>

        <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="window.location='{{ route('super-admin.reports.index') }}'">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Back to Reports
        </button>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table id="archivedReportsTable" class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Report ID</th>
                            <th>Reference No.</th>
                            <th>Client</th>
                            <th>Report Type</th>
                            <th>Submitted By</th>
                            <th>Date Submitted</th>
                            <th>Archived Date</th>
                            <th>Archived By</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>

                    {{-- Filled a page at a time by serverTable.js from
                         ReportController::archivedTable(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- The Restore dialogs for the rows on screen, swapped in with every
         page. Outside the table: a <div> is not valid inside a <tbody>. --}}
    <div data-archived-report-dialogs></div>

    {{-- ==================== VIEW REPORT MODAL ==================== --}}
    {{-- The Reports page viewer, cell for cell, plus the banner that says what
         this copy of it is showing: an archived report is read in full, not in
         summary. --}}
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-secondary d-flex align-items-center gap-2" role="status">
                        <i class="bi bi-archive" aria-hidden="true"></i>
                        <span>
                            This report is archived. Archived on <strong data-view-archived-at></strong>
                            by <strong data-view-archived-by></strong>.
                        </span>
                    </div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-info-circle me-1" aria-hidden="true"></i> Report Information</span>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="technician-field-label">Client</div>
                            <div class="technician-field-value" data-view-client></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Report Type</div>
                            <div data-view-type></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Submitted By</div>
                            <div class="technician-field-value" data-view-submitted-by></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="technician-field-label">Date Submitted</div>
                            <div class="technician-field-value" data-view-date></div>
                        </div>
                    </div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-card-text me-1" aria-hidden="true"></i> Report Details</span>
                    </div>

                    <div class="report-description mb-4" data-view-description></div>

                    <div class="schedule-section-heading">
                        <span><i class="bi bi-images me-1" aria-hidden="true"></i> Report Images</span>
                        <span class="schedule-count-pill d-none" data-view-image-count></span>
                    </div>

                    <div class="report-gallery" data-view-gallery></div>

                    <div class="schedule-empty-state d-none" data-view-no-images>
                        No images attached.
                    </div>
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
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-0">
                    <img src="" alt="Report image" class="img-fluid rounded" data-image-target>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            window.archivedReportsTable = @json(route('super-admin.reports.archived.table'));
        </script>
        <script src="/js/super-admin/archivedReports.js"></script>
    @endpush
@endsection
