<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>PDF Environment Check</title>
    {{--
        The document `php artisan pdf:check` renders. Deliberately built from
        the same ingredients a real report is - the bundled DejaVu font, a
        ruled left-aligned table, and the letterhead image - so a document that
        comes out of here proves the exports will too.
    --}}
    <style>
        @page {
            margin: 28px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
        }

        h1 {
            font-size: 14px;
            text-transform: uppercase;
            margin: 0 0 4px;
        }

        .meta {
            font-size: 8px;
            color: #475569;
            margin-bottom: 12px;
        }

        .brand-logo {
            height: 38px;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #334155;
        }

        table.data th,
        table.data td {
            text-align: left;
            padding: 5px 6px;
            border: 1px solid #cbd5e1;
            vertical-align: top;
        }

        table.data th {
            background: #f1f5f9;
            font-weight: bold;
            border-color: #334155;
        }
    </style>
</head>

<body>

    @php($logo = \App\Support\CompanyBranding::logoDataUri())

    @if ($logo)
        <img src="{{ $logo }}" alt="" class="brand-logo">
    @endif

    <h1>PDF Environment Check</h1>

    <div class="meta">
        {{ \App\Support\CompanyBranding::letterhead()['name'] }} &mdash;
        rendered {{ $generatedAt->format('Y-m-d H:i:s') }}
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width:30%">Setting</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($diagnostics as $key => $value)
                <tr>
                    <td>{{ $key }}</td>
                    <td>
                        @if (is_bool($value))
                            {{ $value ? 'yes' : 'no' }}
                        @else
                            {{ $value ?? '—' }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

</body>

</html>
