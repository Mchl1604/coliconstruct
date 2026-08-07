@extends('layouts.authShell')

@section('title', 'Enter Your Code')

@section('card')
    <i class="bi bi-shield-lock text-primary" style="font-size: 2.6rem;" aria-hidden="true"></i>
    <h3 class="mb-1 mt-2">Enter Your Code</h3>
    <p class="text-muted mb-4">
        If <strong>{{ $email }}</strong> has an account, a 6-digit code is on its way.
        Enter it below to set a new password.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <x-otp-form :action="route('auth.password.verify.store')" :resend-action="route('auth.password.resend')"
        :email="$email" :retry-after="$retryAfter" :back-url="route('auth.password.request')"
        back-label="Use a different email address" button-label="Verify Code" />
@endsection
