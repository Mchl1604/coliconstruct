@extends('emails.layout')

@section('subject', 'Your email address has changed')

@section('preview')
    The address on your account is now {{ $newEmail }}.
@endsection

@section('heading')Your email address has changed@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $account->fullName() }},</p>

    <p style="margin:0 0 16px 0;">
        Sign in with your new address from now on.
    </p>

    <x-mail-details :rows="[
        'Account' => $account->user_code,
        'Previous address' => $previousEmail,
        'New address' => $newEmail,
        'Changed on' => $changedAt,
    ]" />

    <p style="margin:0; color:#b02a37; font-size:13px;">
        <strong>Not you?</strong> Contact your administrator now.
    </p>
@endsection
