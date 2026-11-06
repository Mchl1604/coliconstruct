@extends(auth()->user()?->isOfficeStaff() ? 'layouts.superadminNav' : 'layouts.portalNav')

@section('title', $report->report_title ?? 'Report')

@push('styles')
    <link href="/css/reportDocument.css" rel="stylesheet">
@endpush

@section('content')
    {{-- The whole of a generated progress report or a structured incident
         report, in the design of the form it was filed on, on one page meant
         to be printed - or saved as a PDF from the browser's print dialog. --}}
    <div class="report-form-banner {{ $report->isGenerated() ? 'is-progress' : 'is-incident' }}">
        <div class="d-flex align-items-center gap-3">
            <span class="report-form-banner-icon">
                <i class="bi {{ $report->isGenerated() ? 'bi-clipboard-data' : 'bi-exclamation-triangle' }}" aria-hidden="true"></i>
            </span>
            <div>
                <h4 class="fw-bold mb-1">{{ $report->report_title }}</h4>
                <p class="mb-0 small">{{ $report->displayCode() }} &middot; {{ $report->typeLabel() }}</p>
            </div>
        </div>
        <div class="d-flex gap-2 no-print">
            @if ($backUrl && $backUrl !== url()->current())
                <a href="{{ $backUrl }}" class="report-form-back">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                    Back
                </a>
            @endif
            <button type="button" class="report-form-back" onclick="window.print()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>
                Print
            </button>
        </div>
    </div>

    @php
        $photos = $report->images;
    @endphp

    @if ($report->isGenerated())
        @php
            $snapshot = $report->report_data;
            $notes = array_filter([
                'Additional Remarks' => $snapshot['remarks'] ?? null,
                'Issues / Delays' => $snapshot['issues'] ?? null,
                'Plan for Tomorrow' => $snapshot['next_plan'] ?? null,
            ], fn ($note) => filled($note));
        @endphp

        <div class="row g-3 report-document-columns">
            <div class="col-lg-7">
                <div class="report-form-section is-blue mb-0">
                    <h6 class="report-form-section-title">
                        <i class="bi bi-list-check" aria-hidden="true"></i>
                        Day's Work
                    </h6>
                    @include('reports.partials.progress-document', ['snapshot' => $snapshot, 'hideNotes' => true])
                </div>
            </div>

            <div class="col-lg-5">
                <div class="report-form-section is-green">
                    <h6 class="report-form-section-title">
                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                        Additional Remarks
                    </h6>
                    @forelse ($notes as $label => $note)
                        @if ($label !== 'Additional Remarks')
                            <div class="fw-semibold small mt-2">{{ $label }}</div>
                        @endif
                        <p class="small mb-2" style="white-space: pre-line">{{ $note }}</p>
                    @empty
                        <p class="small text-secondary mb-0">None.</p>
                    @endforelse
                </div>

                @include('reports.partials.document-photos', ['photos' => $photos])
            </div>
        </div>
    @else
        @include('reports.partials.incident-document', ['report' => $report, 'canReview' => $canReview])
        @include('reports.partials.document-photos', ['photos' => $photos])
    @endif
@endsection
