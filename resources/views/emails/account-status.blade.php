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
            Your {{ $company['name'] }} account has been <strong>temporarily deactivated</strong>.
        </p>

        <p style="margin:0 0 16px 0;">
            Nothing has been deleted.
        </p>

        @if ($reason)
            <x-mail-details :rows="['Reason' => $reason]" />
        @endif

        <p style="margin:0; color:#63748a; font-size:13px;">
            Think this is a mistake? Contact your administrator.
        </p>
    @elseif ($change === \App\Mail\AccountStatusMail::VERIFIED)
        <p style="margin:0 0 16px 0;">
            Your account is now active.
        </p>

        <x-mail-details :rows="[
            'Account' => $account->user_code,
            'Role' => $account->roleLabel(),
            'Email address' => $account->email,
        ]" />

        <p style="margin:0 0 16px 0;">
            Sign in to follow your projects.
        </p>
    @else
        <p style="margin:0 0 16px 0;">
            Your {{ $company['name'] }} account has been <strong>reactivated</strong>.
        </p>

        <x-mail-details :rows="[
            'Account' => $account->user_code,
            'Email address' => $account->email,
        ]" />

        <p style="margin:0; color:#63748a; font-size:13px;">
            Forgot your password? Use "Forgot password?" to reset it.
        </p>
    @endif
@endsection

@unless ($isDeactivation)
    @section('action')
        <x-mail-button :url="$loginUrl">Sign in</x-mail-button>
    @endsection
@endunless
