@props([
    'project',
    // Staff see who made each change; a client sees the change and the reason.
    'showActor' => true,
])

{{--
    Every value a project's target completion date has held, newest first.

    Read-only, and laid out as the Quotation History dialog is. The oldest
    entry is the date the project was created with; every later one is a
    change and carries its reason.
--}}
@php
    $entries = $project->targetDateHistory;
@endphp

@once
    @push('styles')
        <link rel="stylesheet" href="/css/targetDateHistory.css">
    @endpush
@endonce

<div class="modal fade" id="targetDateHistoryModal" tabindex="-1" aria-labelledby="targetDateHistoryModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="targetDateHistoryModalLabel">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>
                    Target Date History &mdash; {{ $showActor ? ($project->reference_no ?? $project->name) : $project->name }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <div class="target-date-history-current">
                    <span class="target-date-history-eyebrow">Current target</span>
                    <span class="fw-semibold fs-5">
                        {{ \App\Services\TargetDateChange::format($project->target_end_date) }}
                    </span>
                </div>

                <h6 class="target-date-history-heading">
                    <i class="bi bi-calendar-check" aria-hidden="true"></i>
                    Date changes
                </h6>

                <div class="table-responsive">
                    <table class="table table-sm align-middle target-date-history-table mb-0">
                        <thead>
                            <tr>
                                <th scope="col">When</th>
                                @if ($showActor)
                                    <th scope="col">Changed by</th>
                                @endif
                                <th scope="col">Change</th>
                                <th scope="col">Target date</th>
                                <th scope="col">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr data-target-date-history-row>
                                    <td class="text-nowrap">
                                        {{ \App\Support\BusinessTime::format($entry->created_at, \App\Support\BusinessTime::DATE_TIME) }}
                                    </td>
                                    @if ($showActor)
                                        <td>
                                            {{ $entry->actor_name }}
                                            @if ($entry->actor_role)
                                                <span class="d-block small text-muted">
                                                    {{ \App\Models\User::ROLES[$entry->actor_role] ?? ucwords(str_replace('_', ' ', $entry->actor_role)) }}
                                                </span>
                                            @endif
                                        </td>
                                    @endif
                                    <td>
                                        @if ($entry->isInitial())
                                            <span class="target-date-history-badge is-set">Set</span>
                                        @else
                                            <span class="target-date-history-badge is-changed">Changed</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        @unless ($entry->isInitial())
                                            <span class="text-muted">{{ \App\Services\TargetDateChange::format($entry->previous_date) }}</span>
                                            <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i>
                                            <span class="visually-hidden">to</span>
                                        @endunless
                                        <span class="fw-semibold">{{ \App\Services\TargetDateChange::format($entry->new_date) }}</span>
                                    </td>
                                    <td class="target-date-history-reason">
                                        @if ($entry->reason)
                                            {{ $entry->reason }}
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
