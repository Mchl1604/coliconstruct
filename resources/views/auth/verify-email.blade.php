@extends('layouts.authShell')

@section('title', 'Verify Your Email')

@section('card')
    <img src="/img/coliconstructlogor.png" alt="{{ config('company.name') }}" width="72" class="mb-3">
    <h3 class="mb-1">Verify Your Email</h3>
    <p class="text-muted mb-4">
        A 6-digit code was sent to <strong>{{ $email }}</strong>.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <x-otp-form :action="route('auth.verify.store')" :resend-action="route('auth.verify.resend')" :email="$email"
        :retry-after="$retryAfter" :back-url="route('auth.login')" button-label="Verify &amp; Activate" />
@endsection
