@extends('layouts.authShell')

@section('title', 'Create an Account')

@section('card')
    <img src="/img/coliconstructlogor.png" alt="Coliconstruct" width="72" class="mb-3">
    <h3 class="mb-1">Create an Account</h3>
    <p class="text-muted mb-4">Register as a Coliconstruct client</p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('auth.register.store') }}">
        @csrf

        <div class="mb-3 text-start">
            <label class="form-label" for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" class="form-control" maxlength="255"
                value="{{ old('full_name') }}" required autofocus autocomplete="name">
        </div>

        <div class="mb-3 text-start">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@email.com"
                maxlength="255" value="{{ old('email') }}" required autocomplete="username">
        </div>

        <div class="mb-3 text-start">
            <label class="form-label" for="contact_number">Contact Number</label>
            <input type="tel" id="contact_number" name="contact_number" class="form-control"
                placeholder="09XX XXX XXXX" maxlength="32" value="{{ old('contact_number') }}" required
                autocomplete="tel">
        </div>

        <div class="mb-3 text-start">
            <label class="form-label" for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••"
                minlength="8" maxlength="72" required autocomplete="new-password">
            <div class="form-text">At least 8 characters.</div>
        </div>

        <div class="mb-4 text-start">
            <label class="form-label" for="password_confirmation">Confirm Password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                placeholder="••••••••" minlength="8" maxlength="72" required autocomplete="new-password">
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
        </div>

        <div class="text-muted small">
            Already have an account?
            <a href="{{ route('auth.login') }}" class="text-decoration-none">Sign in</a>
        </div>
        <div class="text-muted small mt-2">
            Registering here creates a client account. Staff accounts are created by an administrator.
        </div>
    </form>
@endsection
