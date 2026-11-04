@extends(auth()->user()?->isOfficeStaff() ? 'layouts.superadminNav' : 'layouts.portalNav')

@section('title', 'Overall Progress')

@push('styles')
    <link href="/css/reportDocument.css" rel="stylesheet">
@endpush

@section('content')
    {{-- A project from its start to today, worked out when opened - see
         ProjectProgressOverview. Printed, or saved as a PDF from the browser's
         print dialog; never filed. --}}
    <div class="report-doc overall-progress">
        <div class="report-form-banner is-progress">
            <div class="d-flex align-items-center gap-3">
                <span class="report-form-banner-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
                <div>
                    <div class="overall-eyebrow">Overall Progress</div>
                    <h4 class="fw-bold mb-1">@unless (str_contains((string) $progress['project']['name'], (string) $progress['project']['reference_no'])){{ $progress['project']['reference_no'] }} &middot; @endunless{{ $progress['project']['name'] }}</h4>
                    <p class="mb-0 small">{{ $progress['project']['client'] }} &middot; {{ $progress['project']['address'] }}</p>
                </div>
            </div>
            <div class="overall-banner-side">
                <div class="d-flex justify-content-end gap-2 mb-2 no-print">
                    @if ($backUrl && $backUrl !== url()->current())
                        <a href="{{ $backUrl }}" class="report-form-back">
                            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                            Back
                        </a>
                    @endif
                    <button type="button" class="report-form-back border-0" onclick="window.print()">
                        <i class="bi bi-printer me-1" aria-hidden="true"></i>
                        Print
                    </button>
                </div>
                <div class="fw-semibold small">{{ $progress['started_on'] ?? 'Not scheduled' }} to {{ $progress['generated_on'] }}</div>
                <span class="overall-status">{{ $progress['project']['status'] }}</span>
            </div>
        </div>

        <div class="report-form-section is-blue">
            <h6 class="report-form-section-title">
                <i class="bi bi-signpost-split" aria-hidden="true"></i>
                Phases
                <span class="overall-section-note">{{ $progress['phase_label'] }}</span>
            </h6>

            <div class="progress overall-bar mb-3" role="progressbar" aria-label="Phases complete"
                aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width: {{ $progress['percent'] }}%"></div>
            </div>

            @forelse ($progress['phases'] as $phase)
                <div class="overall-phase {{ $phase['tone'] }}">
                    <div class="overall-phase-head">
                        <span class="overall-phase-number">{{ $phase['number'] }}</span>
                        <span class="fw-bold flex-grow-1">{{ $phase['title'] }}</span>
                        <span class="small fw-semibold">{{ $phase['tasks_label'] }}</span>
                        <span class="overall-pill {{ $phase['tone'] }}">{{ $phase['status'] }}</span>
                    </div>
                    @unless ($phase['is_future'] && $phase['tasks'] === [])
                        <div class="overall-phase-bar" aria-hidden="true">
                            <span style="width: {{ $phase['task_percent'] }}%"></span>
                        </div>
                        @include('reports.partials.project-progress-tasks', ['tasks' => $phase['tasks']])
                    @endunless
                </div>
            @empty
                <p class="small text-secondary mb-0">No phases set up.</p>
            @endforelse

            @if ($progress['other_tasks'] !== [])
                <div class="overall-phase is-gray">
                    <div class="overall-phase-head">
                        <span class="fw-bold">Other Tasks</span>
                    </div>
                    @include('reports.partials.project-progress-tasks', ['tasks' => $progress['other_tasks']])
                </div>
            @endif
        </div>

        <div class="report-form-section is-teal">
            <h6 class="report-form-section-title">
                <i class="bi bi-box-seam" aria-hidden="true"></i>
                Materials
            </h6>

            @if ($progress['materials'] === [])
                <p class="small text-secondary mb-0">None recorded.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm small overall-table mb-0">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Initial</th>
                                <th>Added</th>
                                <th>Used</th>
                                <th>Returned</th>
                                <th>Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($progress['materials'] as $material)
                                <tr>
                                    <td class="fw-semibold">{{ $material['material'] }}</td>
                                    <td>{{ $material['needed'] }}</td>
                                    <td>{{ $material['added'] }}</td>
                                    <td>{{ $material['used'] }}</td>
                                    <td>{{ $material['returned'] }}</td>
                                    <td>{{ $material['on_site'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="row g-3 mb-3">
            @foreach ([
                'incidents' => ['Incidents', 'is-red', 'bi-exclamation-triangle'],
                'daily_reports' => ['Daily Reports', 'is-purple', 'bi-clipboard-data'],
            ] as $key => [$heading, $tone, $icon])
                <div class="col-md-6">
                    <div class="report-form-section {{ $tone }} h-100 mb-0">
                        <h6 class="report-form-section-title">
                            <i class="bi {{ $icon }}" aria-hidden="true"></i>
                            {{ $heading }}
                            <span class="overall-section-note">{{ count($progress[$key]) }}</span>
                        </h6>

                        @forelse ($progress[$key] as $report)
                            <div class="overall-report-row">
                                <span class="text-secondary text-nowrap">{{ $report['date'] }}</span>
                                @if ($report['url'])
                                    <a href="{{ $report['url'] }}">{{ $report['title'] }}</a>
                                @else
                                    <span>{{ $report['title'] }}</span>
                                @endif
                            </div>
                        @empty
                            <p class="small text-secondary mb-0">None.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        <p class="small text-secondary mb-0">Generated by {{ $generatedBy }} on {{ $progress['generated_at'] }}.</p>
    </div>
@endsection
