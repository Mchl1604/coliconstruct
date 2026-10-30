@extends('layouts.portalNav')

@section('title', 'Progress Report')

@push('styles')
    <link href="/css/reportDocument.css" rel="stylesheet">
@endpush

@section('content')
    {{-- The lead's daily progress report: choose the project and the day,
         read what the system gathered, add what only the lead knows, file it.
         What is gathered is read again and frozen at the moment it is filed -
         see ProgressReportBuilder. --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Progress Report</h4>
            <p class="text-secondary small mb-0">Gathered from the day's work.</p>
        </div>
        <a href="{{ route('technician.reports') }}" class="btn btn-sm btn-outline-secondary">Back to Reports</a>
    </div>

    <div class="card shadow-sm border-0 rounded-2 mb-3">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('technician.progress-reports.create') }}" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="progressProject">Project</label>
                    <select class="form-select" id="progressProject" name="project" required>
                        <option value="">Choose a project</option>
                        @foreach ($projects as $option)
                            <option value="{{ $option->project_id }}" @selected($project?->project_id === $option->project_id)>
                                {{ $option->reference_no }} &mdash; {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="progressDate">Day</label>
                    <input type="date" class="form-control" id="progressDate" name="date" value="{{ $date }}"
                        max="{{ $today }}" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                        Gather
                    </button>
                </div>
            </form>

            @if ($projects->isEmpty())
                <p class="text-secondary small mt-3 mb-0">No projects you lead.</p>
            @endif
        </div>
    </div>

    @if ($refusal)
        <div class="alert alert-warning" role="alert">{{ $refusal }}</div>
    @endif

    @if ($snapshot)
        <div class="card shadow-sm border-0 rounded-2 mb-3">
            <div class="card-body p-4">
                @include('reports.partials.progress-document', ['snapshot' => $snapshot])
            </div>
        </div>

        <form method="POST" action="{{ route('technician.progress-reports.store') }}" enctype="multipart/form-data"
            class="card shadow-sm border-0 rounded-2">
            @csrf
            <input type="hidden" name="project_id" value="{{ $project->project_id }}">
            <input type="hidden" name="report_date" value="{{ $date }}">

            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Your Remarks</h6>

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

                <button type="submit" class="btn btn-success">
                    <i class="bi bi-send me-1" aria-hidden="true"></i>
                    File Report
                </button>
            </div>
        </form>
    @endif
@endsection
