{{--
    The call to action in a system email.

    Built as a bordered table cell rather than a styled <a>: Outlook renders
    through Word, which drops padding on inline elements, and the cell keeps
    the button a button there too.
--}}
@props(['url', 'color' => null])

@php
    $background = $color ?: config('company.colors.primary');
@endphp

<table role="presentation" class="email-button" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center" style="background-color:{{ $background }}; border-radius:6px;">
            <a href="{{ $url }}"
                style="display:inline-block; padding:13px 30px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:6px;">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
