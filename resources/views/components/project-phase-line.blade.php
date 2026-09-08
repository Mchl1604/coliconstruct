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

    // "Phase 3/4: Site Preparation", the bar's width and the phase's own
    // description, all from the one place - see Project::phasePosition(),
    // which the two schedule panels read as well so none of the three can
    // word the same position differently.
    $position = $project->phasePosition();
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
@if ($position !== null)
    <div {{ $attributes->merge(['class' => 'project-phase-line' . ($compact ? ' is-compact' : '')]) }}>
        <p class="project-phase-line-title">{{ $position['headline'] }}</p>

        <div class="project-phase-line-bar" role="progressbar"
            aria-label="Phases completed" aria-valuenow="{{ $position['completed'] }}"
            aria-valuemin="0" aria-valuemax="{{ $position['total'] }}">
            <span style="width: {{ $position['percent'] }}%"></span>
        </div>

        @unless ($compact)
            <p class="project-phase-line-detail">
                {{ $position['description'] ?? 'Every stage of this project has been finished.' }}
            </p>
        @endunless
    </div>
@endif
