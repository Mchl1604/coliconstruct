@extends('layouts.publicSite')

@section('title', $card['name'] . ' - ' . $content->siteTitle())

@section('content')
    {{--
        The Super Admin project details layout, read only.

        Same cards in the same order - overview, assigned team, schedule,
        activity - with every edit control left out. The technician reports
        lead the activity panel rather than sitting behind a tab, because
        following them is the whole reason a client opens this page.
    --}}
    <section class="public-section">
        <div class="container">

            <a class="btn btn-link px-0 mb-3 text-decoration-none" href="{{ route('public.projects') }}">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Back to My Projects
            </a>

            {{-- ------------------------------------- Confirm your completion --}}
            {{-- The one thing on this whole site a client does rather than
                 reads, so it leads the page: above the project itself, with the
                 deadline stated rather than implied. Both buttons are shown
                 together because a client who is unhappy with the work needs
                 the second one as plainly as the first - and reaching support
                 changes nothing about the project or the countdown. --}}
            @if ($card['awaiting_confirmation'])
                <div class="card client-card shadow-sm mb-4 border-success">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-start gap-3">
                            <div class="fs-2 lh-1 text-success">
                                <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                            </div>

                            <div class="flex-grow-1">
                                <h2 class="h5 fw-bold mb-1">Ready for your confirmation</h2>

                                <p class="mb-2 text-secondary">
                                    Work finished
                                    @if ($card['completed_on'])
                                        <strong>{{ \App\Support\BusinessTime::format($card['completed_on'], 'F j, Y') }}</strong>
                                    @endif
                                    . Review the report below and confirm.
                                </p>

                                @if ($card['confirmation_deadline'])
                                    <p class="mb-3">
                                        <span class="badge bg-warning text-dark me-2">
                                            {{ $card['confirmation_countdown'] }}
                                        </span>
                                        Completes automatically on
                                        <strong>{{ \App\Support\BusinessTime::format($card['confirmation_deadline'], 'F j, Y') }}</strong>
                                        if we do not hear from you.
                                    </p>
                                @endif

                                <div class="d-flex flex-wrap gap-2">
                                    <form method="POST"
                                        action="{{ route('public.projects.confirm', $project->project_id) }}"
                                        onsubmit="return confirm('Confirm that the work on this project is complete? This closes the project and cannot be undone.');">
                                        @csrf
                                        <button type="submit" class="btn btn-success px-4">
                                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                                            Confirm Completion
                                        </button>
                                    </form>

                                    {{-- No inquiries table exists to post to, so this points at
                                         the channels the company actually publishes rather than
                                         at a form that would drop what was typed. --}}
                                    <a class="btn btn-outline-secondary px-4"
                                        @if ($supportEmail) href="mailto:{{ $supportEmail }}?subject={{ rawurlencode('Support request - ' . ($card['reference_no'] ?? $card['name'])) }}"
                                        @else
                                            href="{{ route('public.contact') }}" @endif>
                                        <i class="bi bi-life-preserver me-1" aria-hidden="true"></i>
                                        Contact Support
                                    </a>
                                </div>

                                <p class="text-secondary small mb-0 mt-3">
                                    Something not right? Contact us instead of confirming and we will put it right.
                                    @if ($supportPhone)
                                        You can also call <strong>{{ $supportPhone }}</strong>.
                                    @endif
                                    Getting in touch does not pause the
                                    {{ \App\Models\Project::COMPLETION_CONFIRMATION_DAYS }} day confirmation period.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ---------------------------------------------- Project overview --}}
            <div class="card client-card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-3">
                        <div>
                            <h1 class="fw-bold mb-1 h3">{{ $card['name'] }}</h1>

                            <span class="fw-bold text-brand-blue me-4">
                                {{ $card['reference_no'] ?? 'Reference not assigned' }}
                            </span>

                            {{-- The project type is not repeated here: it is
                                 already carried by the coloured badges below,
                                 and one project type on the page is enough. --}}
                            <div class="text-muted mt-2">
                                <span class="me-3">
                                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                    {{ $card['location'] ?: 'Location not set' }}
                                </span>
                            </div>

                            @if ($client)
                                @php
                                    $clientTypeIcon = match (strtolower($client->client_type ?? '')) {
                                        'residential' => 'bi-house-door',
                                        'commercial' => 'bi-building',
                                        default => 'bi-person',
                                    };
                                @endphp

                                <div class="text-muted mt-1">
                                    <span class="me-3">
                                        <i class="bi {{ $clientTypeIcon }}" aria-hidden="true"></i>
                                        {{ $client->client_type ?? 'N/A' }}
                                    </span>

                                    @if (strtolower($client->client_type ?? '') === 'commercial' && $client->company_name)
                                        <span>Company: {{ $client->company_name }}</span>
                                    @endif
                                </div>
                            @endif

                            <div class="mt-3 d-flex flex-wrap gap-2">
                                @foreach ($project->projectTypes as $type)
                                    <span class="badge rounded-pill client-type-badge px-3 py-2">
                                        {{ $type->type_name }}
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <span class="badge rounded-pill fs-6 px-4 py-3 {{ $card['status_badge_class'] }}">
                                {{ $card['status_label'] }}
                            </span>
                        </div>
                    </div>

                    @if ($card['description'])
                        <hr>
                        <p class="text-secondary public-prewrap mb-0">{{ $card['description'] }}</p>
                    @endif

                    {{-- The closing report. Shown from the moment the work is
                         reported finished, not only once it is signed off: this
                         is the very thing the client is being asked to review,
                         so it cannot wait for them to have reviewed it. --}}
                    @if ($project->hasCompletionReport() && $project->completion_summary && ! $project->isCancelled())
                        <div class="client-completion-panel mt-4">
                            <h2 class="h6 fw-bold text-success mb-2">
                                <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
                                Completion Report
                            </h2>

                            <p class="mb-1 public-prewrap">{{ $project->completion_summary }}</p>

                            @if ($project->completion_remarks)
                                <p class="text-secondary small mb-1 public-prewrap">
                                    {{ $project->completion_remarks }}
                                </p>
                            @endif

                            @if ($project->completed_at)
                                <p class="text-secondary small mb-1">
                                    Work completed
                                    {{ \App\Support\BusinessTime::format($project->completed_at, 'M d, Y') }}
                                </p>
                            @endif

                            @if ($card['completion_method_label'])
                                <p class="text-secondary small mb-1">
                                    {{ $card['completion_method_label'] }}@if ($project->client_confirmed_at)
                                        on
                                        {{ \App\Support\BusinessTime::format($project->client_confirmed_at, 'M d, Y') }}
                                    @endif
                                </p>
                            @endif

                            {{-- The evidence. A client asked to confirm the work
                                 has to be able to see it, so the photographs sit
                                 with the report rather than further down. --}}
                            @if ($project->completionPhotos->isNotEmpty())
                                <div class="row g-3 mt-2">
                                    @foreach ($project->completionPhotos as $photo)
                                        <div class="col-lg-3 col-md-4 col-6">
                                            <a href="{{ $photo->url() }}" target="_blank"
                                                rel="noopener noreferrer">
                                                <img src="{{ $photo->url() }}"
                                                    class="img-fluid rounded border" alt="Completion photo"
                                                    style="height:150px;width:100%;object-fit:cover;">
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($project->isCancelled() && $project->cancellation_reason)
                        <div class="client-cancellation-panel mt-4">
                            <h2 class="h6 fw-bold text-danger mb-2">
                                <i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i>
                                Cancellation Report
                            </h2>

                            <p class="mb-1 public-prewrap">{{ $project->cancellation_reason }}</p>

                            @if ($project->cancellation_remarks)
                                <p class="text-secondary small mb-0 public-prewrap">
                                    {{ $project->cancellation_remarks }}
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- Project documents, in the grouped cards the Super Admin
                         project page uses. A row of loose buttons stopped
                         reading as anything once a document could run to
                         several files: "Quotation 1, Quotation 2, Contract 1"
                         is a list of files pretending to be a list of
                         documents. Grouping puts each document back together,
                         with its own count, and the client reads the same
                         shape the office does.

                         What is deliberately not copied is the remove button:
                         these are the client's to read, never to change. --}}
                    @php
                        $documentTitles = \App\Http\Controllers\PublicSiteController::DOCUMENT_TITLES;

                        // Grouped rather than keyed: a project holds any number
                        // of files of each type, and keying would show the
                        // client only the last of them.
                        $clientDocuments = $project->documents
                            ->whereIn('document_type', array_keys($documentTitles))
                            ->groupBy('document_type');
                    @endphp

                    @if ($clientDocuments->isNotEmpty())
                        <hr>

                        <h2 class="h6 fw-bold mb-2">Project Documents</h2>

                        <div class="project-document-groups">
                            @foreach ($documentTitles as $type => $label)
                                @php $files = $clientDocuments->get($type, collect()); @endphp

                                {{-- A type the project holds nothing for is left
                                     out entirely rather than shown empty: an
                                     administrator needs to see that a contract
                                     is missing, a client does not. --}}
                                @continue($files->isEmpty())

                                <div class="project-document-group">
                                    <div class="project-document-group-head">
                                        <span class="fw-semibold">{{ $label }}</span>
                                        <span class="badge project-document-count">{{ $files->count() }}</span>
                                    </div>

                                    {{-- Straight to the file in a new tab, the
                                         way the administrative pages open the
                                         same documents: the client wants to read
                                         the document, not a page wrapped around
                                         it. --}}
                                    @foreach ($files as $document)
                                        <div class="project-document-file">
                                            <a href="{{ $document->url() }}" target="_blank"
                                                rel="noopener noreferrer" class="project-document-link"
                                                title="{{ $document->document_name }}">
                                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                                <span>{{ $document->document_name }}</span>
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- ------------------------------------- Assigned team and schedule --}}
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card client-card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h2 class="h5 mb-0 fw-bold">Assigned Team</h2>
                        </div>

                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                @forelse ($project->projectTechnicians as $projectTechnician)
                                    @php $technician = $projectTechnician->technician; @endphp

                                    @if ($technician)
                                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                            {{-- The person's own picture rather than a
                                                 generic icon: a client recognising who
                                                 is coming to their property is the whole
                                                 point of listing the team. Falls back to
                                                 the default avatar, so a technician who
                                                 has not uploaded one still shows a face
                                                 in line with the rest. --}}
                                            <div class="d-flex align-items-center gap-2 min-w-0">
                                                <x-user-avatar :user="$technician->account" size="sm" />
                                                <span class="text-truncate">{{ $technician->name }}</span>
                                            </div>

                                            @if (optional($technician->account)->role === 'lead_technician')
                                                <span class="badge client-lead-badge flex-shrink-0">Lead Technician</span>
                                            @endif
                                        </li>
                                    @endif
                                @empty
                                    <li class="list-group-item text-muted">No technicians assigned yet.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card client-card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h2 class="h5 mb-0 fw-bold">Project Schedule</h2>
                        </div>

                        <div class="card-body">
                            <div class="fw-semibold mb-2">Schedules:</div>

                            <ul class="client-range-list">
                                @forelse ($ranges as $range)
                                    <li>
                                        <i class="bi bi-calendar-event me-2 text-brand-blue" aria-hidden="true"></i>
                                        <strong>{{ $range['label'] }}</strong>
                                    </li>
                                @empty
                                    <li class="text-muted">No schedule set yet.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------ Project activity --}}
            <div class="card client-card shadow-sm">
                <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h2 class="h5 mb-0 fw-bold">Project Activity</h2>

                    <span class="badge client-count-badge">
                        {{ $reports->count() }} {{ Str::plural('report', $reports->count()) }}
                    </span>
                </div>

                <div class="card-body">

                    {{-- Technician reports: the tracker, so it comes first and is
                         given the page's strongest treatment. --}}
                    <div class="client-tracker mb-4">
                        <div class="client-tracker-heading">
                            <span class="client-tracker-icon">
                                <i class="bi bi-clipboard-pulse" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h3 class="h6 fw-bold mb-0">Technician Reports</h3>
                                <p class="text-secondary small mb-0">
                                    Updates filed from site by the technicians working on your project.
                                </p>
                            </div>
                        </div>

                        @forelse ($reports as $report)
                            @php $isIncident = $report->report_type === 'incident'; @endphp

                            <article class="client-report {{ $isIncident ? 'is-incident' : 'is-progress' }}">
                                <header class="client-report-header">
                                    <div>
                                        <span
                                            class="badge {{ $isIncident ? 'client-badge-incident' : 'client-badge-progress' }}">
                                            {{ \App\Models\TechnicianReport::TYPES[$report->report_type] ?? ucfirst($report->report_type) }}
                                        </span>

                                        <h4 class="h6 fw-bold mt-2 mb-0">{{ $report->report_title }}</h4>
                                    </div>

                                    <small class="text-muted text-nowrap">
                                        {{ \Carbon\CarbonImmutable::parse($report->report_date)->format('M d, Y') }}
                                    </small>
                                </header>

                                <div class="client-report-body">
                                    <p class="mb-0 public-prewrap">{{ $report->report_description }}</p>

                                    @if ($report->images->count())
                                        <div class="row g-2 mt-3">
                                            @foreach ($report->images as $image)
                                                <div class="col-6 col-md-3">
                                                    <a href="{{ $image->url() }}"
                                                        target="_blank" rel="noopener">
                                                        <img src="{{ $image->url() }}"
                                                            class="client-report-image" alt="Report photograph"
                                                            loading="lazy">
                                                    </a>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="client-tracker-empty">
                                <i class="bi bi-clipboard fs-3 d-block mb-2" aria-hidden="true"></i>
                                No reports yet. Updates appear here as
                                work progresses.
                            </div>
                        @endforelse
                    </div>

                    {{-- Tasks are deliberately not listed here. They are how the
                         company organises its own crew - who does what, and in
                         what order - and a client following their project reads
                         the technicians' reports above, which say what actually
                         happened on site. The progress bar on the card already
                         carries the one thing the task list was telling them. --}}

                    @if ($project->completionPhotos->isNotEmpty())
                        <hr class="my-4">

                        <h3 class="h6 fw-bold mb-2">Completion Photos</h3>

                        <div class="row g-2">
                            @foreach ($project->completionPhotos as $photo)
                                <div class="col-6 col-md-3">
                                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener">
                                        <img src="{{ $photo->url() }}" class="client-report-image"
                                            alt="Completion photograph" loading="lazy">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif

                </div>
            </div>

        </div>
    </section>
@endsection
