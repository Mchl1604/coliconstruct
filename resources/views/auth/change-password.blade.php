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

        <div class="mb-3 text-start">
            <label class="form-label" for="current_password">Temporary Password</label>
            <input type="password" id="current_password" name="current_password" class="form-control"
                required autofocus autocomplete="current-password">
        </div>

        <div class="mb-3 text-start">
            <label class="form-label" for="password">New Password</label>
            <input type="password" id="password" name="password" class="form-control" minlength="8"
                maxlength="72" required autocomplete="new-password">
            <div class="form-text">At least 8 characters.</div>
        </div>

        <div class="mb-4 text-start">
            <label class="form-label" for="password_confirmation">Confirm New Password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                minlength="8" maxlength="72" required autocomplete="new-password">
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
