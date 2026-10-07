@extends('emails.layout')

@section('subject', $approved ? 'Specialty request approved' : 'Specialty request declined')

@section('preview')
    Your specialty request has been {{ $approved ? 'approved' : 'declined' }}.
@endsection

@section('heading')
    {{ $approved ? 'Your specialty request was approved' : 'Your specialty request was declined' }}
@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $account->fullName() }},</p>

    @if ($approved)
        <p style="margin:0 0 16px 0;">
            Your specialties have been updated.
        </p>
    @else
        <p style="margin:0 0 16px 0;">
            <strong>Your specialties are unchanged.</strong>
        </p>
    @endif

    @if ($specialties !== [])
        <x-mail-details :rows="[
            ($approved ? 'Your specialties' : 'Your specialties (unchanged)') => implode(', ', $specialties),
        ]" />
    @endif

    <p style="margin:0; color:#63748a; font-size:13px;">
        View them on your profile.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$profileUrl">View my profile</x-mail-button>
@endsection
