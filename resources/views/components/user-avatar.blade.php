{{--
    One account's profile picture, wherever a person is shown.

    Takes the account itself rather than a URL so the "clients have no picture"
    rule is decided in one place: pass a client and this renders nothing at
    all, which is what every caller wants and none of them should have to
    remember.

    `user` may also be null - an unassigned task, a report whose submitter has
    since been removed - in which case the default avatar stands in.
--}}
@props([
    'user' => null,
    'size' => 'md',
    'alt' => null,
])

@php
    // A client is shown by name; there is no avatar to fall back to.
    $isClient = $user?->isClient() === true;
    $source = $user?->avatarUrl() ?? ($user === null ? asset('img/default-avatar.svg') : null);
@endphp

@unless ($isClient || $source === null)
    <img src="{{ $source }}" alt="{{ $alt ?? ($user?->fullName() ?? 'Profile picture') }}"
        loading="lazy" decoding="async"
        {{ $attributes->merge(['class' => 'user-avatar user-avatar-' . $size]) }}>
@endunless
