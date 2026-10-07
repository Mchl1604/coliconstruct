@extends('emails.layout')

@section('subject', $isReset ? 'Your password has been reset' : 'Your account is ready')

@section('preview')
    {{ $isReset ? 'A temporary password has been issued for your account.' : 'Here are your sign-in details.' }}
@endsection

@section('heading')
    {{ $isReset ? 'Your password has been reset' : 'Welcome to ' . $company['name'] }}
@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $account->fullName() }},</p>

    @if ($isReset)
        <p style="margin:0 0 16px 0;">
            Sign in with this temporary password.
        </p>
    @else
        <p style="margin:0 0 16px 0;">
            Your account is ready. Sign in below.
        </p>
    @endif

    <x-mail-details :rows="[
        'User ID' => $account->user_code,
        'Role' => $account->roleLabel(),
        'Email address' => $account->email,
        'Temporary password' => $temporaryPassword,
    ]" />

    <p style="margin:0 0 16px 0;">
        You'll choose a new password at first sign-in.
    </p>

    <p style="margin:0; color:#63748a; font-size:13px;">
        Not expecting this? Contact your administrator.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$loginUrl">Sign in</x-mail-button>
@endsection
