{{--
    A flag beside a task's technician when they are not on the project for
    all of it. Removing a technician never unassigns their work; this is where
    that shows. Nothing is drawn for any other task.

    Off the project for good - no span of theirs still running or still to
    come - says so, whether or not the task has dates: see
    Task::holderRemovedFromProject(). Otherwise, a day off inside the task's
    dates - a scheduled day of the project they are not on its team for - see
    Task::holderHasDayOffInDates().
--}}
@props(['task'])

@if ($task->isOpen() && $task->technician && $task->holderRemovedFromProject())
    <span class="task-holder-flag" title="{{ $task->technician->name }} is no longer on this project. Reassign this task.">
        <i class="bi bi-flag-fill" aria-hidden="true"></i>
        Removed from project
    </span>
@elseif ($task->isOpen() && $task->technician && $task->holderHasDayOffInDates())
    <span class="task-holder-flag" title="{{ $task->technician->name }} has a day off on this project within this task's dates.">
        <i class="bi bi-flag-fill" aria-hidden="true"></i>
        Day off in task dates
    </span>
@endif
