{{--
    A structured incident report, on the printable report page.

    Expects $report (with incident.persons) and $canReview - whether to draw
    the office's review form.
--}}
@php
    $incident = $report->incident;
    $field = fn (string $label, ?string $value) => $value === null || $value === ''
        ? ''
        : '<div class="col-sm-6 col-lg-4"><div class="small text-secondary">'.e($label).'</div><div class="fw-semibold">'.e($value).'</div></div>';
@endphp

<div class="report-doc">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div>
            <div class="fw-bold fs-5">{{ $report->displayCode() }} &middot; {{ $incident->label('TYPES', 'incident_type') }}</div>
            <div class="text-secondary small">
                {{ $report->project?->reference_no }} &middot; {{ $report->project?->name }}
            </div>
        </div>
        <div class="text-end">
            <span class="badge {{ $incident->severityBadgeClass() }}">{{ $incident->label('SEVERITIES', 'severity') }}</span>
            <span class="badge {{ $incident->statusBadgeClass() }}">{{ $incident->label('STATUSES', 'status') }}</span>
        </div>
    </div>

    @if ($incident->mayNeedDoleReport())
        <div class="alert alert-warning small py-2">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Lost-time injury. May need a DOLE report.
        </div>
    @endif

    <div class="row g-3 mb-3">
        {!! $field('When', \App\Support\BusinessTime::at($incident->occurred_at)?->format(\App\Support\BusinessTime::DATE.' g:i A')) !!}
        {!! $field('Where', $incident->label('LOCATIONS', 'location').($incident->location_details ? ' - '.$incident->location_details : '')) !!}
        {!! $field('Phase', $incident->phase?->title) !!}
        {!! $field('Task', $incident->task?->task_title) !!}
        {!! $field('Cause', $incident->label('CAUSES', 'cause')) !!}
        {!! $field('Work stopped', $incident->work_stopped ? 'Yes' : 'No') !!}
        {!! $field('Client notified', $incident->client_notified ? 'Yes' : 'No') !!}
        @if ($incident->incident_type === 'injury')
            {!! $field('Body part', $incident->label('BODY_PARTS', 'injury_body_part')) !!}
            {!! $field('Injury', $incident->label('INJURY_TYPES', 'injury_type')) !!}
            {!! $field('Treatment', $incident->label('TREATMENTS', 'injury_treatment')) !!}
        @endif
        @if ($incident->damage_description || $incident->damage_owner || $incident->damage_cost !== null)
            {!! $field('Damage', $incident->damage_description) !!}
            {!! $field('Owner', $incident->label('DAMAGE_OWNERS', 'damage_owner')) !!}
            {!! $field('Estimated cost', $incident->damage_cost !== null ? 'PHP '.number_format((float) $incident->damage_cost, 2) : null) !!}
        @endif
        @if ($incident->incident_type === 'refrigerant_leak')
            {!! $field('Refrigerant', $incident->label('REFRIGERANTS', 'refrigerant_type')) !!}
            {!! $field('Amount (kg)', $incident->refrigerant_amount !== null ? (string) (float) $incident->refrigerant_amount : null) !!}
        @endif
    </div>

    <h6 class="report-doc-heading">What Happened</h6>
    <p class="small" style="white-space: pre-line">{{ $report->report_description }}</p>

    @if ($incident->immediate_action)
        <h6 class="report-doc-heading">Immediate Action</h6>
        <p class="small" style="white-space: pre-line">{{ $incident->immediate_action }}</p>
    @endif

    @if ($incident->preventive_action)
        <h6 class="report-doc-heading">To Prevent It</h6>
        <p class="small" style="white-space: pre-line">{{ $incident->preventive_action }}</p>
    @endif

    <h6 class="report-doc-heading">People Involved</h6>
    @if ($incident->persons->isEmpty())
        <p class="small text-secondary">Nobody named.</p>
    @else
        <table class="table table-sm small">
            <thead><tr><th>Name</th><th>Who</th><th>Involvement</th></tr></thead>
            <tbody>
                @foreach ($incident->persons as $person)
                    <tr>
                        <td>{{ $person->name }}</td>
                        <td>{{ $person->typeLabel() }}</td>
                        <td>{{ $person->involvementLabel() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($incident->resolution_notes)
        <h6 class="report-doc-heading">Resolution</h6>
        <p class="small" style="white-space: pre-line">{{ $incident->resolution_notes }}</p>
    @endif

    @if ($incident->reviewed_at)
        <p class="small text-secondary">
            Last reviewed by {{ $incident->reviewer?->fullName() ?? 'an administrator' }}
            on {{ \App\Support\BusinessTime::at($incident->reviewed_at)?->format(\App\Support\BusinessTime::DATE) }}.
        </p>
    @endif

    @if ($canReview)
        <form method="POST" action="{{ route('super-admin.incident-reports.status', $incident->incident_report_id) }}"
            class="border rounded-3 p-3 mt-3 no-print">
            @csrf
            @method('PUT')
            <h6 class="fw-bold mb-2">Review</h6>
            <div class="row g-2">
                <div class="col-sm-4">
                    <label class="form-label small fw-semibold" for="incidentStatus">Status</label>
                    <select class="form-select" id="incidentStatus" name="status">
                        @foreach (\App\Models\IncidentReport::STATUSES as $value => $label)
                            <option value="{{ $value }}" @selected($incident->status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-8">
                    <label class="form-label small fw-semibold" for="incidentResolution">Resolution notes</label>
                    <textarea class="form-control" id="incidentResolution" name="resolution_notes" rows="2"
                        maxlength="2000">{{ old('resolution_notes', $incident->resolution_notes) }}</textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-2">Save Review</button>
        </form>
    @endif
</div>
