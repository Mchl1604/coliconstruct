@extends('layouts.authShell')

@section('title', 'Create an Account')

{{-- Wide enough for two columns of fields on a desktop. The shell keeps it
     inside the viewport on anything narrower, and the grid below collapses to
     one column at the same point. --}}
@section('card-width', '760px')

@section('card')
    <img src="/img/coliconstructlogor.png" alt="Coliconstruct" width="72" class="mb-3">
    <h3 class="mb-1">Create an Account</h3>
    <p class="text-muted mb-4">Use the email on your project.</p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('auth.register.store') }}">
        @csrf

        {{-- Two columns from `md` up, one below it. Every field keeps the
             left-aligned label the single-column form had.

             Only what the client record cannot supply. An account is opened
             for a client already on record - see RegistrableClientEmail - and
             takes its name and number from that record once the emailed code
             proves the address, so none of it is asked for or shown here. --}}
        <div class="row g-3 text-start">

            <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="you@email.com"
                    maxlength="255" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>

            <div class="col-md-6">
                <label class="form-label" for="birthdate">Date of Birth</label>
                {{-- The picker's own bounds match the rule the server applies, so
                     an under-age date is refused before the form is even sent. --}}
                <input type="date" id="birthdate" name="birthdate" class="form-control"
                    value="{{ old('birthdate') }}" min="{{ \App\Support\AccountAge::earliestAllowed() }}"
                    max="{{ \App\Support\AccountAge::latestAllowed() }}" required autocomplete="bday">
            </div>

            {{-- The password pair keeps its own column each, so the live match
                 indication and the requirements below sit under both of them. --}}
            <div class="col-md-6">
                <x-password-input name="password" label="Password" placeholder="••••••••"
                    autocomplete="new-password" :minlength="\App\Support\PasswordPolicy::MIN_LENGTH" role="new"
                    wrapper="text-start mb-0" />
            </div>

            <div class="col-md-6">
                <x-password-input name="password_confirmation" label="Confirm Password" placeholder="••••••••"
                    autocomplete="new-password" :minlength="\App\Support\PasswordPolicy::MIN_LENGTH" role="confirm"
                    wrapper="text-start mb-0" />
            </div>
        </div>

        {{-- Turns green the moment the two agree, red while they do not. --}}
        <div class="text-start">
            <span class="form-text text-muted" data-password-match></span>
        </div>

        <x-password-requirements for="password" class="mb-3" />

        {{-- Agreeing is a precondition of registering. The words are a button
             rather than a link so opening them cannot navigate away from a
             half-filled form - the dialog sits over this page and everything
             typed is still here when it closes. --}}
        <div class="form-check text-start mb-4">
            <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox" value="1"
                id="terms" name="terms" required @checked(old('terms'))>
            <label class="form-check-label" for="terms">
                I have read and agree to the
                <button type="button" class="btn btn-link p-0 align-baseline text-decoration-underline"
                    data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</button>.
            </label>
            @error('terms')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
        </div>

        <div class="text-muted small">
            Already have an account?
            <a href="{{ route('auth.login') }}" class="text-decoration-none">Sign in</a>
        </div>
    </form>

    {{-- The one reading copy of the document, shared with the website
         footer - see resources/views/components/terms-modal.blade.php. --}}
    <x-terms-modal />
@endsection


