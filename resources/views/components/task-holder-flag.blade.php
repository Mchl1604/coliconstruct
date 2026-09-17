{{--
    A flag beside a task's technician when they have a day off inside the
    task's dates - a scheduled day of the project they are not on its team
    for. Removing a technician never unassigns their work; this is where that
    shows. Nothing is drawn for any other task. See Task::holderHasDayOffInDates().
--}}
@props(['task'])

@if ($task->isOpen() && $task->technician && $task->holderHasDayOffInDates())
    <span class="task-holder-flag" title="{{ $task->technician->name }} has a day off on this project within this task's dates.">
        <i class="bi bi-flag-fill" aria-hidden="true"></i>
        Day off in task dates
    </span>
@endif
