{{--
    The shell every system email sits in.

    Written as tables with inline styles on purpose: mail clients strip
    stylesheets, ignore flexbox and grid, and Outlook renders through Word.
    The one <style> block carries only the mobile media query, which the
    clients that support it honour and the rest safely ignore.

    A child template fills three things:
      - @section('heading')  the line under the logo
      - @section('content')  the body
      - @section('action')   an optional button, via <x-mail-button>
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <title>@yield('subject', $company['name'])</title>
    <style>
        @media only screen and (max-width: 620px) {

            .email-wrapper,
            .email-card {
                width: 100% !important;
            }

            .email-pad {
                padding-left: 24px !important;
                padding-right: 24px !important;
            }

            .email-heading {
                font-size: 20px !important;
            }

            .email-button a {
                display: block !important;
                text-align: center !important;
            }
        }
    </style>
</head>

<body
    style="margin:0; padding:0; width:100%; background-color:#eef1f5; -webkit-font-smoothing:antialiased; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

    {{-- The preview line inboxes show beside the subject. Hidden in the body
         itself by the zero dimensions. --}}
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">
        @yield('preview', $company['name'].' notification')
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color:#eef1f5;">
        <tr>
            <td align="center" style="padding:32px 12px;">

                <table role="presentation" class="email-wrapper" width="600" cellpadding="0" cellspacing="0" border="0"
                    style="width:600px; max-width:600px;">

                    {{-- Header: logo, company name, tagline. --}}
                    <tr>
                        <td align="center" class="email-pad"
                            style="background-color:{{ $company['colors']['header'] }}; border-radius:10px 10px 0 0; padding:32px 40px 26px 40px;">
                            @if ($company['logo'])
                                <img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}" width="64" height="64"
                                    style="display:block; width:64px; height:auto; border:0; margin:0 auto 14px auto;">
                            @endif
                            <div
                                style="color:#ffffff; font-size:22px; font-weight:700; letter-spacing:0.3px; line-height:1.3;">
                                {{ $company['name'] }}
                            </div>
                            @if ($company['tagline'])
                                <div style="color:#c7d6ea; font-size:13px; margin-top:5px; line-height:1.4;">
                                    {{ $company['tagline'] }}
                                </div>
                            @endif
                        </td>
                    </tr>

                    {{-- Body. --}}
                    <tr>
                        <td class="email-card email-pad"
                            style="background-color:#ffffff; padding:36px 40px 30px 40px; color:#243447; font-size:15px; line-height:1.65;">

                            <h1 class="email-heading"
                                style="margin:0 0 20px 0; font-size:22px; line-height:1.35; font-weight:700; color:#132235;">
                                @yield('heading')
                            </h1>

                            @yield('content')

                            @hasSection('action')
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:30px 0 6px 0;">
                                    <tr>
                                        <td>@yield('action')</td>
                                    </tr>
                                </table>
                            @endif

                        </td>
                    </tr>

                    {{-- Footer: company contact details. --}}
                    <tr>
                        <td class="email-pad"
                            style="background-color:#f6f8fb; border-top:1px solid #e2e8f0; border-radius:0 0 10px 10px; padding:24px 40px 28px 40px; color:#63748a; font-size:12px; line-height:1.7; text-align:center;">
                            <div style="font-weight:600; color:#3d4d61;">{{ $company['name'] }}</div>

                            @if ($company['address'])
                                <div>{{ $company['address'] }}</div>
                            @endif

                            @if ($company['phone'] || $company['email'])
                                <div>
                                    @if ($company['phone'])
                                        {{ $company['phone'] }}
                                    @endif
                                    @if ($company['phone'] && $company['email'])
                                        &nbsp;&middot;&nbsp;
                                    @endif
                                    @if ($company['email'])
                                        <a href="mailto:{{ $company['email'] }}"
                                            style="color:{{ $company['colors']['primary'] }}; text-decoration:none;">{{ $company['email'] }}</a>
                                    @endif
                                </div>
                            @endif

                            @if ($company['website'])
                                <div>
                                    <a href="{{ $company['website'] }}"
                                        style="color:{{ $company['colors']['primary'] }}; text-decoration:none;">{{ $company['website'] }}</a>
                                </div>
                            @endif

                            <div style="margin-top:14px; color:#8494a7;">
                                This is an automated message from {{ $company['name'] }}. Please do not reply to it.
                            </div>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>

</html>
