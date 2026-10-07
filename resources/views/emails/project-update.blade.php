@extends('emails.layout')

@section('subject', $heading)

@section('preview')
    {{ $project->reference_no }} - {{ $heading }}
@endsection

@section('heading'){{ $heading }}@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello{{ $recipientName ? ' ' . $recipientName : '' }},</p>

    <p style="margin:0 0 16px 0;">{{ $body }}</p>

    {{-- extraRows carries whatever this particular event needs and nothing
         else - the confirmation deadline, the new dates - and empty values are
         dropped by the component rather than printed as blank lines. --}}
    <x-mail-details :rows="array_merge([
        'Reference number' => $project->reference_no,
        'Project' => $project->name,
        'Site address' => $project->address,
    ], $extraRows ?? [], [
        $detailLabel => $detail,
    ])" />

    <p style="margin:0; color:#63748a; font-size:13px;">
        Sign in or register with this email to view it.
    </p>
@endsection

@section('action')
    <x-mail-button :url="$projectUrl">{{ $actionLabel ?? 'View my project' }}</x-mail-button>
@endsection
