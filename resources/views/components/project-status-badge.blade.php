@props(['project'])

{{-- Label and colour both come from the Project model so the projects tables,
     the task boards, the calendars, the client's own cards and the JSON
     payloads never disagree.

     The colour is not in the class. `data-status` carries Project::statusKey()
     - which is what settles the precedence, archived over paused over overdue
     over the stored status - and projectStatus.css paints it from the same
     palette the schedule calendar draws with. See Project::STATUS_INK. --}}
<span {{ $attributes->merge(['class' => 'badge ' . $project->statusBadgeClass()]) }}
    data-status="{{ $project->statusKey() }}">
    {{ $project->statusLabel() }}
</span>
