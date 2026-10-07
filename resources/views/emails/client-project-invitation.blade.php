@extends('emails.layout')

@section('subject', 'Your project has been created')

@section('preview')
    Project {{ $project->reference_no }} is now open.
@endsection

@section('heading')Welcome to {{ $company['name'] }}@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello {{ $clientName }},</p>

    <p style="margin:0 0 16px 0;">
        Your project is created. Follow it online anytime.
    </p>

    <x-mail-details :rows="[
        'Reference number' => $project->reference_no,
        'Project type' => $projectTypes !== [] ? implode(', ', $projectTypes) : null,
        'Client' => $clientName,
        'Site address' => $project->address,
    ]" />

    @if ($hasAccount)
        <p style="margin:0 0 16px 0;">
            Sign in with <strong>{{ $contactEmail }}</strong> and open <em>My Projects</em>.
        </p>
    @else
        <p style="margin:0 0 8px 0;">
            Create a free account to follow it:
        </p>
        <p
            style="margin:0 0 16px 0; padding:12px 16px; background-color:#fff8e6; border-left:3px solid #f0ad4e; font-size:14px;">
            Register with <strong>{{ $contactEmail }}</strong>.
        </p>
    @endif

    <p style="margin:0; color:#63748a; font-size:13px;">
        Something wrong? Contact us.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$actionUrl">
        {{ $hasAccount ? 'Sign in to view my project' : 'Create my account' }}
    </x-mail-button>
@endsection
