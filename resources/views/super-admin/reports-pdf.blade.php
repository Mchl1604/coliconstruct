<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>{{ $reportTitle }}</title>
    {{--
        The PDF half of the report. The markup for the sections and the
        sign-off is shared with the on-screen preview - see
        partials/report-sections.blade.php - so the two say the same thing in
        the same order. Only the styling is written twice, because dompdf and
        a browser do not read the same CSS: no flexbox, no CSS variables, and
        the repeated header and footer are fixed boxes rather than @page
        margin content.

        Formal on purpose: ruled tables, one ink, and statuses spelled out.
        There are no status colours here any more - the report is filed and
        photocopied, and a colour that carries meaning stops carrying it the
        first time somebody prints it in black and white.
    --}}
    <style>
        @page {
            margin: 118px 28px 64px 28px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
        }

        /* Repeated on every page by dompdf because of the fixed position. */
        header {
            position: fixed;
            top: -100px;
            left: 0;
            right: 0;
            height: 94px;
        }

        footer {
            position: fixed;
            bottom: -48px;
            left: 0;
            right: 0;
            height: 30px;
            border-top: 1px solid #cbd5e1;
            padding-top: 5px;
            font-size: 8px;
            color: #475569;
        }

        /* dompdf resolves counter(page) without needing PHP enabled. */
        .page-number:after {
            content: counter(page);
        }

        .brand-row {
            width: 100%;
            border-bottom: 1.5px solid #334155;
            padding-bottom: 6px;
        }

        .brand-logo {
            height: 38px;
        }

        .company-name {
            font-size: 15px;
            font-weight: bold;
            color: #0f172a;
        }

        .company-meta {
            font-size: 8px;
            color: #475569;
        }

        .report-title {
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            color: #0f172a;
            text-align: right;
        }

        .report-meta {
            font-size: 8px;
            color: #475569;
            text-align: right;
        }

        h2.section {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            color: #0f172a;
            border-bottom: 1px solid #94a3b8;
            padding-bottom: 3px;
            margin: 14px 0 7px;
        }

        h3.group {
            font-size: 9.5px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1f2937;
            background: #f1f5f9;
            border-left: 3px solid #64748b;
            padding: 4px 7px;
            margin: 10px 0 0;
        }

        h3.group .position {
            color: #475569;
            font-weight: normal;
            text-transform: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        /* Ruled on all four sides: a formal table is a grid, not a list with
           faint separators. */
        table.data {
            border: 1px solid #334155;
        }

        table.data th {
            background: #f1f5f9;
            color: #0f172a;
            font-size: 8px;
            font-weight: bold;
            text-align: left;
            padding: 5px 6px;
            border: 1px solid #334155;
        }

        table.data td {
            font-size: 8.5px;
            padding: 4px 6px;
            border: 1px solid #cbd5e1;
            vertical-align: top;
            /* Every column reads from the same left edge, counts included. */
            text-align: left;
            /* Long client names wrap; reference numbers are never cut. */
            word-wrap: break-word;
        }

        .nowrap {
            white-space: nowrap;
        }

        /* One stacked value per line, with room between them. */
        .stacked {
            padding: 1px 0;
        }

        /* How many bookings a duration was summed from, under the figure. */
        .sub {
            font-size: 7.5px;
            color: #475569;
            font-weight: normal;
        }

        /* The summary belongs to the table above it, so it is attached to it
           rather than floated off onto a page of its own. */
        .summary {
            border: 1px solid #334155;
            border-top: none;
            background: #f8fafc;
            padding: 6px 9px;
            margin-top: 0;
            page-break-inside: avoid;
        }

        .summary-title {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 3px;
        }

        .summary td {
            font-size: 8.5px;
            padding: 1px 10px 1px 0;
            width: 25%;
            text-align: left;
        }

        .summary .value {
            font-weight: bold;
            color: #0f172a;
        }

        .muted {
            color: #64748b;
            font-style: italic;
        }

        .empty-notice {
            border: 1px solid #94a3b8;
            background: #f8fafc;
            color: #334155;
            padding: 14px;
            text-align: center;
            font-size: 9.5px;
            margin: 10px 0;
        }

        /* ---------------- Sign-off ---------------- */

        .signature-block {
            margin-top: 34px;
            page-break-inside: avoid;
        }

        .signature-table {
            width: 100%;
        }

        .signature-cell {
            width: 200px;
            vertical-align: bottom;
        }

        .signature-label {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #334155;
        }

        /* Room to actually sign in - the rule is well clear of the printed
           name below it. */
        .signature-space {
            height: 46px;
        }

        .signature-line {
            border-bottom: 1px solid #334155;
            font-size: 1px;
        }

        .signature-name {
            font-size: 10px;
            font-weight: bold;
            color: #0f172a;
            padding-top: 3px;
        }

        .signature-role {
            font-size: 8px;
            color: #475569;
        }
    </style>
</head>

<body>

    <header>
        <table class="brand-row">
            <tr>
                <td style="width: 55%;">
                    @if ($logoData)
                        <img src="{{ $logoData }}" alt="" class="brand-logo">
                    @endif
                    <div class="company-name">{{ $company['name'] }}</div>
                    <div class="company-meta">
                        {{ $company['address'] }}<br>
                        {{ $company['system'] }}
                    </div>
                </td>
                <td style="width: 45%;">
                    <div class="report-title">{{ $reportTitle }}</div>
                    <div class="report-meta">
                        Reporting Period: {{ $period['label'] }}
                        ({{ $period['start']->format(\App\Support\BusinessTime::DATE) }} &ndash; {{ $period['end']->format(\App\Support\BusinessTime::DATE) }})<br>
                        @foreach ($appliedFilters as $filterLabel => $filterValue)
                            {{ $filterLabel }}: {{ $filterValue }}<br>
                        @endforeach
                        Generated By: {{ $generatedBy }}<br>
                        Generated: {{ \App\Support\BusinessTime::format($generatedAt, \App\Support\BusinessTime::DATE_TIME) }}
                    </div>
                </td>
            </tr>
        </table>
    </header>

    <footer>
        <table>
            <tr>
                <td style="width: 40%;">{{ $company['name'] }}</td>
                <td style="width: 35%; text-align: center;">
                    Archived projects are excluded from this report.
                </td>
                <td style="width: 25%; text-align: right;">
                    Page <span class="page-number"></span>
                </td>
            </tr>
        </table>
    </footer>

    <main>
        @include('super-admin.partials.report-sections', ['report' => $report])

        @include('super-admin.partials.report-signature', ['generatedBy' => $generatedBy])
    </main>
</body>

</html>
