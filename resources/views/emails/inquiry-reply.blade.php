@extends('emails.layout')

@section('subject', 'Re: ' . $inquirySubject)

@section('preview')
    A reply to the message you sent us about {{ $inquirySubject }}.
@endsection

@section('heading')
    A reply to your message
@endsection

@section('content')
    <p style="margin:0 0 16px 0;">
        Hello {{ $recipientName }}, thank you for getting in touch. Here is our reply to the message
        you sent us.
    </p>

    <x-mail-details :rows="[
        'Reference' => $reference,
        'Subject' => $inquirySubject,
    ]" />

    <p style="margin:24px 0 8px 0;font-weight:600;">Our reply</p>

    {{-- Line breaks kept as they were typed, and escaped by Blade so nothing
         written on the admin side can become markup in somebody's inbox. --}}
    <div
        style="margin:0 0 16px 0;padding:16px;background:#f4f6f8;border-radius:6px;white-space:pre-wrap;word-break:break-word;">{{ $replyBody }}</div>

    <p style="margin:24px 0 8px 0;font-weight:600;">Your original message</p>

    <div
        style="margin:0;padding:16px;background:#ffffff;border-left:3px solid #d5dbe1;white-space:pre-wrap;word-break:break-word;color:#5b6670;">{{ $originalMessage }}</div>
@endsection
