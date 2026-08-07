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
            An administrator has approved the changes you asked for. Your specialties have been updated, and you
            will now be matched to work that calls for them.
        </p>
    @else
        <p style="margin:0 0 16px 0;">
            An administrator has declined the changes you asked for. <strong>Your current specialties are
                unchanged</strong>, and you may submit a new request at any time.
        </p>
    @endif

    @if ($specialties !== [])
        <x-mail-details :rows="[
            ($approved ? 'Your specialties' : 'Your specialties (unchanged)') => implode(', ', $specialties),
        ]" />
    @endif

    <p style="margin:0; color:#63748a; font-size:13px;">
        You can review your specialties at any time on your profile page.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$profileUrl">View my profile</x-mail-button>
@endsection
