@extends('emails.layout')

@section('subject', 'Your email address has changed')

@section('preview')
    The address on your account is now {{ $newEmail }}.
@endsection

@section('heading')Your email address has changed@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $account->fullName() }},</p>

    <p style="margin:0 0 16px 0;">
        The email address on your {{ $company['name'] }} account has been changed and confirmed. You will sign in
        with the new address from now on, and this mailbox will stop receiving notifications about the account.
    </p>

    <x-mail-details :rows="[
        'Account' => $account->user_code,
        'Previous address' => $previousEmail,
        'New address' => $newEmail,
        'Changed on' => $changedAt,
    ]" />

    <p style="margin:0; color:#b02a37; font-size:13px;">
        <strong>If you did not make this change</strong>, contact your administrator immediately - somebody else
        may have access to your account.
    </p>
@endsection
