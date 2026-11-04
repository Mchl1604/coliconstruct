@extends($portal === 'super-admin' ? 'layouts.superadminNav' : 'layouts.portalNav')

@section('title', 'Incident Report')

@push('styles')
    <link href="/css/reportDocument.css" rel="stylesheet">
@endpush

@php
    use App\Models\IncidentReport;

    // A list offering Other brings a box to say what, shown by incidentForm.js.
    $select = function (string $name, string $label, array $options, bool $required = false, string $placeholder = 'Choose') {
        $id = 'incident_'.$name;
        $html = '<label class="form-label fw-semibold" for="'.$id.'">'.e($label).'</label>'
            .'<select class="form-select" id="'.$id.'" name="'.$name.'"'.($required ? ' required' : '').'>'
            .'<option value="">'.e($placeholder).'</option>';

        foreach ($options as $value => $text) {
            $html .= '<option value="'.e($value).'"'.(old($name) === (string) $value ? ' selected' : '').'>'.e($text).'</option>';
        }

        $html .= '</select>';

        if (isset($options['other'])) {
            $html .= '<div class="mt-2 d-none" data-other-for="'.$name.'">'
                .'<input type="text" class="form-control" name="'.$name.'_other" maxlength="150"'
                .' aria-label="'.e($label).': please specify" placeholder="Please specify" value="'.e((string) old($name.'_other')).'">'
                .'</div>';
        }

        return $html;
    };
@endphp

