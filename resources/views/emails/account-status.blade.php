@extends('emails.layout')

@section('subject', $heading)

@section('preview')
    @if ($isDeactivation)
        Your account has been temporarily deactivated.
    @else
        You can sign in to {{ $company['name'] }} again.
    @endif
@endsection

@section('heading'){{ $heading }}@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $account->fullName() }},</p>

    @if ($isDeactivation)
        <p style="margin:0 0 16px 0;">
            Your {{ $company['name'] }} account has been <strong>temporarily deactivated</strong> by an
            administrator. You will not be able to sign in until it is reactivated.
        </p>

        <p style="margin:0 0 16px 0;">
            Nothing has been deleted. Your projects, documents and history are all intact and will be exactly as
            you left them when your access is restored.
        </p>

        @if ($reason)
            <x-mail-details :rows="['Reason' => $reason]" />
        @endif

        <p style="margin:0; color:#63748a; font-size:13px;">
            If you believe this was a mistake, please contact your administrator.
        </p>
    @elseif ($change === \App\Mail\AccountStatusMail::VERIFIED)
        <p style="margin:0 0 16px 0;">
            Thank you for confirming your email address. Your {{ $company['name'] }} account is now active and you
            can sign in at any time.
        </p>

        <x-mail-details :rows="[
            'Account' => $account->user_code,
            'Role' => $account->roleLabel(),
            'Email address' => $account->email,
        ]" />

        <p style="margin:0 0 16px 0;">
            Once signed in you can follow the progress of any project booked under this address.
        </p>
    @else
        <p style="margin:0 0 16px 0;">
            Your {{ $company['name'] }} account has been <strong>reactivated</strong>. You may sign in again with
            the same email address and password you used before.
        </p>

        <x-mail-details :rows="[
            'Account' => $account->user_code,
            'Email address' => $account->email,
        ]" />

        <p style="margin:0; color:#63748a; font-size:13px;">
            If you have forgotten your password, use "Forgot password?" on the sign-in page.
        </p>
    @endif
@endsection

@unless ($isDeactivation)
    @section('action')
        <x-mail-button :url="$loginUrl">Sign in</x-mail-button>
    @endsection
@endunless
