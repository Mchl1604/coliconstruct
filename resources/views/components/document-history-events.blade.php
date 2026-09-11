@props([
    'events',
    'label',
])

{{--
    One document type's file changes, newest first: every file uploaded,
    replaced or removed, who did it and when.

    A file that still exists opens from here - including a replaced quotation,
    which is kept. A removed file was deleted, so its entry names it and says
    so rather than offering a link to nothing.
--}}
@if ($events->isEmpty())
    <p class="text-muted small mb-0">No {{ strtolower($label) }} file has been uploaded, replaced or removed yet.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm align-middle document-history-table mb-0">
            <thead>
                {{-- What happened comes first: it is the column a reader
                     scans, and on a narrow screen the last columns are the
                     ones that scroll out of view. --}}
                <tr>
                    <th scope="col">Change</th>
                    <th scope="col">File</th>
                    <th scope="col">When</th>
                    <th scope="col">By</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $event)
                    <tr data-document-history-row data-event="{{ $event->event }}">
                        <td>
                            <span class="document-history-badge is-{{ $event->event }}">
                                {{ $event->eventLabel() }}
                            </span>
                            @if ($event->event === \App\Models\DocumentHistory::EVENT_REPLACED)
                                <span class="d-block small text-muted text-nowrap">By a newer upload</span>
                            @endif
                        </td>
                        <td class="document-history-file">
                            @if ($event->document)
                                <a href="{{ $event->document->url() }}" target="_blank" rel="noopener noreferrer"
                                    class="project-document-link" title="{{ $event->document_name }}">
                                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                    <span>{{ $event->document_name }}</span>
                                </a>
                            @else
                                <span class="document-history-gone" title="{{ $event->document_name }}">
                                    <i class="bi bi-file-earmark-x" aria-hidden="true"></i>
                                    <span>{{ $event->document_name }}</span>
                                </span>
                                <span class="d-block small text-muted">File deleted</span>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            {{ \App\Support\BusinessTime::format($event->created_at, \App\Support\BusinessTime::DATE_TIME) }}
                        </td>
                        <td>
                            {{ $event->actor_name }}
                            @if ($event->actor_role)
                                <span class="d-block small text-muted">
                                    {{ \App\Models\User::ROLES[$event->actor_role] ?? ucwords(str_replace('_', ' ', $event->actor_role)) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
