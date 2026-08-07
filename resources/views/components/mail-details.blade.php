{{--
    The label/value block system emails use for account and project details.

    @param array<string, string|null> $rows  Empty values are dropped rather
                                             than printed as a blank line.
--}}
@props(['rows' => []])

@php
    $rows = array_filter($rows, fn ($value) => filled($value));
@endphp

@if ($rows !== [])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:22px 0; background-color:#f6f8fb; border:1px solid #e2e8f0; border-radius:8px;">
        @foreach ($rows as $label => $value)
            <tr>
                <td
                    style="padding:11px 18px; font-size:13px; color:#63748a; white-space:nowrap; border-bottom:{{ $loop->last ? 'none' : '1px solid #e8edf3' }};">
                    {{ $label }}
                </td>
                <td
                    style="padding:11px 18px; font-size:14px; color:#1d2b3a; font-weight:600; text-align:right; border-bottom:{{ $loop->last ? 'none' : '1px solid #e8edf3' }};">
                    {{ $value }}
                </td>
            </tr>
        @endforeach
    </table>
@endif
