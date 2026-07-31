@props(['project'])

{{-- Label and colour both come from the Project model so the projects table,
     the tasks table, the calendars and the JSON payloads never disagree.
     Overdue (orange) takes precedence over the underlying status. --}}
<span {{ $attributes->merge(['class' => 'badge ' . $project->statusBadgeClass()]) }}>
    {{ $project->statusLabel() }}
</span>
