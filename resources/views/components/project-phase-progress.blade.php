@props(['project'])

{{--
    "3/4" - how far through its phases a project is, on a row or a card.

    Shown to everybody who can see the project at all: the office, both
    technician roles and the client on the public website. How far through a
    job is has never been a fact any of them had to be kept from, and until now
    it could only be read by opening the project.

    Green, because it is a measure of work finished - the one figure on a
    projects table that is never a problem. The strongest cut of that green is
    saved for a project whose every phase is closed.

    The figures are Project::phaseProgress(), which reads eager-loaded counts,
    so a table of fifty rows is still one query.
--}}
@php
    $progress = $project->phaseProgress();
@endphp

@if ($progress === null)
    {{-- No agreed structure, so no denominator. The row's own setup flag says
         what to do about it; this only avoids an empty cell. --}}
    <span {{ $attributes->merge(['class' => 'project-phase-chip is-unset']) }}
        title="This project has not been configured with its project phases yet.">
        <i class="bi bi-diagram-3" aria-hidden="true"></i>
        Not set up
    </span>
@else
    <span
        {{ $attributes->merge([
            'class' =>
                'project-phase-chip' .
                ($progress['completed'] === 0 ? ' is-empty' : '') .
                ($progress['completed'] === $progress['total'] ? ' is-complete' : ''),
        ]) }}
        title="{{ $progress['completed'] }} of {{ $progress['total'] }} {{ \Illuminate\Support\Str::plural('phase', $progress['total']) }} completed.">
        <i class="bi bi-diagram-3" aria-hidden="true"></i>
        {{ $progress['completed'] }}/{{ $progress['total'] }}
    </span>
@endif
