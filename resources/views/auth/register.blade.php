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
            <label class="form-label" for="birthdate">Date of Birth</label>
            {{-- The picker's own bounds match the rule the server applies, so
                 an under-age date is refused before the form is even sent. --}}
            <input type="date" id="birthdate" name="birthdate" class="form-control"
                value="{{ old('birthdate') }}" min="{{ \App\Support\AccountAge::earliestAllowed() }}"
                max="{{ \App\Support\AccountAge::latestAllowed() }}" required autocomplete="bday">
            <span class="form-text text-muted">You must be at least 18 years old to register.</span>
        </div>

        <x-password-input name="password" label="Password" placeholder="••••••••"
            autocomplete="new-password" minlength="8" role="new" />

        <x-password-input name="password_confirmation" label="Confirm Password" placeholder="••••••••"
            autocomplete="new-password" minlength="8" role="confirm" />

        {{-- Turns green the moment the two agree, red while they do not. --}}
        <div class="text-start mb-4">
            <span class="form-text text-muted" data-password-match>At least 8 characters.</span>
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
        </div>

        <div class="text-muted small">
            Already have an account?
            <a href="{{ route('auth.login') }}" class="text-decoration-none">Sign in</a>
        </div>
    </form>
@endsection
