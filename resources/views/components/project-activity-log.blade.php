@props([
    // A LengthAwarePaginator of ActivityLog rows, already narrowed to this
    // project and to what the reader may see, and already in newest-first
    // order. Read rather than queried here: the filtering is the part that
    // must not be repeated in two places - see ActivityLog::scopeForProject().
    'logs',
])

{{--
    Activity Logs, for one project.

    The same rows, in the same order, as the Activity Logs page on
    Configuration - this is a view onto that trail narrowed to one job, not a
    second record of it. Nothing here writes anything.

    Deliberately plain: date, who, what, a page at a time. The filters, the
    sorting and the export belong to the full page, and duplicating them here
    would be a second thing to keep in step with no reader asking for it.
--}}
<div class="card shadow-sm project-activity-log" id="project-activity-log">

    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">

        <div>
            <h4 class="mb-0 fw-bold">
                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                Activity Logs
            </h4>
            <span class="text-secondary small">
                What has been recorded against this project, newest first.
                @unless (auth()->user()?->isSuperAdmin())
                    Entries by a Super Admin or another Admin are not shown.
                @endunless
            </span>
        </div>

    </div>

    <div class="card-body">

        @if ($logs->total() === 0)

            <div class="project-history-empty">
                No activity recorded for this project.
            </div>

        @elseif ($logs->isEmpty())

            {{-- A page number past the end of the trail - only reachable by
                 typing one. Saying the project has no activity would be
                 untrue, and so would "Page 9 of 1", so this says what is
                 actually the case and offers the way back. --}}
            <div class="project-history-empty">
                <p class="mb-2">There is nothing on this page of the activity log.</p>
                <a href="{{ $logs->url(1) }}" data-workspace-link>Back to the latest entries</a>
            </div>

        @else

            <div class="table-responsive project-activity-log-table">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th scope="col">Date &amp; Time</th>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Action</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="text-nowrap">
                                    {{ $log->created_at?->format(\App\Support\BusinessTime::DATE_TIME) ?? '—' }}
                                </td>
                                {{-- The snapshot columns, not the account they
                                     point at: an entry has to keep naming who
                                     it was at the time. An action taken by a
                                     scheduled job is already stored as
                                     "System", so there is nobody missing to
                                     stand in for. --}}
                                <td class="fw-semibold">{{ $log->actor_name ?: 'System' }}</td>
                                <td>
                                    <span class="badge {{ $log->actorRoleBadgeClass() }}">
                                        {{ $log->actorRoleLabel() }}
                                    </span>
                                </td>
                                <td>{{ $log->action }}</td>
                                <td class="project-activity-log-details">{{ $log->description }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif

        @if ($logs->isNotEmpty())
            {{-- The same summary and the same button group the Activity Logs
                 page draws, in the shared `.table-pagination` shell from
                 theme.css - so the two read the same. Links rather than
                 buttons: this section is server-rendered, and a page of an
                 audit trail should be somewhere a person can return to.

                 `data-workspace-link` has Project Details redraw its content
                 for the next page instead of reloading the application - see
                 projectWorkspace.js. The address still changes with it. --}}
            <nav class="table-pagination" aria-label="Activity log pages" data-project-activity-pagination>

                <span class="table-pagination-summary">
                    Showing {{ $logs->firstItem() ?? 0 }}&ndash;{{ $logs->lastItem() ?? 0 }}
                    of {{ $logs->total() }} {{ $logs->total() === 1 ? 'entry' : 'entries' }}
                </span>

                <div class="btn-group btn-group-sm">
                    @if ($logs->onFirstPage())
                        <button type="button" class="btn btn-outline-secondary" disabled>Previous</button>
                    @else
                        <a href="{{ $logs->previousPageUrl() }}" class="btn btn-outline-secondary"
                            rel="prev" data-workspace-link>Previous</a>
                    @endif

                    <button type="button" class="btn btn-outline-secondary" disabled>
                        Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}
                    </button>

                    @if ($logs->hasMorePages())
                        <a href="{{ $logs->nextPageUrl() }}" class="btn btn-outline-secondary" rel="next"
                            data-workspace-link>Next</a>
                    @else
                        <button type="button" class="btn btn-outline-secondary" disabled>Next</button>
                    @endif
                </div>

            </nav>
        @endif

    </div>

</div>
