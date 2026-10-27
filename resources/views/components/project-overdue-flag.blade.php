@props(['project'])

{{--
    "The promised completion date has gone by."

    The row-level counterpart of the Overdue badge beside the target date on
    the project page, drawn like PHASE SETUP REQUIRED so both project tables
    say it identically.

    Nothing is stored. It disappears once the work is finished or the target
    date is moved - see Project::isPastTargetDate().
--}}
@if ($project->isPastTargetDate())
    <span {{ $attributes->merge(['class' => 'project-overdue-flag']) }}
        title="Past the target date.">
        <i class="bi bi-flag-fill" aria-hidden="true"></i>
        OVERDUE
    </span>
@endif
