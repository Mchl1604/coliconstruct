@extends('layouts.authShell')

@section('title', 'Choose a New Password')

@section('card')
    <i class="bi bi-shield-lock text-primary" style="font-size: 2.6rem;" aria-hidden="true"></i>
    <h3 class="mb-1 mt-2">Choose a New Password</h3>
    <p class="text-muted mb-4">
        Your account was opened with a temporary password. Pick one only you know before continuing.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('auth.password.update') }}">
        @csrf

        <x-password-input name="current_password" label="Temporary Password" autofocus />

        <x-password-input name="password" label="New Password" autocomplete="new-password"
            minlength="8" role="new" />

        <x-password-input name="password_confirmation" label="Confirm New Password"
            autocomplete="new-password" minlength="8" role="confirm" />

        {{-- Turns green the moment the two agree, red while they do not. --}}
        <div class="text-start mb-4">
            <span class="form-text text-muted" data-password-match>At least 8 characters.</span>
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg">Update Password</button>
        </div>
    </form>

    <form method="POST" action="{{ route('auth.logout') }}">
        @csrf
        <button type="submit" class="btn btn-link text-muted small text-decoration-none">Sign out instead</button>
    </form>
@endsection
