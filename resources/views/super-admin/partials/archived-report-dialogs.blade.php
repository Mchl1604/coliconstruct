{{-- The Restore dialogs for the rows on one page of the archive, rendered by
     ReportController::archivedTable() beside the rows. Swapped in whole with
     every page, so only the rows on screen have one. --}}
@foreach ($reports as $report)
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
                            It returns to the active reports.
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
