@extends('layouts.portalNav')

@section('title', 'Incident Report')

@php
    use App\Models\IncidentReport;

    $select = function (string $name, string $label, array $options, bool $required = false, string $placeholder = 'Choose') {
        $id = 'incident_'.$name;
        $html = '<label class="form-label fw-semibold" for="'.$id.'">'.e($label).'</label>'
            .'<select class="form-select" id="'.$id.'" name="'.$name.'"'.($required ? ' required' : '').'>'
            .'<option value="">'.e($placeholder).'</option>';

        foreach ($options as $value => $text) {
            $html .= '<option value="'.e($value).'"'.(old($name) === (string) $value ? ' selected' : '').'>'.e($text).'</option>';
        }

        return $html.'</select>';
    };
@endphp

@section('content')
    {{-- The lead's incident report: every choice from the lists on
         IncidentReport, so incidents can be counted and compared. Sections
         that only fit some incidents - an injury, a refrigerant release - show
         when that type is chosen; see incidentForm.js. --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Incident Report</h4>
            <p class="text-secondary small mb-0">What happened, and to whom.</p>
        </div>
        <a href="{{ route('technician.reports') }}" class="btn btn-sm btn-outline-secondary">Back to Reports</a>
    </div>

    <div class="card shadow-sm border-0 rounded-2 mb-3">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('technician.incident-reports.create') }}" class="row g-2 align-items-end">
                <div class="col-md-9">
                    <label class="form-label fw-semibold" for="incidentProject">Project</label>
                    <select class="form-select" id="incidentProject" name="project" required>
                        <option value="">Choose a project</option>
                        @foreach ($projects as $option)
                            <option value="{{ $option->project_id }}" @selected($project?->project_id === $option->project_id)>
                                {{ $option->reference_no }} &mdash; {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Continue</button>
                </div>
            </form>

            @if ($projects->isEmpty())
                <p class="text-secondary small mt-3 mb-0">No projects you lead.</p>
            @endif
        </div>
    </div>

    @if ($project)
        <form method="POST" action="{{ route('technician.incident-reports.store') }}" enctype="multipart/form-data"
            class="card shadow-sm border-0 rounded-2" data-incident-form>
            @csrf
            <input type="hidden" name="project_id" value="{{ $project->project_id }}">

            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">The Incident</h6>
                <div class="row g-3">
                    <div class="col-md-6">{!! $select('incident_type', 'Type', IncidentReport::TYPES, true) !!}</div>
                    <div class="col-md-6">{!! $select('severity', 'Severity', IncidentReport::SEVERITIES, true) !!}</div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold" for="incident_occurred_date">Date</label>
                        <input type="date" class="form-control" id="incident_occurred_date" name="occurred_date"
                            value="{{ old('occurred_date', $today) }}" max="{{ $today }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold" for="incident_occurred_time">Time</label>
                        <input type="time" class="form-control" id="incident_occurred_time" name="occurred_time"
                            value="{{ old('occurred_time', $now) }}" required>
                    </div>
                    <div class="col-md-6">{!! $select('location', 'Where', IncidentReport::LOCATIONS, true) !!}</div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="incident_location_details">Exact spot</label>
                        <input type="text" class="form-control" id="incident_location_details" name="location_details"
                            maxlength="255" placeholder="Optional" value="{{ old('location_details') }}">
                    </div>
                    <div class="col-md-6">
                        {!! $select('phase_id', 'Phase', $phases->pluck('title', 'phase_id')->all(), false, 'None') !!}
                    </div>
                    <div class="col-md-6">
                        {!! $select('task_id', 'Task', $tasks->pluck('task_title', 'task_id')->all(), false, 'None') !!}
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="incident_description">What happened</label>
                        <textarea class="form-control" id="incident_description" name="description" rows="4"
                            maxlength="5000" required>{{ old('description') }}</textarea>
                    </div>
                </div>

                {{-- An injury. --}}
                <div class="row g-3 mt-1 d-none" data-incident-for="injury">
                    <div class="col-12"><h6 class="fw-bold mb-0">Injury</h6></div>
                    <div class="col-md-4">{!! $select('injury_body_part', 'Body part', IncidentReport::BODY_PARTS) !!}</div>
                    <div class="col-md-4">{!! $select('injury_type', 'Injury', IncidentReport::INJURY_TYPES) !!}</div>
                    <div class="col-md-4">{!! $select('injury_treatment', 'Treatment', IncidentReport::TREATMENTS) !!}</div>
                </div>

                {{-- A refrigerant release. --}}
                <div class="row g-3 mt-1 d-none" data-incident-for="refrigerant_leak">
                    <div class="col-12"><h6 class="fw-bold mb-0">Refrigerant</h6></div>
                    <div class="col-md-6">{!! $select('refrigerant_type', 'Refrigerant', IncidentReport::REFRIGERANTS) !!}</div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="incident_refrigerant_amount">Estimated amount (kg)</label>
                        <input type="number" class="form-control" id="incident_refrigerant_amount" name="refrigerant_amount"
                            min="0" step="0.01" value="{{ old('refrigerant_amount') }}">
                    </div>
                </div>

                {{-- Damage, for any incident that caused some. --}}
                <div class="row g-3 mt-1">
                    <div class="col-12"><h6 class="fw-bold mb-0">Damage</h6></div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="incident_damage_description">What was damaged</label>
                        <input type="text" class="form-control" id="incident_damage_description" name="damage_description"
                            maxlength="255" placeholder="Optional" value="{{ old('damage_description') }}">
                    </div>
                    <div class="col-md-3">{!! $select('damage_owner', 'Owner', IncidentReport::DAMAGE_OWNERS, false, 'None') !!}</div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold" for="incident_damage_cost">Estimated cost (PHP)</label>
                        <input type="number" class="form-control" id="incident_damage_cost" name="damage_cost" min="0"
                            step="0.01" value="{{ old('damage_cost') }}">
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-12"><h6 class="fw-bold mb-0">Response</h6></div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="incident_immediate_action">Immediate action</label>
                        <textarea class="form-control" id="incident_immediate_action" name="immediate_action" rows="2"
                            maxlength="2000">{{ old('immediate_action') }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="incident_preventive_action">To prevent it</label>
                        <textarea class="form-control" id="incident_preventive_action" name="preventive_action" rows="2"
                            maxlength="2000">{{ old('preventive_action') }}</textarea>
                    </div>
                    <div class="col-md-6">{!! $select('cause', 'Cause', IncidentReport::CAUSES, false, 'Not known') !!}</div>
                    <div class="col-md-6 d-flex align-items-end gap-4">
                        <div class="form-check">
                            <input type="hidden" name="work_stopped" value="0">
                            <input class="form-check-input" type="checkbox" id="incident_work_stopped" name="work_stopped"
                                value="1" @checked(old('work_stopped'))>
                            <label class="form-check-label" for="incident_work_stopped">Work stopped</label>
                        </div>
                        <div class="form-check">
                            <input type="hidden" name="client_notified" value="0">
                            <input class="form-check-input" type="checkbox" id="incident_client_notified" name="client_notified"
                                value="1" @checked(old('client_notified'))>
                            <label class="form-check-label" for="incident_client_notified">Client notified</label>
                        </div>
                    </div>
                </div>

                {{-- The people: a team member picked from the team, or anyone
                     else by name. Rows added by incidentForm.js. --}}
                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0">People Involved</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-incident-person-add>
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                            Add Person
                        </button>
                    </div>
                    <div data-incident-persons></div>
                    <p class="small text-secondary mb-0" data-incident-persons-empty>Nobody added.</p>
                </div>

                <div class="mt-4">
                    <label class="form-label fw-semibold" for="incident_photos">Photos</label>
                    <input type="file" class="form-control" id="incident_photos" name="images[]" accept="image/jpeg,image/png"
                        multiple>
                    <div class="form-text">Optional. Up to 5 JPG or PNG.</div>
                </div>

                <button type="submit" class="btn btn-danger mt-4">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
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
