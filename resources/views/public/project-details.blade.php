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

                    {{-- The closing report, once there is one. --}}
                    @if ($project->isCompleted() && $project->completion_summary)
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
                                <p class="text-secondary small mb-0">
                                    Completed
                                    {{ \Carbon\CarbonImmutable::parse($project->completed_at)->format('M d, Y') }}
                                </p>
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

                    {{-- Project documents. A button appears only for a document
                         that exists: a disabled control that never becomes
                         usable tells the client nothing. --}}
                    @php
                        $documentTitles = \App\Http\Controllers\PublicSiteController::DOCUMENT_TITLES;

                        // Keyed into a plain array on purpose: Eloquent's
                        // Collection::only() filters by primary key rather than
                        // by array key, so it would quietly return nothing here.
                        $clientDocuments = $project->documents
                            ->whereIn('document_type', array_keys($documentTitles))
                            ->keyBy('document_type')
                            ->all();
                    @endphp

                    @if ($clientDocuments !== [])
                        <hr>

                        <h2 class="h6 fw-bold mb-2">Project Documents</h2>

                        {{-- Straight to the file in a new tab, the way the
                             administrative pages open the same documents: the
                             client wants to read the document, not a page
                             wrapped around it. --}}
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($documentTitles as $type => $label)
                                @continue(! isset($clientDocuments[$type]))

                                <a class="btn btn-outline-brand-blue"
                                    href="{{ asset($clientDocuments[$type]->document_path) }}" target="_blank"
                                    rel="noopener noreferrer">
                                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>
                                    {{ $label }}
                                </a>
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
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="bi bi-person-circle me-2 text-brand-blue"
                                                    aria-hidden="true"></i>
                                                {{ $technician->name }}
                                            </div>

                                            @if (optional($technician->account)->role === 'lead_technician')
                                                <span class="badge client-lead-badge">Lead Technician</span>
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
                            <div class="fw-semibold mb-2">Date Ranges:</div>

                            <ul class="client-range-list">
                                @forelse ($ranges as $range)
                                    <li>
                                        <i class="bi bi-calendar-event me-2 text-brand-blue" aria-hidden="true"></i>
                                        <strong>
                                            {{ \Carbon\CarbonImmutable::parse($range['start'])->format('M d, Y') }}
                                        </strong>
                                        &ndash;
                                        <strong>
                                            {{ \Carbon\CarbonImmutable::parse($range['end'])->format('M d, Y') }}
                                        </strong>
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
                                                    <a href="{{ asset('storage/' . $image->image_path) }}"
                                                        target="_blank" rel="noopener">
                                                        <img src="{{ asset('storage/' . $image->image_path) }}"
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
                                No reports have been filed yet. Updates from the technicians will appear here as
                                work progresses.
                            </div>
                        @endforelse
                    </div>

                    {{-- Tasks, below the tracker. --}}
                    <h3 class="h6 fw-bold mb-2">
                        Tasks
                        <span class="text-secondary fw-normal">
                            ({{ $completedTasks->count() }} completed, {{ $remainingTasks->count() }} remaining)
                        </span>
                    </h3>

                    @if ($completedTasks->isEmpty() && $remainingTasks->isEmpty())
                        <p class="text-secondary small mb-0">No tasks have been scheduled yet.</p>
                    @else
                        <div class="row g-4">
                            @if ($remainingTasks->isNotEmpty())
                                <div class="col-md-6">
                                    <div class="public-detail-label mb-2">Remaining</div>
                                    <ul class="public-task-list">
                                        @foreach ($remainingTasks as $task)
                                            <li>
                                                <i class="bi bi-circle text-secondary" aria-hidden="true"></i>
                                                <span>
                                                    {{ $task->task_title }}
                                                    @if ($task->due_date)
                                                        <span class="text-secondary small">
                                                            &middot; due
                                                            {{ \Carbon\CarbonImmutable::parse($task->due_date)->format('M j, Y') }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($completedTasks->isNotEmpty())
                                <div class="col-md-6">
                                    <div class="public-detail-label mb-2">Completed</div>
                                    <ul class="public-task-list">
                                        @foreach ($completedTasks as $task)
                                            <li>
                                                <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                                                <span>
                                                    {{ $task->task_title }}
                                                    @if ($task->completed_at)
                                                        <span class="text-secondary small">
                                                            &middot; {{ $task->completed_at->format('M j, Y') }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($project->completionPhotos->isNotEmpty())
                        <hr class="my-4">

                        <h3 class="h6 fw-bold mb-2">Completion Photos</h3>

                        <div class="row g-2">
                            @foreach ($project->completionPhotos as $photo)
                                <div class="col-6 col-md-3">
                                    <a href="{{ asset($photo->photo_path) }}" target="_blank" rel="noopener">
                                        <img src="{{ asset($photo->photo_path) }}" class="client-report-image"
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
