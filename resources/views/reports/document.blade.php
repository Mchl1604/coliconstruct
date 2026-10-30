@extends(auth()->user()?->isOfficeStaff() ? 'layouts.superadminNav' : 'layouts.portalNav')

@section('title', $report->report_title ?? 'Report')

@push('styles')
    <link href="/css/reportDocument.css" rel="stylesheet">
@endpush

@section('content')
    {{-- The whole of a generated progress report or a structured incident
         report, on one page meant to be printed - or saved as a PDF from the
         browser's print dialog. --}}
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div>
            <h4 class="fw-bold mb-1">{{ $report->report_title }}</h4>
            <p class="text-secondary small mb-0">{{ $report->displayCode() }} &middot; {{ $report->typeLabel() }}</p>
        </div>
        <div class="d-flex gap-2">
            @if ($backUrl && $backUrl !== url()->current())
                <a href="{{ $backUrl }}" class="btn btn-sm btn-outline-secondary">Back</a>
            @endif
            <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>
                Print
            </button>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-4">
            @if ($report->isGenerated())
                @include('reports.partials.progress-document', ['snapshot' => $report->report_data])
            @else
                @include('reports.partials.incident-document', ['report' => $report, 'canReview' => $canReview])
            @endif

            @if ($report->images->isNotEmpty())
                <h6 class="report-doc-heading">Photos</h6>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($report->images as $image)
                        <a href="{{ $image->url() }}" target="_blank" rel="noopener noreferrer">
                            <img src="{{ $image->url() }}" class="rounded shadow-sm"
                                style="width: 9rem; height: 9rem; object-fit: cover" alt="Report photo">
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
