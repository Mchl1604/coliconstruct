{{-- One row of the Archived Reports table, rendered by
     ReportController::archivedTable() for serverTable.js. --}}
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
        <div class="d-flex align-items-center gap-2">
            @if ($report->submitterAvatarUrl())
                <img class="user-avatar user-avatar-xs"
                    src="{{ $report->submitterAvatarUrl() }}" alt="" loading="lazy">
            @endif
            <x-technician-link :technician="$report->submitterTechnician()">{{ $report->submitterName() }}</x-technician-link>
        </div>
    </td>
    <td>
        {{ $report->report_date?->format(\App\Support\BusinessTime::DATE) ?? '—' }}
    </td>
    <td>
        {{ \App\Support\BusinessTime::format($report->archived_at) }}
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
