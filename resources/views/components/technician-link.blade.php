{{--
    A technician's name - or their picture and name together - that opens
    their details on the Technicians page.

    Only an Admin or Super Admin is sent anywhere: the Technicians page is
    theirs, and several of the components that show a technician (the task
    board, the task details dialog) are shared with the technician portal,
    where the same markup renders as plain text.

    With a slot, the slot is what becomes clickable, so an avatar can travel
    inside the link beside the name. Without one, the technician's name is
    printed. `technician` may be null - an unassigned task - in which case the
    fallback text is shown and nothing links.
--}}
@props([
    'technician' => null,
    'fallback' => 'Unassigned',
])

@php
    $viewer = auth()->user();

    $linksToTechnician = $technician
        && $viewer
        && in_array($viewer->role, \App\Models\User::ADMINISTRATOR_ROLES, true);
@endphp

@if ($linksToTechnician)
    <a href="{{ route('super-admin.technicians.index', ['technician' => $technician->technician_id]) }}"
        title="View {{ $technician->name }}'s details"
        {{ $attributes->merge(['class' => 'technician-link']) }}>{{ $slot->isEmpty() ? $technician->name : $slot }}</a>
@else
    <span {{ $attributes }}>{{ $slot->isEmpty() ? ($technician?->name ?? $fallback) : $slot }}</span>
@endif
