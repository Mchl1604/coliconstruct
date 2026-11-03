{{--
    A generated daily progress report, from its snapshot - what the system
    gathered and the lead's remarks. Shared by the lead's preview, before it
    is filed, and by the printable report page after.

    Expects $snapshot (ProgressReportBuilder::gather(), plus remarks once
    filed).
--}}
@php
    $section = fn (string $title) => '<h6 class="report-doc-heading">'.e($title).'</h6>';
@endphp

<div class="report-doc">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div>
            <div class="fw-bold fs-5">@unless (str_contains((string) $snapshot['project']['name'], (string) $snapshot['project']['reference_no'])){{ $snapshot['project']['reference_no'] }} &middot; @endunless{{ $snapshot['project']['name'] }}</div>
            <div class="text-secondary small">{{ $snapshot['project']['client'] }} &middot; {{ $snapshot['project']['address'] }}</div>
        </div>
        <div class="text-end">
            <div class="fw-semibold">{{ $snapshot['date_label'] }}</div>
            <div class="small text-secondary">{{ $snapshot['project']['status'] }}</div>
        </div>
    </div>

    {!! $section('Progress') !!}
    <div class="mb-3">
        <div class="progress mb-1" style="height: .6rem" role="progressbar" aria-label="Phases complete"
            aria-valuenow="{{ $snapshot['phase']['percent'] }}" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: {{ $snapshot['phase']['percent'] }}%"></div>
        </div>
        <div class="small">
            {{ $snapshot['phase']['label'] }} ({{ $snapshot['phase']['percent'] }}%)
            @if ($snapshot['phase']['current'])
                &middot; Current: <span class="fw-semibold">{{ $snapshot['phase']['current'] }}</span>
                ({{ $snapshot['phase']['current_tasks'] }})
            @endif
        </div>
    </div>

    {!! $section('Team on Site') !!}
    <p class="small mb-3">
        @forelse ($snapshot['team'] as $member)
            {{ $member['name'] }}@if ($member['lead']) (Lead)@endif{{ ! $loop->last ? ',' : '' }}
        @empty
            <span class="text-secondary">Nobody on the team this day.</span>
        @endforelse
    </p>

    {!! $section('Task Updates') !!}
    @foreach ($snapshot['in_progress_tasks'] ?? [] as $task)
        <div class="small mb-2">
            <span class="fw-semibold">{{ $task['task'] }}</span>
            <span class="text-secondary">&middot; {{ $task['technician'] }}</span>
            <span class="badge text-bg-info ms-1">In Progress</span>
        </div>
    @endforeach
    @forelse ($snapshot['task_updates'] as $update)
        <div class="small mb-2">
            <span class="fw-semibold">{{ $update['task'] }}</span>
            <span class="text-secondary">&middot; {{ $update['technician'] }}@if ($update['photos']) &middot; {{ $update['photos'] }} photo(s)@endif</span>
            <div style="white-space: pre-line">{{ $update['notes'] }}</div>
        </div>
    @empty
        @if (($snapshot['in_progress_tasks'] ?? []) === [])
            <p class="small text-secondary mb-3">No updates posted.</p>
        @endif
    @endforelse

    <div class="row g-3 mt-1">
        <div class="col-md-6">
            {!! $section('Completed') !!}
            <ul class="small ps-3 mb-0">
                @forelse ($snapshot['completed_tasks'] as $task)
                    <li>{{ $task['task'] }} <span class="text-secondary">&middot; {{ $task['technician'] }}</span></li>
                @empty
                    <li class="text-secondary list-unstyled ms-n3">None.</li>
                @endforelse
            </ul>
        </div>
        <div class="col-md-6">
            {!! $section('Overdue') !!}
            <ul class="small ps-3 mb-0">
                @forelse ($snapshot['overdue_tasks'] as $task)
                    <li>{{ $task['task'] }} <span class="text-danger">&middot; due {{ $task['due'] }}</span></li>
                @empty
                    <li class="text-secondary list-unstyled ms-n3">None.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="mt-3">
        {!! $section('Materials') !!}
        @if ($snapshot['materials'] === [])
            <p class="small text-secondary mb-3">None recorded.</p>
        @else
            <table class="table table-sm small mb-3">
                <thead><tr><th>Material</th><th>Entry</th><th>Quantity</th></tr></thead>
                <tbody>
                    @foreach ($snapshot['materials'] as $entry)
                        <tr><td>{{ $entry['material'] }}</td><td>{{ $entry['type'] }}</td><td>{{ $entry['quantity'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {!! $section('Incidents') !!}
    <p class="small mb-3">
        @forelse ($snapshot['incidents'] as $incident)
            {{ $incident['code'] }} &middot; {{ $incident['title'] }}{{ ! $loop->last ? ';' : '' }}
        @empty
            <span class="text-secondary">None reported.</span>
        @endforelse
    </p>

    @foreach (['remarks' => 'Remarks', 'issues' => 'Issues / Delays', 'next_plan' => 'Plan for Tomorrow'] as $key => $label)
        @if (filled($snapshot[$key] ?? null))
            {!! $section($label) !!}
            <p class="small mb-3" style="white-space: pre-line">{{ $snapshot[$key] }}</p>
        @endif
    @endforeach

    @if (filled($snapshot['filed_by'] ?? null))
        <p class="small text-secondary mb-0">Filed by {{ $snapshot['filed_by'] }} on {{ $snapshot['filed_at'] }}.</p>
    @endif
</div>
