@extends('emails.layout')

@section('subject', $heading)

@section('preview')
    Your verification code is {{ $code }}. It expires in {{ $minutesValid }} minutes.
@endsection

@section('heading'){{ $heading }}@endsection

@section('content')
    <p style="margin:0 0 16px 0;">Hello{{ $recipientName ? ' ' . $recipientName : '' }},</p>

    <p style="margin:0 0 8px 0;">
        Use the code below to {{ $purposeLabel }}.
    </p>

    {{-- The code itself. Letter-spaced and oversized so it can be read off a
         phone at a glance, and selectable as plain text so it can be copied. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
        <tr>
            <td align="center"
                style="background-color:#f2f6fc; border:1px solid #d6e2f2; border-radius:10px; padding:26px 20px;">
                <div style="font-size:13px; color:#63748a; margin-bottom:10px; letter-spacing:0.4px;">
                    VERIFICATION CODE
                </div>
                <div
                    style="font-family:'Courier New',Consolas,monospace; font-size:36px; font-weight:700; letter-spacing:10px; color:{{ $company['colors']['header'] }}; line-height:1.2;">
                    {{ $code }}
                </div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px 0;">
        This code expires in <strong>{{ $minutesValid }} minutes</strong> and can only be used once.
    </p>

    <p style="margin:0; color:#63748a; font-size:13px;">
        If you did not request this code, you can safely ignore this email - nothing on your account has changed.
        Never share this code with anyone, including {{ $company['name'] }} staff.
    </p>
@endsection
