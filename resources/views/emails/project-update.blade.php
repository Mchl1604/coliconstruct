@extends('emails.layout')

@section('subject', $heading)

@section('preview')
    {{ $project->reference_no }} - {{ $heading }}
@endsection

@section('heading'){{ $heading }}@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello{{ $recipientName ? ' ' . $recipientName : '' }},</p>

    <p style="margin:0 0 16px 0;">{{ $body }}</p>

    <x-mail-details :rows="[
        'Reference number' => $project->reference_no,
        'Project' => $project->name,
        'Site address' => $project->address,
        $detailLabel => $detail,
    ]" />

    <p style="margin:0; color:#63748a; font-size:13px;">
        Sign in and open <em>My Projects</em> to see the full history of this project. If you do not have an
        account yet, register with this email address and the project will appear automatically.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$projectUrl">View my project</x-mail-button>
@endsection
