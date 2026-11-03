@extends($portal === 'super-admin' ? 'layouts.superadminNav' : 'layouts.portalNav')

@section('title', 'Progress Report')

@push('styles')
    <link href="/css/reportDocument.css" rel="stylesheet">
@endpush

@section('content')
    {{-- The daily progress report, always for today: read what the system
         gathered, add what only the lead knows, file it. What is gathered is
         read again and frozen at the moment it is filed - see
         ProgressReportBuilder. Opened from a project, it asks for no project. --}}
    <div class="report-form-banner is-progress">
        <div class="d-flex align-items-center gap-3">
            <span class="report-form-banner-icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span>
            <div>
                <h4 class="fw-bold mb-1">Progress Report</h4>
                <p class="mb-0 small">
                    {{ \Carbon\CarbonImmutable::parse($date)->format(\App\Support\BusinessTime::DATE) }}
                    @if ($project)
                        &middot; {{ $project->reference_no }}
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
            <form method="GET" action="{{ route($portal.'.progress-reports.create') }}">
                <label class="form-label fw-semibold" for="progressProject">Project</label>
                <select class="form-select" id="progressProject" name="project" required onchange="this.form.submit()">
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

    @if ($refusal)
        <div class="alert alert-warning" role="alert">{{ $refusal }}</div>
    @endif

    @if ($snapshot)
        <div class="report-form-tiles">
            <div class="report-form-tile is-blue">
                <span>Progress</span>
                <strong>{{ $snapshot['phase']['percent'] }}%</strong>
            </div>
            <div class="report-form-tile is-teal">
                <span>In Progress</span>
                <strong>{{ count($snapshot['task_updates']) + count($snapshot['in_progress_tasks'] ?? []) }}</strong>
            </div>
            <div class="report-form-tile is-green">
                <span>Completed</span>
                <strong>{{ count($snapshot['completed_tasks']) }}</strong>
            </div>
            <div class="report-form-tile is-red">
                <span>Overdue</span>
                <strong>{{ count($snapshot['overdue_tasks']) }}</strong>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="report-form-section is-blue h-100 mb-0">
                    <h6 class="report-form-section-title">
                        <i class="bi bi-list-check" aria-hidden="true"></i>
                        Today's Work
                    </h6>
                    @include('reports.partials.progress-document', ['snapshot' => $snapshot])
                </div>
            </div>

            <div class="col-lg-5">
                <form method="POST" action="{{ route($portal.'.progress-reports.store') }}" enctype="multipart/form-data"
                    class="report-form-section is-green h-100 mb-0">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $project->project_id }}">
                    @if ($fromProject)
                        <input type="hidden" name="from" value="project">
                    @endif

                    <h6 class="report-form-section-title">
                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                        Your Remarks
                    </h6>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="progressRemarks">How the day went</label>
                        <textarea class="form-control" id="progressRemarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks') }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="progressIssues">Issues or delays</label>
                        <textarea class="form-control" id="progressIssues" name="issues" rows="2" maxlength="2000">{{ old('issues') }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="progressNext">Plan for tomorrow</label>
                        <textarea class="form-control" id="progressNext" name="next_plan" rows="2" maxlength="2000">{{ old('next_plan') }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="progressPhotos">Photos</label>
                        <input type="file" class="form-control" id="progressPhotos" name="images[]"
                            accept="image/jpeg,image/png" multiple>
                        <div class="form-text">Optional. Up to 5 JPG or PNG.</div>
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>
                        File Report
                    </button>
                </form>
            </div>
        </div>
    @endif
@endsection
