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
                Archived technician reports are preserved in full - images and attachments included - and
                removed from the active reports list.
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

                    <tbody>
                        {{-- No blade fallback row here: a single colspan cell has
                             fewer cells than the header, which DataTables cannot
                             parse. Its own emptyTable message covers it. --}}
                        @foreach ($reports as $report)
                            @php
                                $reportClient = $report->project?->clients->first();
                            @endphp

                            <tr>
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
                                <td data-order="{{ $report->archived_at?->timestamp ?? 0 }}">
                                    {{ $report->archived_at?->format('M j, Y') ?? '—' }}
                                </td>
                                <td>{{ $report->archiver?->fullName() ?? '—' }}</td>
                                <td class="text-center">
                                    <div class="d-inline-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                                            data-view-report="{{ $report->id }}" title="View report">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>

                                        {{-- Drawn only for whoever the policy says may
                                             put this one back. The endpoint asks the
                                             same question again, so a missing button
                                             and a refused request mean the same thing. --}}
                                        @can('restore', $report)
                                            <button type="button" class="btn btn-sm btn-success py-1 px-2"
                                                data-bs-toggle="modal"
                                                data-bs-target="#restoreReportModal{{ $report->id }}"
                                                title="Restore report">
                                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>

                            @can('restore', $report)
                                <!-- RESTORE REPORT MODAL -->
                                <div class="modal fade" id="restoreReportModal{{ $report->id }}" tabindex="-1"
                                    aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">

                                            <div class="modal-header">
                                                <h5 class="modal-title">Restore Report</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                    aria-label="Close"></button>
                                            </div>

                                            <div class="modal-body">
                                                Restore <strong>{{ $report->displayCode() }} &mdash;
                                                    {{ $report->report_title }}</strong>?

                                                <p class="text-secondary small mb-0 mt-2">
                                                    It returns to the active reports list and to its project's
                                                    report list, with the same submitter, images and attachments
                                                    it was archived with.
                                                </p>
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary"
                                                    data-bs-dismiss="modal">Cancel</button>

                                                <form method="POST"
                                                    action="{{ route('technician-reports.restore', $report->id) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="btn btn-success">
                                                        <i class="bi bi-arrow-counterclockwise me-1"
                                                            aria-hidden="true"></i>
                                                        Restore Report
                                                    </button>
                                                </form>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            @endcan
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
            // Every archived report in full, so the viewer reads from the page
            // rather than asking again for a report it was already handed.
            window.archivedReports = @json($reportPayloads);
        </script>
        <script src="/js/super-admin/archivedReports.js"></script>
    @endpush
@endsection
