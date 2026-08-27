@props(['project'])

{{--
    "There is a crew on this job today."

    One indicator, rendered from one component, so the Super Admin, Admin, Lead
    Technician and Technician projects tables all say it the same way. Whether
    it appears at all is Project::isActiveToday() - every portal asks the same
    question and none of them works it out for itself.

    A pill rather than a status: the row's status badge still says what stage
    the project is AT, and this says that it is happening now. The two are
    different questions, and a project can be Pending and active today at once
    - its first booked range opens this morning.

    Nothing is stored. This is a display state that starts and stops on its own
    as the date rolls over.
--}}
@if ($project->isActiveToday())
    <span {{ $attributes->merge(['class' => 'project-active-today-flag']) }}
        title="This project has a booked schedule range covering today.">
        <i class="bi bi-broadcast" aria-hidden="true"></i>
        ACTIVE TODAY
    </span>
@endif