@section('content')
    {{-- The incident report: every choice from the lists on IncidentReport, so
         incidents can be counted and compared. Sections that only fit some
         incidents - an injury, a refrigerant release - show when that type is
         chosen; see incidentForm.js. Opened from a project, it asks for no
         project. --}}
    <div class="report-form-banner is-incident">
        <div class="d-flex align-items-center gap-3">
            <span class="report-form-banner-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
            <div>
                <h4 class="fw-bold mb-1">Incident Report</h4>
                <p class="mb-0 small">
                    @if ($project)
                        {{ $project->reference_no }}
                    @else
                        What happened, and to whom.
                    @endif
                </p>
            </div>
        </div>
        <a href="{{ $backUrl }}" class="report-form-back">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            {{ $fromProject ? 'Back to Project' : 'Back to Reports' }}
        </a>
    </div>

    @unless ($fromProject && $project)
        <div class="report-form-section is-blue">
            <form method="GET" action="{{ route($portal.'.incident-reports.create') }}">
                <label class="form-label fw-semibold" for="incidentProject">Project</label>
                <select class="form-select" id="incidentProject" name="project" required onchange="this.form.submit()">
                    <option value="">Choose a project</option>
                    @foreach ($projects as $option)
                        <option value="{{ $option->project_id }}" @selected($project?->project_id === $option->project_id)>
                            {{ $option->reference_no }} &mdash; {{ $option->name }}
                        </option>
                    @endforeach
                </select>
            </form>

            @if ($projects->isEmpty())
                <p class="text-secondary small mt-2 mb-0">No projects to report on.</p>
            @endif
        </div>
    @endunless

    @if ($project)
        <form method="POST" action="{{ route($portal.'.incident-reports.store') }}" enctype="multipart/form-data"
            data-incident-form>
            @csrf
            <input type="hidden" name="project_id" value="{{ $project->project_id }}">
            @if ($fromProject)
                <input type="hidden" name="from" value="project">
            @endif

            <div class="report-form-section is-red">
                <h6 class="report-form-section-title">
                    <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                    The Incident
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">{!! $select('incident_type', 'Type', IncidentReport::TYPES, true) !!}</div>
                    <div class="col-md-6">{!! $select('location', 'Where', IncidentReport::LOCATIONS, true) !!}</div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" for="incident_occurred_date">Date</label>
                        <input type="date" class="form-control" id="incident_occurred_date" name="occurred_date"
                            value="{{ old('occurred_date', $today) }}" max="{{ $today }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" for="incident_occurred_time">Time</label>
                        <input type="time" class="form-control" id="incident_occurred_time" name="occurred_time"
                            value="{{ old('occurred_time', $now) }}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="incident_description">What happened</label>
                        <textarea class="form-control" id="incident_description" name="description" rows="4"
                            maxlength="5000" required>{{ old('description') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- An injury. --}}
            <div class="report-form-section is-orange d-none" data-incident-for="injury">
                <h6 class="report-form-section-title">
                    <i class="bi bi-bandaid" aria-hidden="true"></i>
                    Injury
                </h6>
                <div class="row g-3">
                    <div class="col-md-4">{!! $select('injury_body_part', 'Body part', IncidentReport::BODY_PARTS) !!}</div>
                    <div class="col-md-4">{!! $select('injury_type', 'Injury', IncidentReport::INJURY_TYPES) !!}</div>
                    <div class="col-md-4">{!! $select('injury_treatment', 'Treatment', IncidentReport::TREATMENTS) !!}</div>
                </div>
            </div>

            {{-- A refrigerant release. --}}
            <div class="report-form-section is-teal d-none" data-incident-for="refrigerant_leak">
                <h6 class="report-form-section-title">
                    <i class="bi bi-droplet" aria-hidden="true"></i>
                    Refrigerant
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">{!! $select('refrigerant_type', 'Refrigerant', IncidentReport::REFRIGERANTS) !!}</div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="incident_refrigerant_amount">Estimated amount (kg)</label>
                        <input type="number" class="form-control" id="incident_refrigerant_amount" name="refrigerant_amount"
                            min="0" step="0.01" value="{{ old('refrigerant_amount') }}">
                    </div>
                </div>
            </div>

            <div class="report-form-section is-amber">
                <h6 class="report-form-section-title">
                    <i class="bi bi-tools" aria-hidden="true"></i>
                    Damage and Response
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="incident_damage_description">What was damaged</label>
                        <input type="text" class="form-control" id="incident_damage_description" name="damage_description"
                            maxlength="255" placeholder="Optional" value="{{ old('damage_description') }}">
                    </div>
                    <div class="col-md-6">{!! $select('cause', 'Cause', IncidentReport::CAUSES, false, 'Not known') !!}</div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="incident_immediate_action">Immediate action</label>
                        <textarea class="form-control" id="incident_immediate_action" name="immediate_action" rows="2"
                            maxlength="2000">{{ old('immediate_action') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- The people: a team member picked from the team, or anyone else
                 by name. Rows added by incidentForm.js. --}}
            <div class="report-form-section is-blue">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="report-form-section-title mb-0">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        People Involved
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-incident-person-add>
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                        Add Person
                    </button>
                </div>
                <div data-incident-persons></div>
                <p class="small text-secondary mb-0" data-incident-persons-empty>Nobody added.</p>
            </div>

            <div class="report-form-section is-purple">
                <h6 class="report-form-section-title">
                    <i class="bi bi-camera" aria-hidden="true"></i>
                    Photos
                </h6>
                <input type="file" class="form-control" id="incident_photos" name="images[]" accept="image/jpeg,image/png"
                    aria-label="Photos" multiple>
                <div class="form-text">Optional. Up to 5 JPG or PNG.</div>
            </div>

            <div class="text-end mb-4">
                <button type="submit" class="btn btn-danger px-4">
                    <i class="bi bi-send me-1" aria-hidden="true"></i>
                    File Incident Report
                </button>
            </div>
        </form>

        {{-- One person's row, copied by incidentForm.js. --}}
        <template data-incident-person-template>
            <div class="row g-2 align-items-end mb-2" data-incident-person>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Team member</label>
                    <select class="form-select form-select-sm" data-person-field="technician_id">
                        <option value="">Not on the team</option>
                        @foreach ($team as $member)
                            <option value="{{ $member->technician_id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Name</label>
                    <input type="text" class="form-control form-control-sm" maxlength="150" data-person-field="name"
                        placeholder="If not on the team">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Who</label>
                    <select class="form-select form-select-sm" data-person-field="person_type">
                        @foreach (IncidentReport::PERSON_TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Involvement</label>
                    <select class="form-select form-select-sm" data-person-field="involvement">
                        @foreach (IncidentReport::INVOLVEMENTS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger" title="Remove" data-incident-person-remove>
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </template>
    @endif
@endsection

@push('scripts')
    <script src="/js/incidentForm.js"></script>
@endpush
