{{-- One row of a lead's report log, rendered by
     TechnicianPortalController::reportsTable() for serverTable.js. --}}
@php
    $reportClient = $report->project?->client;
@endphp

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
    <td>
        {{ $report->report_date?->format(\App\Support\BusinessTime::DATE) ?? '—' }}
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
                <button type="button" class="btn btn-sm btn-dark py-1 px-2"
                    data-archive-report="{{ $report->id }}"
                    data-report-label="{{ $report->displayCode() }} - {{ $report->report_title }}"
                    title="Archive report">
                    <i class="bi bi-archive" aria-hidden="true"></i>
                </button>
            @endcan
        </div>
    </td>
</tr>
