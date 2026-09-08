@props(['task'])

{{--
    Which stage of the project a task belongs to, as it reads in a table row.

    The number is what the column is for: a person scanning a board wants to
    know whether a task is Phase 1 work or Phase 4 work, and the number alone
    answers that. The title is under it because "3" on its own is only a
    position - the name is what the phase actually is, and it is the half a
    reader recognises.

    One component for every task table so the Super Admin board, the lead's
    board and both project pages print the phase identically. Kept out of the
    Task column deliberately: a phase is a fact about where the task sits, and
    burying it in the task's own description makes it unsortable.
--}}
@php
    $phase = $task->phase;
@endphp

@if ($phase)
    <span class="task-phase" title="{{ $phase->label() }}">
        <span class="task-phase-number">{{ $phase->sequence }}</span>
        <span class="task-phase-title">{{ $phase->title }}</span>
    </span>
@else
    {{-- A task with no phase is not an error to hide. Tasks created before
         project phases existed were placed by a backfill, and one whose phase
         went missing should be visible so somebody can file it - see the
         phase() relation on Task. --}}
    <span class="task-phase is-unset" title="This task is not filed under a project phase.">
        <span class="task-phase-number">&mdash;</span>
        <span class="task-phase-title">No phase</span>
    </span>
@endif
