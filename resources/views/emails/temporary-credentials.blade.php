@extends('emails.layout')

@section('subject', $isReset ? 'Your password has been reset' : 'Your account is ready')

@section('preview')
    {{ $isReset ? 'A temporary password has been issued for your account.' : 'Your account has been created. Here are your sign-in details.' }}
@endsection

@section('heading')
    {{ $isReset ? 'Your password has been reset' : 'Welcome to ' . $company['name'] }}
@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $account->fullName() }},</p>

    @if ($isReset)
        <p style="margin:0 0 16px 0;">
            An administrator has reset the password on your {{ $company['name'] }} account. Use the temporary
            password below to sign in.
        </p>
    @else
        <p style="margin:0 0 16px 0;">
            An account has been created for you on the {{ $company['name'] }} {{ $company['tagline'] }}.
            Use the details below to sign in for the first time.
        </p>
    @endif

    <x-mail-details :rows="[
        'User ID' => $account->user_code,
        'Role' => $account->roleLabel(),
        'Email address' => $account->email,
        'Temporary password' => $temporaryPassword,
    ]" />

    <p style="margin:0 0 16px 0;">
        You will be asked to choose a new password the first time you sign in. This temporary password stops
        working at that point, so there is no need to keep it.
    </p>

    <p style="margin:0; color:#63748a; font-size:13px;">
        If you were not expecting this message, please contact your administrator.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$loginUrl">Sign in</x-mail-button>
@endsection
