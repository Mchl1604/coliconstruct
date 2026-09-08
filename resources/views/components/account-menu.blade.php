@props(['user'])

{{--
    Who is signed in, and the menu that acts on it.

    One control, not two: a picture with a name beside a separate Logout button
    reads as two unrelated things when they are both about the same account.
    The picture leads, the way it does everywhere else a person is shown, and
    the caret says there is more behind it.

    This is where Logout lives for every signed-in staff account. It used to
    sit at the foot of the sidebar, which is a list of places to go - signing
    out is not one of them, and on a collapsed or hidden sidebar it went with
    it. Here it is on the same control that says whose session it is, which is
    where people look for it.

    The public website has its own copy of this menu in its own header - it
    carries My Portal as well, and is styled to that header rather than to the
    admin topbar - so the two are deliberately separate. Both staff shells
    share this one.
--}}
@php
    $displayName = $user?->fullName() ?? 'Guest';
    $displayRole = $user?->roleLabel() ?? '';
@endphp

<div class="dropdown">
    <button class="admin-user-menu admin-user-button" type="button" data-bs-toggle="dropdown" aria-expanded="false"
        aria-label="Signed in as {{ $displayName }} - open the account menu">
        <x-user-avatar :user="$user" size="md" alt="" />

        <span>
            <span class="admin-user-name">{{ $displayName }}</span>
            <span class="admin-user-role">{{ $displayRole }}</span>
        </span>

        <i class="bi bi-chevron-down admin-user-caret" aria-hidden="true"></i>
    </button>

    <ul class="dropdown-menu dropdown-menu-end admin-user-dropdown">
        <li>
            <a class="dropdown-item" href="{{ route('profile.edit') }}">
                <i class="bi bi-person" aria-hidden="true"></i>
                Profile
            </a>
        </li>

        <li><hr class="dropdown-divider"></li>

        <li>
            {{-- Signing out changes state, so it is a POST rather than a link
                 anything can follow. --}}
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit" class="dropdown-item text-danger">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    Logout
                </button>
            </form>
        </li>
    </ul>
</div>
