@extends('emails.layout')

@section('subject', 'Your project has been created')

@section('preview')
    Project {{ $project->reference_no }} is now open. Follow its progress online.
@endsection

@section('heading')Welcome to {{ $company['name'] }}@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $clientName }},</p>

    <p style="margin:0 0 16px 0;">
        Thank you for choosing {{ $company['name'] }}. Your project has been created and our team is now working
        on it. You can follow its progress online at any time - the schedule, the assigned technicians, the
        documents and every status change as it happens.
    </p>

    <x-mail-details :rows="[
        'Reference number' => $project->reference_no,
        'Project type' => $projectTypes !== [] ? implode(', ', $projectTypes) : null,
        'Client' => $clientName,
        'Site address' => $project->address,
    ]" />

    @if ($hasAccount)
        <p style="margin:0 0 16px 0;">
            You already have a {{ $company['name'] }} account under <strong>{{ $contactEmail }}</strong>.
            Simply sign in and open <em>My Projects</em> to see this project.
        </p>
    @else
        <p style="margin:0 0 8px 0;">
            To follow this project, create a free account using <strong>this same email address</strong>:
        </p>
        <p
            style="margin:0 0 16px 0; padding:12px 16px; background-color:#fff8e6; border-left:3px solid #f0ad4e; font-size:14px;">
            Register with <strong>{{ $contactEmail }}</strong>. The system matches projects to accounts by email
            address, so using a different one means this project will not appear under <em>My Projects</em>.
        </p>
    @endif

    <p style="margin:0; color:#63748a; font-size:13px;">
        If anything about the details above looks wrong, please contact us using the details at the bottom of this
        email.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$actionUrl">
        {{ $hasAccount ? 'Sign in to view my project' : 'Create my account' }}
    </x-mail-button>
@endsection
