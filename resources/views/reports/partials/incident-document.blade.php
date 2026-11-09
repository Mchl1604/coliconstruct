{{--
    A structured incident report on its printable page, in the sections of the
    form it was filed on.

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
    @if ($incident->mayNeedDoleReport())
        <div class="alert alert-warning small py-2">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Lost-time injury. May need a DOLE report.
        </div>
    @endif

    <div class="report-form-section is-red">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <h6 class="report-form-section-title">
                <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                The Incident
            </h6>
            <span class="badge {{ $incident->statusBadgeClass() }}">{{ $incident->label('STATUSES', 'status') }}</span>
        </div>
        <div class="row g-3 mb-3">
            {!! $field('Type', $incident->label('TYPES', 'incident_type')) !!}
            {!! $field('Project', str_contains((string) $report->project?->name, (string) $report->project?->reference_no)
                ? $report->project?->name
                : trim($report->project?->reference_no.' · '.$report->project?->name, ' ·')) !!}
            {!! $field('When', \App\Support\BusinessTime::at($incident->occurred_at)?->format(\App\Support\BusinessTime::DATE.' g:i A')) !!}
            {!! $field('Where', $incident->label('LOCATIONS', 'location')) !!}
        </div>
        <div class="small text-secondary">What happened</div>
        <p class="mb-0" style="white-space: pre-line">{{ $report->report_description }}</p>
    </div>

    @if ($incident->incident_type === 'injury')
        <div class="report-form-section is-orange">
            <h6 class="report-form-section-title">
                <i class="bi bi-bandaid" aria-hidden="true"></i>
                Injury
            </h6>
            <div class="row g-3">
                {!! $field('Body part', $incident->label('BODY_PARTS', 'injury_body_part')) !!}
                {!! $field('Injury', $incident->label('INJURY_TYPES', 'injury_type')) !!}
                {!! $field('Treatment', $incident->label('TREATMENTS', 'injury_treatment')) !!}
            </div>
        </div>
    @endif

    @if ($incident->incident_type === 'refrigerant_leak')
        <div class="report-form-section is-teal">
            <h6 class="report-form-section-title">
                <i class="bi bi-droplet" aria-hidden="true"></i>
                Refrigerant
            </h6>
            <div class="row g-3">
                {!! $field('Refrigerant', $incident->label('REFRIGERANTS', 'refrigerant_type')) !!}
                {!! $field('Amount (kg)', $incident->refrigerant_amount !== null ? (string) (float) $incident->refrigerant_amount : null) !!}
            </div>
        </div>
    @endif

    <div class="report-form-section is-blue">
        <h6 class="report-form-section-title">
            <i class="bi bi-people" aria-hidden="true"></i>
            People Involved
        </h6>
        @if ($incident->persons->isEmpty())
            <p class="small text-secondary mb-0">Nobody named.</p>
        @else
            <table class="table table-sm small mb-0">
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
    </div>

    @if ($incident->resolution_notes || $incident->reviewed_at)
        <div class="report-form-section is-green">
            <h6 class="report-form-section-title">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                Resolution
            </h6>
            @if ($incident->resolution_notes)
                <p class="small" style="white-space: pre-line">{{ $incident->resolution_notes }}</p>
            @endif
            @if ($incident->reviewed_at)
                <p class="small text-secondary mb-0">
                    Last reviewed by {{ $incident->reviewer?->fullName() ?? 'an administrator' }}
                    on {{ \App\Support\BusinessTime::at($incident->reviewed_at)?->format(\App\Support\BusinessTime::DATE) }}.
                </p>
            @endif
        </div>
    @endif

    @if ($canReview)
        <form method="POST" action="{{ route('super-admin.incident-reports.status', $incident->incident_report_id) }}"
            class="report-form-section is-gray no-print">
            @csrf
            @method('PUT')
            <h6 class="report-form-section-title">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                Review
            </h6>
            <div class="row g-2">
                <div class="col-sm-4">
                    <label class="form-label small fw-semibold" for="incidentStatus">Status</label>
                    <select class="form-select" id="incidentStatus" name="status">
                        @foreach (\App\Models\IncidentReport::STATUSES as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $incident->status) === $value)>{{ $label }}</option>
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
