@extends('layouts.authShell')

@section('title', 'Forgot Password')

@section('card')
    <i class="bi bi-key text-primary" style="font-size: 2.6rem;" aria-hidden="true"></i>
    <h3 class="mb-1 mt-2">Forgot Password</h3>
    <p class="text-muted mb-4">
        Enter your account email and we will send a 6-digit code.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    @unless ($mailEnabled)
        {{-- Without a configured mailer nothing can be sent, so say so here
             rather than letting somebody wait for a link that never arrives. --}}
        <div class="alert alert-warning text-start" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Verification codes cannot be sent right now.
            Ask an administrator to reset your password.
        </div>
    @endunless

    <form method="POST" action="{{ route('auth.password.email') }}">
        @csrf

        <div class="mb-4 text-start">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@email.com"
                value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg" @disabled(! $mailEnabled)>
                Send Verification Code
            </button>
        </div>

        <div class="text-muted small">
            Remembered it?
            <a href="{{ route('auth.login') }}" class="text-decoration-none">Back to sign in</a>
        </div>
    </form>
@endsection
