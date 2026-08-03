@extends('layouts.authShell')

@section('title', 'Set a New Password')

@section('card')
    <i class="bi bi-shield-lock text-primary" style="font-size: 2.6rem;" aria-hidden="true"></i>
    <h3 class="mb-1 mt-2">Set a New Password</h3>
    <p class="text-muted mb-4">Pick one only you know.</p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('auth.password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3 text-start">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control"
                value="{{ old('email', $email) }}" required autocomplete="username" readonly>
        </div>

        <x-password-input name="password" label="New Password" autocomplete="new-password"
            minlength="8" role="new" autofocus />

        <x-password-input name="password_confirmation" label="Confirm New Password"
            autocomplete="new-password" minlength="8" role="confirm" />

        {{-- Turns green the moment the two agree, red while they do not. --}}
        <div class="text-start mb-4">
            <span class="form-text text-muted" data-password-match>At least 8 characters.</span>
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg">Reset Password</button>
        </div>

        <div class="text-muted small">
            <a href="{{ route('auth.login') }}" class="text-decoration-none">Back to sign in</a>
        </div>
    </form>
@endsection
