{{--
    One account's profile picture, wherever a person is shown.

    Takes the account itself rather than a URL so the fallback is decided in
    one place: every account has a picture, and any that has not been set falls
    back to the default avatar.

    `user` may also be null - an unassigned task, a report whose submitter has
    since been removed - in which case the default avatar stands in.
--}}
@props([
    'user' => null,
    'size' => 'md',
    'alt' => null,
])

@php
    $source = $user?->avatarUrl() ?? asset('img/default-avatar.svg');
@endphp

<img src="{{ $source }}" alt="{{ $alt ?? ($user?->fullName() ?? 'Profile picture') }}"
    loading="lazy" decoding="async"
    {{ $attributes->merge(['class' => 'user-avatar user-avatar-' . $size]) }}>
