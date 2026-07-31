@props(['project'])

@php
    $onHold = (bool) ($project->on_hold ?? false);

    [$badgeClass, $badgeLabel] = match (true) {
        $onHold => ['bg-secondary', 'On Hold'],
        $project->status === 'not_yet_scheduled' => ['bg-info text-dark', 'Not Yet Scheduled'],
        $project->status === 'pending' => ['bg-warning', 'Pending'],
        $project->status === 'ongoing' => ['bg-primary', 'Ongoing'],
        $project->status === 'completed' => ['bg-success', 'Completed'],
        $project->status === 'cancelled' => ['bg-danger', 'Cancelled'],
        $project->status === 'archived' => ['bg-dark', 'Archived'],
        default => ['bg-secondary', ucfirst((string) $project->status)],
    };
@endphp

<span {{ $attributes->merge(['class' => 'badge ' . $badgeClass]) }}>{{ $badgeLabel }}</span>
