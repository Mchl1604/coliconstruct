@props([
    'project',
    // Compact drops the phase's description and tightens the spacing, for a
    // card in a grid rather than a panel on a page of its own.
    'compact' => false,
])

@php
    // Read from the phases relation where the page loaded it - the My Projects
    // grid draws one of these per card, and a query each would be a query per
    // project. See ClientProjects::forOwner(), which eager loads it.
    $project->loadMissing('phases');

    $progress = $project->phaseProgress();
    $current = $project->currentPhase();

    // Built here rather than assembled from several Blade expressions: broken
    // across lines in the template it renders with newlines inside it, and
    // "Phase 3/4: Site Preparation" is one thing to read, not three.
    //
    // Once every phase is closed there is no current one left to name, and
    // "Phase 4/4" would suggest work is still happening on the last of them -
    // hence the two wordings.
    $headline = $progress === null
        ? null
        : ($current === null
            ? sprintf(
                'All %d %s complete',
                $progress['total'],
                \Illuminate\Support\Str::plural('phase', $progress['total'])
            )
            : sprintf(
                'Phase %d/%d: %s',
                $progress['completed'] + 1,
                $progress['total'],
                $current->title
            ));

    $percent = $progress === null
        ? 0
        : (int) round($progress['completed'] / $progress['total'] * 100);
@endphp

{{--
    "Phase 3/4: Site Preparation", with a bar underneath.

    Deliberately NOT the monitoring panel. A client is not running this project
    and has no use for four cards, a task count per phase or a Complete Phase
    button; what they want to know when they open their own job is how far
    along it is, and that is one line and a bar. The panel stays in the two
    staff portals, where somebody is actually working the phases.

    The figures are Project::phaseProgress() and Project::currentPhase(), which
    is what the staff panel counts from too - so the client and the office can
    never be told different things about the same project.
--}}
@if ($headline !== null)
    <div {{ $attributes->merge(['class' => 'project-phase-line' . ($compact ? ' is-compact' : '')]) }}>
        <p class="project-phase-line-title">{{ $headline }}</p>

        <div class="project-phase-line-bar" role="progressbar"
            aria-label="Phases completed" aria-valuenow="{{ $progress['completed'] }}"
            aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}">
            <span style="width: {{ $percent }}%"></span>
        </div>

        @unless ($compact)
            <p class="project-phase-line-detail">
                {{ $current?->description ?? 'Every stage of this project has been finished.' }}
            </p>
        @endunless
    </div>
@endif
