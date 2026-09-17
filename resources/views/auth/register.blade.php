@extends('layouts.authShell')

@section('title', 'Create an Account')

{{-- Wide enough for two columns of fields on a desktop. The shell keeps it
     inside the viewport on anything narrower, and the grid below collapses to
     one column at the same point. --}}
@section('card-width', '760px')

@section('card')
    <img src="/img/coliconstructlogor.png" alt="Coliconstruct" width="72" class="mb-3">
    <h3 class="mb-1">Create an Account</h3>
    <p class="text-muted mb-4">Sign up to follow your projects with Coliconstruct</p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('auth.register.store') }}">
        @csrf

        {{-- Two columns from `md` up, one below it. Every field keeps the
             left-aligned label the single-column form had. --}}
        <div class="row g-3 text-start">

            {{-- The name in its parts, the same three fields the Profile page
                 keeps: nothing has to guess later where one part ends. --}}
            <div class="col-md-5">
                <label class="form-label" for="first_name">First Name</label>
                <input type="text" id="first_name" name="first_name" class="form-control" maxlength="100"
                    value="{{ old('first_name') }}" required autofocus autocomplete="given-name">
            </div>

            <div class="col-md-2">
                <label class="form-label" for="middle_name">M.I.</label>
                {{-- One letter, which is what an initial is - see PersonName. --}}
                <input type="text" id="middle_name" name="middle_name" class="form-control text-center"
                    maxlength="1" pattern="[A-Za-z]" placeholder="Optional" title="A single letter (A-Z)"
                    value="{{ old('middle_name') }}" autocomplete="additional-name">
            </div>

            <div class="col-md-5">
                <label class="form-label" for="last_name">Last Name</label>
                <input type="text" id="last_name" name="last_name" class="form-control" maxlength="100"
                    value="{{ old('last_name') }}" required autocomplete="family-name">
            </div>

            <div class="col-md-4">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="you@email.com"
                    maxlength="255" value="{{ old('email') }}" required autocomplete="username">
            </div>

            <div class="col-md-4">
                <label class="form-label" for="contact_number">Contact Number</label>
                {{-- Digits and nothing else, and exactly eleven of them - the
                     same rule User::CONTACT_NUMBER_RULE applies on the server.
                     `inputmode` gets a phone keypad rather than a full
                     keyboard; registerForm.js strips anything that is not a
                     digit as it is typed or pasted. --}}
                <input type="text" id="contact_number" name="contact_number" class="form-control"
                    placeholder="09171234567" inputmode="numeric" autocomplete="tel"
                    maxlength="{{ \App\Models\User::CONTACT_NUMBER_LENGTH }}"
                    minlength="{{ \App\Models\User::CONTACT_NUMBER_LENGTH }}" pattern="[0-9]{11}"
                    data-digits-only value="{{ old('contact_number') }}" required>
            </div>

            <div class="col-md-4">
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

@push('scripts')
    {{-- Keeps the contact number to digits only as it is typed or pasted. --}}
    <script src="/js/registerForm.js"></script>
@endpush
