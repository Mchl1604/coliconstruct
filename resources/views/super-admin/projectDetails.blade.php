@extends('layouts.superadminNav')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="/css/super-admin/projectDetails.css">
        {{-- The Assign To picker cards, shared with the Tasks page and the
             technician portal. --}}
        <link rel="stylesheet" href="/css/taskModal.css">
        {{-- The Import Team dialog, shared with the project wizard. --}}
        <link rel="stylesheet" href="/css/importTeam.css">
    @endpush
    @php
        $scheduleStart = $project->schedules->min('start_datetime');
        $scheduleEnd = $project->schedules->max('end_datetime');
        $hasSchedule = $scheduleStart && $scheduleEnd;
        // Each range on its own: the pickers work out the period they span,
        // which is what a task is measured against, and the panel below still
        // shows a person which days are actually booked.
        $scheduleRanges = $project->schedules
            ->map(
                fn($schedule) => [
                    'start' => \Carbon\Carbon::parse($schedule->start_datetime)->format('Y-m-d'),
                    'end' => \Carbon\Carbon::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('Y-m-d'),
                ],
            )
            ->values();
        // Tasks are dated, never timed, so the window a task may sit in is
        // still the whole of a partial day's date - but the label says which
        // schedule that date came from.
        $scheduleRangesLabel = $project->schedules->map(fn($schedule) => $schedule->describe())->join('; ');
        // Which days a task may start and be due on. Not the outer period: the
        // gap between two visits is not a day this project exists on, and the
        // pickers grey those days out.
        $taskDateHint = app(\App\Services\TaskScheduleRules::class)->describeSelectable($scheduleRanges->all());
    @endphp
    {{-- `project-details-page` is what applies the brand blue from the
         client's own project page; the layout below is unchanged. --}}
    <div class="container-fluid py-4 project-details-page">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="fw-bold text-brand-blue">Project Details</h2>

            <a href="{{ route('super-admin.projects') }}" class="btn btn-outline-secondary">
                Back to Projects
            </a>
        </div>

        {{-- Waiting on the client. The work is done and the project is locked;
             the only move left is reopening it onto a new schedule, and that
             belongs to an administrator. --}}
        @if ($project->isAwaitingClientConfirmation())
            <div class="alert alert-success border-0 shadow-sm mb-4" role="alert">
                <div class="d-flex flex-wrap align-items-start gap-3">
                    <div class="fs-3 lh-1">
                        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                    </div>

                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-1">Awaiting client confirmation</h5>
                        {{-- Built as one sentence rather than as fragments with
                             punctuation between them: Blade puts a newline where
                             each directive was, and the browser renders that as a
                             space, which is what strands a comma from its word. --}}
                        @php
                            $requestedBy = $project->completionRequestedByUser?->fullName();
                            $sentence = 'The work was recorded as finished on '
                                . ($project->completed_at?->format('F j, Y') ?? 'an earlier date') . '.';

                            if ($project->completion_requested_at) {
                                $sentence .= ' Sent for confirmation on '
                                    . $project->completion_requested_at->format('F j, Y')
                                    . ($requestedBy ? ' by ' . $requestedBy : '') . '.';
                            }
                        @endphp

                        <p class="mb-2">
                            {{ $sentence }} The client has been emailed and notified.
                        </p>

                        <p class="mb-2">
                            @if ($project->confirmationDeadline())
                                It completes automatically on
                                <strong>{{ $project->confirmationDeadline()->format('F j, Y') }}</strong>
                                ({{ $project->confirmationCountdown() }}) if the client does not reply.
                            @endif
                            This project is locked and cannot be edited while it is waiting.
                        </p>

                        @if ($canReopen)
                            <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal"
                                data-bs-target="#reopenProjectModal">
                                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                                Reopen Project
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Reopening. Deliberately not a status dropdown: the dates released
             when completion was requested are gone for good and may already
             have been taken by other work, so resuming a project means saying
             when the remaining work actually happens. The reason is required
             because "why is this project open again?" is the first thing
             anybody reading the audit trail will ask. --}}
        @if ($canReopen)
            @php
                $partialDayAllowed = $project->isResidential();
                $today = \App\Models\Schedule::businessToday()->format('Y-m-d');
            @endphp

            <div class="modal fade" id="reopenProjectModal" tabindex="-1" aria-labelledby="reopenProjectModalLabel"
                aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('super-admin.projects.reopen', $project->project_id) }}"
                            data-reopen-form>
                            @csrf

                            <div class="modal-header bg-dark text-white">
                                <h5 class="modal-title" id="reopenProjectModalLabel">
                                    <i class="bi bi-arrow-counterclockwise me-2" aria-hidden="true"></i>
                                    Reopen Project &mdash; {{ $project->reference_no }}
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                                <div class="mb-3">
                                    <span class="form-label fw-semibold d-block mb-1">Current status</span>
                                    <x-project-status-badge :project="$project" />
                                </div>

                                <div class="alert alert-secondary small mb-4">
                                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                                    The dates released when this project was completed were freed for other work and
                                    are not restored. Book the remaining work below; the project becomes
                                    <strong>Ongoing</strong> and editable again.
                                    @if ($project->schedules->isNotEmpty())
                                        Its recorded work
                                        ({{ $project->schedules->map(fn($schedule) => $schedule->describe())->join('; ') }})
                                        is kept as history.
                                    @endif
                                </div>

                                <h6 class="fw-bold mb-2">New schedule</h6>

                                @if ($partialDayAllowed)
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold" for="reopenSchedulingMode">
                                            Scheduling mode
                                        </label>
                                        <select class="form-select" id="reopenSchedulingMode" name="scheduling_mode"
                                            data-reopen-mode>
                                            <option value="{{ \App\Models\Schedule::MODE_DATE_BASED }}">Date-Based
                                            </option>
                                            <option value="{{ \App\Models\Schedule::MODE_PARTIAL_DAY }}">Partial Day
                                            </option>
                                        </select>
                                    </div>
                                @else
                                    {{-- Partial days are a Residential offering, so a Commercial
                                         project simply books whole days, exactly as elsewhere. --}}
                                    <input type="hidden" name="scheduling_mode"
                                        value="{{ \App\Models\Schedule::MODE_DATE_BASED }}">
                                @endif

                                {{-- The two field groups. The one not in use is hidden AND
                                     disabled, so the browser neither validates nor submits it -
                                     the same rule the schedules page follows. --}}
                                <div class="row g-3" data-reopen-date-based>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="reopenStartDate">Start date</label>
                                        <input type="date" class="form-control" id="reopenStartDate" name="start_date"
                                            min="{{ $today }}" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="reopenEndDate">End date</label>
                                        <input type="date" class="form-control" id="reopenEndDate" name="end_date"
                                            min="{{ $today }}" required>
                                    </div>
                                </div>

                                @if ($partialDayAllowed)
                                    <div class="row g-3" data-reopen-partial-day hidden>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold" for="reopenProjectDate">Date</label>
                                            <input type="date" class="form-control" id="reopenProjectDate"
                                                name="project_date" min="{{ $today }}" disabled>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold" for="reopenStartTime">Start
                                                time</label>
                                            <select class="form-select" id="reopenStartTime" name="start_time" disabled>
                                                <option value="">Select</option>
                                                @foreach ($workingHours as $hour)
                                                    <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold" for="reopenEndTime">End time</label>
                                            <select class="form-select" id="reopenEndTime" name="end_time" disabled>
                                                <option value="">Select</option>
                                                @foreach ($workingHours as $hour)
                                                    <option value="{{ $hour['value'] }}">{{ $hour['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                @endif

                                @if ($project->projectTechnicians->isNotEmpty())
                                    <div class="form-text mt-2">
                                        The assigned team
                                        ({{ $project->projectTechnicians->map(fn($assignment) => $assignment->technician?->name)->filter()->join(', ') }})
                                        is booked onto these dates. Anyone already committed elsewhere will be
                                        reported and the reopen refused.
                                    </div>
                                @endif

                                <hr class="my-4">

                                <div class="mb-1">
                                    <label class="form-label fw-semibold" for="reopenReason">
                                        Reason for reopening
                                    </label>
                                    <textarea class="form-control" id="reopenReason" name="reopen_reason" rows="3" minlength="10"
                                        maxlength="500" required placeholder="e.g. Additional installation work is required."></textarea>
                                    <div class="form-text">
                                        Recorded in the activity log and shown to the client. At least 10 characters.
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-dark">
                                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                                    Reopen &amp; Schedule
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            @if ($partialDayAllowed)
                @push('scripts')
                    <script>
                        // Swapping the mode swaps which fields are live. Disabling the
                        // hidden group is what stops the browser demanding a start date
                        // nobody can see, and stops it being submitted alongside the
                        // hours - the server would then have two readings to choose from.
                        document.addEventListener('DOMContentLoaded', function() {
                            const form = document.querySelector('[data-reopen-form]');
                            const mode = form && form.querySelector('[data-reopen-mode]');

                            if (!form || !mode) {
                                return;
                            }

                            const groups = {
                                date_based: form.querySelector('[data-reopen-date-based]'),
                                partial_day: form.querySelector('[data-reopen-partial-day]'),
                            };

                            function apply() {
                                Object.keys(groups).forEach(function(key) {
                                    const group = groups[key];

                                    if (!group) {
                                        return;
                                    }

                                    const active = mode.value === key;

                                    group.hidden = !active;

                                    group.querySelectorAll('input, select').forEach(function(field) {
                                        field.disabled = !active;
                                        field.required = active;
                                    });
                                });
                            }

                            mode.addEventListener('change', apply);
                            apply();
                        });
                    </script>
                @endpush
            @endif
        @endif

        {{-- How the project was finally closed. Shown only once it is, and it
             is the one place the difference between "the client agreed" and
             "nobody answered for a week" is visible. --}}
        @if ($project->isCompleted() && $project->completionMethodLabel())
            <div class="alert alert-light border shadow-sm mb-4" role="status">
                <i class="bi bi-check2-circle me-2 text-success" aria-hidden="true"></i>
                <strong>{{ $project->completionMethodLabel() }}</strong>
                @if ($project->client_confirmed_at)
                    on {{ $project->client_confirmed_at->format('F j, Y') }}
                @endif
                . A completed project is a historical record and cannot be reopened.
            </div>
        @endif

        {{-- Overdue: the last scheduled day has passed but the project is
             still open. Offer the only two ways out - extend the schedule, or
             close it off properly. --}}
        @if ($project->isOverdue())
            <div class="alert alert-warning border-0 shadow-sm mb-4 overdue-banner" role="alert">
                <div class="d-flex flex-wrap align-items-start gap-3">
                    <div class="overdue-banner-icon">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    </div>

                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-1">This project is overdue</h5>
                        <p class="mb-2">
                            Its last scheduled day was
                            <strong>{{ $project->scheduleEndsOn()->format('F j, Y') }}</strong>
                            ({{ $project->scheduleEndsOn()->diffForHumans() }}), but the project is still
                            <strong>{{ $project->status }}</strong>.
                            Add a new date range if the work is continuing, or mark the project complete.
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('super-admin.schedules.index', ['openSchedule' => $project->project_id]) }}"
                                class="btn btn-sm btn-primary">
                                <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>
                                Add New Schedule
                            </a>

                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal"
                                data-bs-target="#completeOverdueProjectModal">
                                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                                Mark as Complete
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Same fields as the Projects page completion modal, so both
                 routes into completion collect identical information. --}}
            <div class="modal fade" id="completeOverdueProjectModal" tabindex="-1"
                aria-labelledby="completeOverdueProjectModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <form method="POST"
                            action="{{ route('super-admin.projects.complete', $project->project_id) }}"
                            enctype="multipart/form-data">
                            @csrf

                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title" id="completeOverdueProjectModalLabel">
                                    <i class="bi bi-check-circle me-2"></i>
                                    Complete Project &mdash; {{ $project->reference_no }}
                                </h5>
                                <button type="button" class="btn-close btn-close-white"
                                    data-bs-dismiss="modal"></button>
                            </div>

                            <div class="modal-body">
                                <p class="mb-3">
                                    Mark <strong>{{ $project->reference_no }}</strong> as completed? The schedule,
                                    technicians and task history stay on record, but the project becomes view only.
                                </p>

                                {{-- What the completion rules object to. A lead technician is
                                     refused outright; an administrator may go ahead, but the
                                     reason is written onto the project and into the activity
                                     log, so the decision is never a silent one. --}}
                                @if (! empty($completionBlockers))
                                    <div class="alert alert-warning" role="alert">
                                        <p class="fw-semibold mb-2">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            This project is not ready to be completed
                                        </p>
                                        <ul class="mb-2 ps-3">
                                            @foreach ($completionBlockers as $blocker)
                                                <li>{{ $blocker }}</li>
                                            @endforeach
                                        </ul>
                                        <label class="form-label fw-semibold mb-1" for="completionOverrideReason">
                                            Reason for completing it anyway
                                        </label>
                                        <textarea class="form-control" id="completionOverrideReason"
                                            name="completion_override_reason" rows="2" minlength="10" maxlength="500"
                                            required
                                            placeholder="Why is this being completed with the above outstanding?"></textarea>
                                        <div class="form-text">
                                            Recorded against the project and in the activity log, and the other
                                            administrators are notified.
                                        </div>
                                    </div>
                                @endif

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Completion Date</label>
                                    <input type="date" class="form-control" name="completion_date"
                                        value="{{ now()->format('Y-m-d') }}"
                                        max="{{ now()->format('Y-m-d') }}" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Completion Summary</label>
                                    <textarea class="form-control" name="completion_summary" rows="3"
                                        placeholder="Summarize the work that was completed..." required></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Completion Remarks</label>
                                    <textarea class="form-control" name="completion_remarks" rows="2"
                                        placeholder="Any additional remarks (optional)"></textarea>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label fw-semibold">Upload Completion Photos</label>
                                    <input type="file" class="form-control" name="completion_photos[]"
                                        accept=".jpg,.jpeg,.png" multiple>
                                    <div class="form-text">JPG, JPEG, or PNG. You can select multiple photos.</div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-lg me-1"></i>
                                    Confirm Completion
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        <!-- Project Information -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="d-flex align-items-center mb-2">
                            <h2 class="fw-bold mb-0">
                                {{ $project->clients->first()?->fullname ?? 'N/A' }}
                            </h2>

                            @unless ($isReadOnly)
                                <button class="btn btn-outline-info border btn-sm ms-2" data-bs-toggle="modal"
                                    data-bs-target="#editProjectDetailsModal">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            @endunless
                        </div>
                        <span class="fw-bold me-4 mb-3 project-reference">
                            {{ $project->reference_no }}
                        </span>

                        <div class="text-muted">
                            <span class="me-2">
                                <i class="bi bi-file-earmark-text text-brand-blue"></i>
                                Project ID: {{ $project->displayCode() }}
                            </span>

                            <span>
                                <i class="bi bi-geo-alt text-brand-blue"></i>
                                {{ $project->address }}
                            </span>
                        </div>

                        @php
                            $client = $project->clients->first();
                            $clientTypeClass = match (strtolower($client?->client_type ?? '')) {
                                'residential' => 'bi bi-house-door',
                                'commercial' => 'bi bi-building',
                                default => 'bi bi-person',
                            };
                        @endphp

                        <div class="text-muted">
                            <span>
                                <i class="{{ $clientTypeClass }}"></i>
                                {{ $client?->client_type ?? 'N/A' }}
                            </span>

                            @if (strtolower($client?->client_type ?? '') === 'commercial')
                                <span class="ms-3">
                                    Company:
                                    {{ $project->clients->first()?->company_name ?? 'N/A' }}
                                </span>
                            @endif
                        </div>

                        <div class="text-muted mb-3">
                            <span>
                                <i class="bi bi-telephone"></i>
                                {{ $client?->contact_number ?? 'N/A' }}
                            </span>

                            <span class="ms-3">
                                <i class="bi bi-envelope"></i>
                                {{ $client?->email_address ?? 'N/A' }}
                            </span>
                        </div>

                        @foreach ($project->projectTypes as $type)
                            <span class="badge rounded-pill fs-6 px-3 py-2 project-type-badge">
                                {{ $type->type_name }}
                            </span>
                        @endforeach
                    </div>
                    <div>
                        {{-- The model decides the label and the colour, so this
                             page cannot disagree with the projects table, the
                             calendars or the client's own copy of it. It had its
                             own match() here, which is how a new status ends up
                             reading as "Awaiting_client_confirmation". --}}
                        <x-project-status-badge :project="$project" class="rounded-pill fs-6 px-4 py-3" />
                    </div>

                </div>

                {{-- Shown from the moment completion is requested, not only
                     once the client has signed it off: the client is being
                     asked to review this very report, so everybody has to be
                     able to read it while they decide. --}}
                @if ($project->hasCompletionReport() && ! $project->isCancelled())
                    <hr>
                    <div class="completion-report">
                        <h5 class="fw-bold text-success mb-3">
                            <i class="bi bi-check-circle me-2"></i>
                            Completion Report
                            @unless ($project->isCompleted())
                                <span class="badge bg-secondary align-middle ms-2">Awaiting client confirmation</span>
                            @endunless
                        </h5>

                        <div class="mb-2">
                            <span class="fw-semibold me-2">Completion Date:</span>
                            <span>{{ $project->completed_at ? \Carbon\Carbon::parse($project->completed_at)->format('M d, Y') : 'N/A' }}</span>
                        </div>

                        <div class="mb-2">
                            <span class="fw-semibold d-block">Completion Summary:</span>
                            <p class="mb-0">{{ $project->completion_summary ?? 'N/A' }}</p>
                        </div>

                        @if ($project->completion_remarks)
                            <div class="mb-2">
                                <span class="fw-semibold d-block">Completion Remarks:</span>
                                <p class="mb-0">{{ $project->completion_remarks }}</p>
                            </div>
                        @endif

                        {{-- A project closed over its own completion rules says so on its
                             record, not only in the activity log. What was outstanding is
                             printed as it read at the time - the tasks it names may since
                             have been finished or deleted. --}}
                        @if ($project->completionWasOverridden())
                            <div class="alert alert-warning mt-3 mb-0" role="alert">
                                <p class="fw-semibold mb-2">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Completed over its blockers by
                                    {{ $project->completionOverriddenByUser?->fullName() ?? 'an administrator' }}
                                </p>

                                @if (! empty($project->completion_override_blockers))
                                    <ul class="mb-2 ps-3">
                                        @foreach ($project->completion_override_blockers as $blocker)
                                            <li>{{ $blocker }}</li>
                                        @endforeach
                                    </ul>
                                @endif

                                <span class="fw-semibold d-block">Reason given:</span>
                                <p class="mb-0">{{ $project->completion_override_reason }}</p>
                            </div>
                        @endif

                        @if ($project->completionPhotos->isNotEmpty())
                            <div class="mt-3">
                                <span class="fw-semibold d-block mb-2">Completion Photos:</span>
                                <div class="row g-3">
                                    @foreach ($project->completionPhotos as $photo)
                                        <div class="col-lg-3 col-md-4 col-6">
                                            <a href="{{ asset($photo->photo_path) }}" target="_blank" rel="noopener noreferrer">
                                                <img src="{{ asset($photo->photo_path) }}" class="img-fluid rounded border"
                                                    style="height:170px;width:100%;object-fit:cover;">
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($project->isCancelled())
                    <hr>
                    <div class="cancellation-report">
                        <h5 class="fw-bold text-danger mb-3">
                            <i class="bi bi-x-circle me-2"></i>
                            Cancellation Report
                        </h5>

                        <div class="mb-2">
                            <span class="fw-semibold me-2">Cancellation Date:</span>
                            <span>{{ $project->cancelled_at ? \Carbon\Carbon::parse($project->cancelled_at)->format('M d, Y') : 'N/A' }}</span>
                        </div>

                        <div class="mb-2">
                            <span class="fw-semibold d-block">Cancellation Reason:</span>
                            <p class="mb-0">{{ $project->cancellation_reason ?? 'N/A' }}</p>
                        </div>

                        @if ($project->cancellation_remarks)
                            <div class="mb-2">
                                <span class="fw-semibold d-block">Additional Remarks:</span>
                                <p class="mb-0">{{ $project->cancellation_remarks }}</p>
                            </div>
                        @endif
                    </div>
                @endif

                <hr>

                {{-- A project holds any number of files of each type, so each
                     type is a group of them rather than a single button. The
                     remove action sits with the file it removes; read-only
                     work is a record and carries none. --}}
                @php
                    $documentsByType = $project->documents->groupBy('document_type');
                    $isCommercial = $project->clients->first()?->client_type === 'Commercial';
                    $documentTypes = collect(\App\Models\Document::TYPES)
                        ->reject(fn ($label, $type) => $type === 'contract' && ! $isCommercial);
                @endphp

                <div class="project-document-groups" data-project-documents
                    data-project-id="{{ $project->project_id }}">
                    @foreach ($documentTypes as $type => $label)
                        @php $files = $documentsByType->get($type, collect()); @endphp

                        <div class="project-document-group">
                            {{-- The count is the one number here, so it carries
                                 the yellow; the files themselves are blue,
                                 which is what says they open. --}}
                            <div class="project-document-group-head">
                                <span class="fw-semibold">{{ $label }}</span>
                                @if ($files->isNotEmpty())
                                    <span class="badge project-document-count">{{ $files->count() }}</span>
                                @endif
                            </div>

                            @forelse ($files as $document)
                                <div class="project-document-file" data-document-row="{{ $document->document_id }}">
                                    <a href="{{ asset($document->document_path) }}" target="_blank"
                                        rel="noopener noreferrer" class="project-document-link"
                                        title="{{ $document->document_name }}">
                                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                        <span>{{ $document->document_name }}</span>
                                    </a>

                                    @unless ($isReadOnly)
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                                            data-document-remove="{{ route('super-admin.projects.documents.destroy', ['id' => $project->project_id, 'document' => $document->document_id]) }}"
                                            data-document-label="{{ $document->document_name }}"
                                            title="Remove this file">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                        </button>
                                    @endunless
                                </div>
                            @empty
                                <span class="text-muted small">No {{ strtolower($label) }} uploaded.</span>
                            @endforelse
                        </div>
                    @endforeach
                </div>

                <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-document-error></div>
                <div class="mt-3">
                    <span class="fw-bold me-2">
                        Quotation:
                    </span>
                    <span>
                        ₱ {{ number_format($project->quotation, 2) }}
                    </span>
                </div>
                <div class="mt-3">
                    <span class="fw-bold me-2">
                        Project Description:
                    </span>
                    <p>
                        {{ $project->description ?? 'N/A' }}
                    </p>
                </div>

            </div>
        </div>
        <!-- Team + Date -->
        <div class="row mb-4">

            <!-- Assigned Team -->
            <div class="col-lg-6 mb-3">

                <div class="card shadow-sm h-100">

                    <div class="card-header bg-white d-flex justify-content-between align-items-center">

                        <h4 class="mb-0 fw-bold">
                            Assigned Team
                        </h4>
                        @unless ($isReadOnly)
                            <button class="btn btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#editAssignedTeamModal">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        @endunless

                    </div>

                    <div class="card-body p-0">

                        {{-- Deactivating a technician keeps their bookings rather than
                             handing the dates back silently, so the project has to say
                             who can no longer work it. Derived, so it clears itself when
                             the account is restored or the person is taken off. --}}
                        @if ($project->needsRecrew())
                            <div class="alert alert-warning rounded-0 border-0 border-bottom mb-0" role="alert">
                                <i class="bi bi-person-exclamation me-1"></i>
                                <strong>This team needs attention.</strong>
                                {{ $project->inactiveCrew()->map(fn ($assignment) => $assignment->technician?->name)->filter()->join(', ', ' and ') }}
                                can no longer sign in, but their dates are still booked.
                                Reassign the work or take them off the team.
                            </div>
                        @endif

                        <ul class="list-group list-group-flush">

                            @forelse($project->projectTechnicians as $projectTechnician)
                                @php
                                    $technician = $projectTechnician->technician;
                                @endphp

                                @if ($technician)
                                    <li class="list-group-item d-flex align-items-start gap-3">

                                        <x-user-avatar :user="$technician->account" size="md" />

                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="fw-semibold">{{ $technician->name }}</span>

                                                @if (optional($technician->account)->role === 'lead_technician')
                                                    <span class="badge project-lead-badge">Lead Technician</span>
                                                @else
                                                    <span class="badge bg-secondary">Technician</span>
                                                @endif

                                                @unless ($technician->isAssignable())
                                                    <span class="badge bg-warning text-dark">Account inactive</span>
                                                @endunless
                                            </div>

                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                @forelse ($technician->skills->sortBy('skill_name') as $skill)
                                                    <span class="technician-chip">{{ $skill->skill_name }}</span>
                                                @empty
                                                    <span class="text-muted small">No specialties assigned.</span>
                                                @endforelse
                                            </div>
                                        </div>

                                    </li>
                                @endif

                            @empty

                                <li class="list-group-item text-muted">
                                    No technicians assigned.
                                </li>
                            @endforelse

                        </ul>

                    </div>

                </div>

            </div>

            <!-- Project Schedule -->
            <div class="col-lg-6 mb-3">

                <div class="card shadow-sm h-100">

                    <div class="card-header bg-white d-flex justify-content-between align-items-center">

                        <h4 class="mb-0 fw-bold">
                            Project Schedule
                        </h4>

                        @unless ($isReadOnly)
                            <a href="{{ route('super-admin.schedules.index', ['openSchedule' => $project->project_id]) }}"
                                class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-calendar-week me-1"></i>
                                Update Schedule
                            </a>
                        @endunless

                    </div>

                    <div class="card-body">
                        <div>
                            <strong>Schedules:</strong>
                        </div>
                        <ul>
                            {{-- describe() is the one formatter every screen
                                 shares, so a Partial Day reads as
                                 "Aug 6, 2026 · 8:00 AM - 12:00 PM" here and
                                 everywhere else it appears. --}}
                            @forelse($project->schedules as $schedule)
                                <li class="list-group-item">
                                    <strong>{{ $schedule->describe() }}</strong>
                                    @if ($schedule->isPartialDay())
                                        <span class="badge bg-info text-dark ms-1">Partial Day</span>
                                    @endif
                                </li>
                            @empty
                                <li class="list-group-item text-muted">
                                    No schedule set.
                                </li>
                            @endforelse
                        </ul>

                    </div>

                </div>

            </div>

        </div>

        <!-- Project Activity -->
        <div class="card shadow-sm">

            <div class="card-header bg-white">

                <h4 class="fw-bold mb-0">
                    Project Activity
                </h4>

            </div>

            <div class="card-body">

                <!-- Main Tabs -->
                <ul class="nav nav-tabs mb-4" id="activityTabs">

                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#reports">

                            Technician Reports

                        </button>
                    </li>

                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tasks">

                            Tasks

                        </button>
                    </li>

                </ul>

                <div class="tab-content">

                    <!-- Technician Reports -->
                    <div class="tab-pane fade show active" id="reports">
                        <div class="d-flex justify-content-between align-items-center mb-3">

                            <form method="GET" action="{{ route('super-admin.projects.show', $project->project_id) }}">

                                <select class="form-select" name="report_type" onchange="this.form.submit()">

                                    <option value="" {{ request('report_type') == '' ? 'selected' : '' }}>
                                        All Reports
                                    </option>

                                    <option value="progress" {{ request('report_type') == 'progress' ? 'selected' : '' }}>
                                        Progress Reports
                                    </option>

                                    <option value="incident" {{ request('report_type') == 'incident' ? 'selected' : '' }}>
                                        Incident Reports
                                    </option>

                                </select>

                            </form>

                            <button class="btn btn-primary" data-bs-toggle="modal"
                                data-bs-target="#addTechnicianReportModal">

                                <i class="bi bi-plus-lg me-1"></i>

                                Add Report

                            </button>

                        </div>

                        <!-- Report Card -->
                        @forelse($reports as $report)
                            <div
                                class="card mb-3
    {{ $report->report_type == 'progress' ? 'border-primary bg-primary-subtle' : 'border-danger bg-danger-subtle' }}">

                                <div class="card-header d-flex justify-content-between">

                                    <div>

                                        <span
                                            class="badge
                {{ $report->report_type == 'progress' ? 'bg-primary' : 'bg-danger' }}">

                                            {{ ucfirst($report->report_type) }}

                                        </span>

                                        <h5 class="mt-2 mb-0">
                                            {{ $report->report_title }}
                                        </h5>

                                        {{-- Who filed it, beside their picture.
                                             A report is a person's account of a
                                             visit, so it is signed. --}}
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            @if ($report->submitterAvatarUrl())
                                                <img class="user-avatar user-avatar-xs"
                                                    src="{{ $report->submitterAvatarUrl() }}" alt=""
                                                    loading="lazy">
                                            @endif
                                            <small class="text-muted">
                                                by {{ $report->submitterName() }}
                                            </small>
                                        </div>

                                    </div>

                                    <small class="text-muted">

                                        {{ \Carbon\Carbon::parse($report->report_date)->format('M d, Y') }}

                                    </small>

                                </div>

                                <div class="card-body">

                                    <p>
                                        {{ $report->report_description }}
                                    </p>

                                    @if ($report->images->count())
                                        <h6>Pictures</h6>

                                        <div class="row g-3">

                                            @foreach ($report->images as $image)
                                                <div class="col-lg-3 col-md-4 col-6">

                                                    <a href="{{ asset('storage/' . $image->image_path) }}"
                                                        target="_blank">

                                                        <img src="{{ asset('storage/' . $image->image_path) }}"
                                                            class="img-fluid rounded border"
                                                            style="height:170px;width:100%;object-fit:cover;">

                                                    </a>

                                                </div>
                                            @endforeach

                                        </div>
                                    @endif

                                </div>

                            </div>

                        @empty

                            <div class="alert alert-info">

                                No technician reports found.

                            </div>
                        @endforelse
                    </div>

                    <!-- Tasks -->
                    <div class="tab-pane fade" id="tasks">
                        <div class="d-flex justify-content-between align-items-center mb-3">

                            <h5 class="mb-0 fw-bold">
                                Task List
                            </h5>

                            @unless ($isReadOnly || ! $hasSchedule)
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                                    <i class="bi bi-plus-lg me-1"></i>
                                    Add Task
                                </button>
                            @endunless

                        </div>

                        <div class="table-responsive">

                            <table id="tasksTable" class="table table-hover table-striped align-middle mb-0">

                                <thead class="table-info">

                                    <tr>

                                        <th>Task</th>
                                        <th>Assigned To</th>
                                        <th>Start Date</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    @foreach ($tasks as $task)
                                        <tr data-status="{{ $task->status }}">

                                            <td>
                                                <div class="fw-semibold">
                                                    {{ $task->task_title }}
                                                </div>

                                                <small class="text-muted">
                                                    {{ \Illuminate\Support\Str::limit($task->task_description, 60) }}
                                                </small>
                                            </td>

                                            <td>
                                                {{ optional($task->technician)->name ?? 'Unassigned' }}
                                            </td>

                                            <td>
                                                {{ $task->start_date ? \Carbon\Carbon::parse($task->start_date)->format('M d, Y') : 'Unassigned' }}
                                            </td>

                                            <td>
                                                {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('M d, Y') : 'Unassigned' }}
                                            </td>

                                            <td>
                                                @switch($task->status)
                                                    @case('unassigned')
                                                        <span class="badge bg-warning text-dark">Unassigned</span>
                                                    @break

                                                    @case('pending')
                                                        <span class="badge bg-secondary">Pending</span>
                                                    @break

                                                    @case('ongoing')
                                                        <span class="badge bg-primary">Ongoing</span>
                                                    @break

                                                    @case('completed')
                                                        <span class="badge bg-success">Completed</span>
                                                    @break

                                                    @case('cancelled')
                                                        <span class="badge bg-danger">Cancelled</span>
                                                    @break
                                                @endswitch
                                            </td>

                                            <td class="text-start">

                                                <div class="d-flex justify-content-start gap-2">

                                                    {{-- View / Edit. A completed task keeps the eye
                                                         but opens view only, showing what was
                                                         submitted on completion. --}}
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#taskModal{{ $task->task_id }}"
                                                        title="{{ $task->isCompleted() || $isReadOnly ? 'View task' : 'View / edit task' }}">
                                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                                    </button>

                                                    {{-- A locked project's task history is a record,
                                                         so completing and deleting both drop away. --}}
                                                    @unless ($isReadOnly)
                                                        @if ($task->status != 'completed' && $task->status != 'unassigned')
                                                            <button type="button" class="btn btn-sm btn-success"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#completeTaskModal{{ $task->task_id }}"
                                                                title="Mark as completed">
                                                                <i class="bi bi-check-lg" aria-hidden="true"></i>
                                                            </button>
                                                        @endif

                                                        <button type="button" class="btn btn-sm btn-danger"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#deleteTaskModal{{ $task->task_id }}"
                                                            title="Delete task">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    @endunless

                                                </div>

                                            </td>

                                        </tr>
                                    @endforeach

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        </div>
    </div>

    @unless ($isReadOnly)
        <div class="d-flex justify-content-end mt-3 mb-4">
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelProjectModal">
                <i class="bi bi-x-circle me-1"></i>
                Cancel Project
            </button>
        </div>

        <!-- CANCEL PROJECT MODAL -->
        <div class="modal fade" id="cancelProjectModal" tabindex="-1" aria-labelledby="cancelProjectModalLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">

                    <form method="POST" action="{{ route('super-admin.projects.cancel', $project->project_id) }}">
                        @csrf

                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="cancelProjectModalLabel">
                                <i class="bi bi-x-circle me-2"></i>
                                Cancel Project
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <p class="mb-3">
                                Cancelling <strong>{{ $project->reference_no }}</strong> will remove its schedule and
                                assigned technicians, freeing them up for other projects. This action cannot be undone.
                            </p>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Cancellation Date</label>
                                <input type="date" class="form-control" name="cancellation_date"
                                    value="{{ now()->format('Y-m-d') }}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Cancellation Reason</label>
                                <input type="text" class="form-control" name="cancellation_reason"
                                    placeholder="Reason for cancellation" required>
                            </div>

                            <div class="mb-1">
                                <label class="form-label fw-semibold">Additional Remarks</label>
                                <textarea class="form-control" name="cancellation_remarks" rows="2"
                                    placeholder="Optional remarks"></textarea>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Close
                            </button>
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-x-circle me-1"></i>
                                Confirm Cancellation
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    @endunless

    <!-- EDIT PROJECT DETAILS MODAL -->
    <div class="modal fade" id="editProjectDetailsModal" tabindex="-1" aria-labelledby="editProjectDetailsModalLabel"
        aria-hidden="true">

        <div class="modal-dialog modal-xl modal-dialog-centered" style="max-height: calc(100vh - 3rem);">

            <div class="modal-content border-0 shadow d-flex flex-column" style="max-height: calc(100vh - 3rem);">

                <div class="modal-header edit-project-header">
                    <div>
                        <span class="edit-project-eyebrow">
                            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>
                            Editing {{ $project->reference_no }}
                        </span>
                        <h5 class="modal-title fw-bold mb-0" id="editProjectDetailsModalLabel">
                            {{ $project->name }}
                        </h5>
                    </div>

                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <form class="d-flex flex-column flex-grow-1 overflow-hidden"
                    action="{{ route('super-admin.projects.update', $project->project_id) }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="modal-body flex-grow-1 overflow-auto edit-project-body">

                        <!-- Client Information -->
                        <section class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-person-circle" aria-hidden="true"></i>
                            Client Information
                        </h6>

                        <div class="row g-3">

                            <div class="col-md-5">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name"
                                    value="{{ $project->clients->first()?->firstname ?? '' }}">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Middle Initial</label>
                                <input type="text" maxlength="1" class="form-control text-center"
                                    name="middle_initial" value="{{ $project->clients->first()?->middlename ?? '' }}">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name"
                                    value="{{ $project->clients->first()?->surname ?? '' }}">
                            </div>

                        </div>

                        @if ($project->clients->first()?->client_type === 'Commercial')
                            <div class="mt-3">
                                <label class="form-label">Company Name</label>

                                <input type="text" class="form-control" name="company_name"
                                    value="{{ $project->clients->first()?->company_name ?? '' }}">
                            </div>
                        @endif


                        <div class="mt-4">

                            <label class="form-label">Address</label>

                            <input type="text" class="form-control" name="address" value="{{ $project->address }}">

                        </div>

                        <div class="row g-3 mt-1">

                            <div class="col-md-6">
                                <label class="form-label">
                                    Contact Number
                                </label>

                                <input type="text" class="form-control" name="contact_number"
                                    value="{{ $project->clients->first()?->contact_number ?? '' }}">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Email Address
                                </label>

                                <input type="email" class="form-control" name="email_address"
                                    value="{{ $project->clients->first()?->email_address ?? '' }}">
                            </div>

                        </div>
                        </section>

                        <!-- Project Information -->
                        <section class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-folder2-open" aria-hidden="true"></i>
                            Project Information
                        </h6>
                        <div class="mb-4">

                            <label class="form-label fw-semibold">
                                Project Types
                            </label>

                            <div class="dropdown mb-3">

                                <button class="btn btn-outline-primary dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown">

                                    <i class="bi bi-plus-lg me-1"></i>
                                    Add Project Type

                                </button>

                                <ul class="dropdown-menu">

                                    @foreach ($projectTypes->reject(fn($type) => $project->projectTypes->contains('type_id', $type->type_id)) as $type)
                                        <li>
                                            <button type="button" class="dropdown-item add-project-type"
                                                data-type-id="{{ $type->type_id }}"
                                                data-type-name="{{ $type->type_name }}">

                                                {{ $type->type_name }}

                                            </button>
                                        </li>
                                    @endforeach

                                </ul>

                            </div>

                            <div id="projectTypesContainer" class="d-flex flex-wrap gap-2 mb-3">

                                @foreach ($project->projectTypes as $type)
                                    <span class="badge bg-primary d-flex align-items-center px-3 py-2"
                                        data-type-id="{{ $type->type_id }}">

                                        {{ $type->type_name }}

                                        <button type="button" class="btn-close btn-close-white ms-2 remove-project-type"
                                            data-type-id="{{ $type->type_id }}" aria-label="Remove">
                                        </button>

                                    </span>
                                @endforeach

                            </div>



                            <!-- Hidden inputs submitted with the form -->
                            <div id="projectTypesInputs">

                                @foreach ($project->projectTypes as $type)
                                    <input type="hidden" name="project_types[]" value="{{ $type->type_id }}"
                                        data-type-id="{{ $type->type_id }}">
                                @endforeach

                            </div>

                        </div>


                        <div class="mb-4">

                            <label class="form-label">
                                Quotation
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    ₱
                                </span>

                                <input type="number" class="form-control" name="quotation"
                                    value="{{ $project->quotation }}">

                            </div>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Project Description
                            </label>

                            <textarea class="form-control" rows="2" name="project_description">{{ $project->description }}</textarea>

                        </div>
                        </section>

                        <!-- Documents -->
                        <section class="edit-section">
                        <h6 class="edit-section-title">
                            <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i>
                            Update Documents
                        </h6>

                        {{-- Said once for all three rather than repeated under
                             each: it is the same rule every time. --}}
                        <p class="edit-section-note">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span>
                                {{ \App\Models\Document::ALLOWED_LABEL }}, up to
                                {{ \App\Models\Document::MAX_LABEL }} each. You may pick several at once, and
                                anything you pick is <strong>added</strong> to what the project already holds &mdash;
                                remove a file from the list on the page behind this dialog.
                            </span>
                        </p>

                        @php
                            $uploadFields = [
                                'assessment' => ['label' => 'Assessment', 'field' => 'assessmentDocument'],
                                'quotation' => ['label' => 'Quotation', 'field' => 'quotationDocument'],
                            ];

                            if ($project->clients->first()?->client_type === 'Commercial') {
                                $uploadFields['contract'] = ['label' => 'Contract', 'field' => 'contractDocument'];
                            }
                        @endphp

                        <div class="row g-3">
                            @foreach ($uploadFields as $type => $upload)
                                @php $held = $documentsByType->get($type, collect())->count(); @endphp

                                <div class="col-md-4">
                                    <div class="edit-document-card h-100">

                                        <div class="edit-document-head">
                                            <span class="fw-semibold">{{ $upload['label'] }}</span>

                                            {{-- What the project holds now, so it
                                                 is clear these are added to. --}}
                                            @if ($held)
                                                <span class="badge project-document-count">{{ $held }} on file</span>
                                            @else
                                                <span class="edit-document-none">None yet</span>
                                            @endif
                                        </div>

                                        <input type="file" class="form-control form-control-sm"
                                            name="{{ $upload['field'] }}[]"
                                            accept="{{ \App\Models\Document::ACCEPT_ATTRIBUTE }}" multiple
                                            data-upload-input>

                                        {{-- Filled in by projectDetails.js once
                                             files are chosen, so what is about to
                                             be uploaded is visible before saving. --}}
                                        <ul class="edit-document-picked d-none" data-picked-list></ul>

                                    </div>
                                </div>
                            @endforeach
                        </div>
                        </section>

                    </div>

                    <div class="modal-footer edit-project-footer">

                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                            Save Changes
                        </button>

                    </div>

                </form>

            </div>

        </div>
    </div>

    <!-- END OF EDIT PROJECT DETAILS MODAL -->
    <!-- EDIT ASSIGNED TEAM MODAL -->
    <div class="modal fade" id="editAssignedTeamModal" tabindex="-1" aria-labelledby="editAssignedTeamModalLabel"
        aria-hidden="true">

        <div class="modal-dialog modal-lg">

            <form action="{{ route('super-admin.projects.team.update', $project->project_id) }}" method="POST"
                data-team-form>

                @csrf
                @method('PUT')

                <div class="modal-content">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="editAssignedTeamModalLabel">
                            <i class="bi bi-people me-2"></i>
                            Edit Assigned Team
                        </h5>

                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <div class="mb-4">
                            <label for="editLeadTech" class="form-label fw-bold">
                                Lead Technician <span class="text-danger">*</span>
                            </label>

                            @php
                                // Same three buckets the technician picker uses: skills
                                // that match the project types first, then everyone else
                                // who is free, then the booked ones - shown so the
                                // scheduler knows why, but not choosable.
                                $leadGroups = [
                                    'Suggested — matches this project' => $leadTechnicianOptions->where('suggested', true),
                                    'Other available' => $leadTechnicianOptions->where('suggested', false)->where('available', true),
                                    'Unavailable for these dates' => $leadTechnicianOptions->where('available', false),
                                ];
                            @endphp

                            <select class="form-select" id="editLeadTech" name="lead_tech" required
                                data-lead-tech-select>
                                <option value="" disabled {{ $currentLeadTechnicianId ? '' : 'selected' }}>
                                    Select lead technician
                                </option>

                                @foreach ($leadGroups as $groupLabel => $groupOptions)
                                    @continue($groupOptions->isEmpty())

                                    <optgroup label="{{ $groupLabel }}">
                                        @foreach ($groupOptions as $candidate)
                                            <option value="{{ $candidate['id'] }}"
                                                @disabled(!$candidate['selectable'])
                                                {{ (string) $candidate['id'] === (string) $currentLeadTechnicianId ? 'selected' : '' }}>
                                                {{ $candidate['name'] }}@if ($candidate['matched_skills'])
                                                    — {{ implode(', ', $candidate['matched_skills']) }}
                                                @endif
                                                @if (!$candidate['available'])
                                                    ({{ $candidate['reason'] }})
                                                @endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>

                            <div class="form-text text-danger d-none" data-lead-tech-error>
                                A lead technician is required.
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label fw-bold mb-0">Technicians</label>

                            {{-- Copies a team from another project into the
                                 picker below, which stays exactly as editable
                                 as it is when people are chosen by hand.

                                 Opened from JavaScript rather than by
                                 data-bs-toggle: Bootstrap's data API replaces
                                 the modal it is triggered from, which would
                                 close this editor and drop the person back on
                                 the page. The two are handed over explicitly
                                 instead, and this dialog comes back when the
                                 import one closes. --}}
                            <button type="button" class="btn btn-sm btn-outline-primary" data-import-team-open>
                                <i class="bi bi-people me-1" aria-hidden="true"></i>
                                Import Team
                            </button>
                        </div>

                        <div class="technician-picker" data-technician-picker>
                            <div class="dropdown w-100">
                                {{-- Closing only on an outside click keeps the menu up while
                                     the unavailable section is expanded. --}}
                                <button type="button" class="form-select technician-dropdown-toggle text-start"
                                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                                    data-technician-dropdown-button>
                                    Select technicians
                                </button>

                                <ul class="dropdown-menu w-100 technician-dropdown-menu"
                                    data-technician-dropdown-menu></ul>
                            </div>

                            <div class="technician-selected-list mt-3" data-technician-selected-list></div>
                            <div class="technician-hidden-inputs" data-technician-hidden-inputs></div>
                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Close
                        </button>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>
                            Save Changes
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>
    <!-- END OF EDIT ASSIGNED TEAM MODAL -->

    <x-import-team-modal />

    @push('scripts')
        <script>
            window.assignedTeamData = @json($assignedTeamLookup);
            window.assignedTeamState = @json([
                'leadTechId' => $currentLeadTechnicianId,
                'technicianIds' => $currentTeamTechnicianIds,
            ]);
        </script>
    @endpush

    <!-- Add Technician Report Modal -->
    <div class="modal fade" id="addTechnicianReportModal" tabindex="-1" aria-labelledby="addTechnicianReportModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form action="{{ route('super-admin.technician.reports.store', $project->project_id) }}" method="POST"
                enctype="multipart/form-data">
                @csrf

                <div class="modal-content">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="addTechnicianReportModalLabel">
                            <i class="fas fa-file-alt me-2"></i>
                            Add Technician Report
                        </h5>

                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <!-- Report Type -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Report Type
                            </label>

                            <select class="form-select" name="report_type" required>
                                <option value="">Select Report Type</option>
                                <option value="progress">Progress Report</option>
                                <option value="incident">Incident Report</option>
                            </select>
                        </div>

                        <!-- Report Title -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Report Title
                            </label>

                            <input type="text" class="form-control" name="report_title"
                                placeholder="Enter report title" required>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Report Description
                            </label>

                            <textarea class="form-control" name="report_description" rows="5"
                                placeholder="Describe the work completed or incident..." required></textarea>
                        </div>

                        <!-- Upload Images -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Upload Images
                            </label>

                            <input type="file" class="form-control" name="images[]" id="reportImages"
                                accept="image/*" multiple required>

                            <small class="text-muted">
                                You may upload multiple images (JPG, PNG, JPEG).
                            </small>
                        </div>

                        <!-- Image Preview -->
                        <div class="row g-2" id="imagePreview"></div>

                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-save me-1"></i>
                            Submit Report
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>
    <!-- End of Add Technician Report Modal -->

    <!-- Add Task Modal -->
    <div class="modal fade" id="addTaskModal" tabindex="-1" aria-labelledby="addTaskModalLabel" aria-hidden="true">

        <div class="modal-dialog modal-xl">

            <form action="{{ route('super-admin.task.store', $project->project_id) }}" method="POST">
                @csrf

                <div class="modal-content">

                    <div class="modal-header bg-primary text-white">

                        <h5 class="modal-title">
                            <i class="bi bi-list-task me-2"></i>
                            Create Task
                        </h5>

                        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

                    </div>

                    <div class="modal-body">

                        <!-- Task Title -->
                        <div class="mb-3">

                            <label class="form-label fw-semibold">
                                Task Title
                            </label>

                            <input type="text" class="form-control" name="task_title" placeholder="Enter task title"
                                required>

                        </div>

                        <!-- Description -->
                        <div class="mb-4">

                            <label class="form-label fw-semibold">
                                Task Description
                            </label>

                            <textarea class="form-control" name="task_description" rows="4" placeholder="Describe the task..." required></textarea>

                        </div>

                        <!-- Dates -->
                        <div class="row" data-task-date-row data-schedule-ranges='@json($scheduleRanges)'>

                            <div class="col-md-6 mb-3">

                                <label class="form-label fw-semibold">
                                    Start Date
                                </label>

                                <input type="text" id="taskStartDate" name="start_date" class="form-control"
                                    data-task-start placeholder="Select start date" required>

                            </div>

                            <div class="col-md-6 mb-3">

                                <label class="form-label fw-semibold">
                                    Due Date
                                </label>

                                <input type="text" id="taskDueDate" name="due_date" class="form-control"
                                    data-task-due placeholder="Select due date" required>

                            </div>

                            <div class="col-12 mb-3">
                                <div class="form-text">
                                    {{ $taskDateHint }}
                                </div>
                            </div>

                        </div>

                        <hr>

                        <label class="form-label fw-bold mb-3">
                            Assign To
                        </label>

                        {{-- The same picker cards the Tasks page and the technician
                             portal use, so an assignee is chosen the same way
                             everywhere. --}}
                        <div class="task-assign-row">

                            @foreach ($project->projectTechnicians as $projectTechnician)
                                @php
                                    $technician = $projectTechnician->technician;
                                    $activeCount = $technicianActiveTaskCounts[$technician->technician_id] ?? 0;
                                @endphp

                                <label>
                                    <input type="radio" class="btn-check" name="technician_id"
                                        value="{{ $technician->technician_id }}" required>

                                    <div class="task-assign-card">
                                        <x-user-avatar :user="$technician->account" size="lg"
                                            class="task-assign-avatar" />
                                        <div class="task-assign-name">{{ $technician->name }}</div>
                                        <div class="task-assign-count">
                                            {{ $activeCount }}
                                            Active Task{{ $activeCount == 1 ? '' : 's' }}
                                        </div>
                                        @if (optional($technician->account)->role === 'lead_technician')
                                            <span class="badge bg-primary task-assign-lead">Lead</span>
                                        @endif
                                    </div>
                                </label>
                            @endforeach

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                            Cancel

                        </button>

                        <button type="submit" class="btn btn-primary">

                            <i class="bi bi-check-lg me-1"></i>

                            Create Task

                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>

    @php
        $projectTechnicianModels = $project->projectTechnicians->pluck('technician')->filter()->values();
    @endphp

    @foreach ($tasks as $task)
        {{-- Shared with the Tasks page and the technician portal. --}}
        <x-task-details-modal :task="$task" :technicians="$projectTechnicianModels"
            :active-task-counts="$technicianActiveTaskCounts" :schedule-ranges="$scheduleRanges"
            :update-action="$isReadOnly ? null : route('super-admin.tasks.update', $task->task_id)" />

        @unless ($isReadOnly)
            @if ($task->status != 'completed' && $task->status != 'unassigned')
                <x-task-complete-modal :task="$task"
                    :action="route('super-admin.tasks.complete', $task->task_id)" />
            @endif

            <x-task-delete-modal :task="$task"
                :action="route('super-admin.tasks.destroy', $task->task_id)" />
        @endunless
    @endforeach



    @push('scripts')
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        {{-- Same range-aware task date pickers the Tasks page uses. --}}
        <script src="/js/super-admin/taskDatePickers.js"></script>
        <script src="/js/imagePreview.js"></script>
        <script src="/js/importTeam.js"></script>
        <script src="/js/super-admin/projectDetails.js"></script>
        <script>
            window.assignedTeamData = @json($assignedTeamLookup);
            window.assignedTeamState = @json([
                'leadTechId' => $currentLeadTechnicianId,
                'technicianIds' => $currentTeamTechnicianIds,
            ]);
            window.importTeamProjectId = @json($project->project_id);
        </script>
    @endpush
@endsection
