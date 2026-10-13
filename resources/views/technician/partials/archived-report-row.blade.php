{{-- One row of a lead's archived reports, rendered by
     TechnicianPortalController::archivedReportsTable() for serverTable.js. --}}
@php
    $reportClient = $report->project?->client;
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
        {{ $report->report_date?->format(\App\Support\BusinessTime::DATE) ?? '—' }}
    </td>
    <td>
        {{ \App\Support\BusinessTime::format($report->archived_at) }}
    </td>
    <td class="text-center">
        <div class="d-inline-flex gap-1">
            <button type="button" class="btn btn-sm btn-primary py-1 px-2"
                data-view-report="{{ $report->id }}" title="View report">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>

            {{-- The same policy the endpoint enforces: a
                 lead restores what they filed away and
                 nothing else. --}}
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
