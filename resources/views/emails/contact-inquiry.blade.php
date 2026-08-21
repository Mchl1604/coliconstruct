@extends('emails.layout')

@section('subject', 'Website inquiry from ' . $senderName)

@section('preview')
    {{ $senderName }} wrote in through the Contact page about {{ $inquirySubject }}.
@endsection

@section('heading')
    New inquiry from the website
@endsection

@section('content')
    <p style="margin:0 0 16px 0;">
        Somebody has written in through the Contact page. Replying to this email answers them directly.
    </p>

    <x-mail-details :rows="[
        'Name' => $senderName,
        'Email' => $senderEmail,
        'Subject' => $inquirySubject,
    ]" />

    <p style="margin:24px 0 8px 0;font-weight:600;">Their message</p>

    {{-- The message as they typed it, line breaks and all. Escaped by Blade,
         so nothing they wrote can become markup in the inbox. --}}
    <div
        style="margin:0 0 16px 0;padding:16px;background:#f4f6f8;border-radius:6px;white-space:pre-wrap;word-break:break-word;">{{ $body }}</div>

    <x-mail-button :url="'mailto:' . $senderEmail">Reply to {{ $senderName }}</x-mail-button>
@endsection
