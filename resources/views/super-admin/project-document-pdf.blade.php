{{-- One project document - an assessment report, a quotation or a service
     contract - on the company letterhead.

     Rendered by DemoDataSeeder so the demonstration projects carry files that
     open like the real thing. Same letterhead and rules as the reports and the
     activity log, so it reads as coming out of the same office.

     Set in Helvetica, one of the fonts every PDF reader already has, rather
     than the embedded DejaVu the reports use: the seeder renders about 120 of
     these in one go, and embedding a font is most of the time each one takes.
     Everything printed here - ñ and ° included - is in Helvetica's character
     set. --}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>{{ $title }} - {{ $project['reference_no'] }}</title>
    <style>
        @page {
            margin: 118px 40px 60px 40px;
        }

        body {
            font-family: Helvetica, sans-serif;
            font-size: 9.5px;
            color: #1e293b;
            margin: 0;
            line-height: 1.45;
        }

        header {
            position: fixed;
            top: -100px;
            left: 0;
            right: 0;
            height: 94px;
        }

        footer {
            position: fixed;
            bottom: -44px;
            left: 0;
            right: 0;
            height: 30px;
            border-top: 1px solid #e5e7eb;
            padding-top: 5px;
            font-size: 8px;
            color: #64748b;
        }

        .page-number:after {
            content: counter(page);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .brand-row {
            border-bottom: 2px solid #2563eb;
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
            color: #64748b;
        }

        .doc-title {
            font-size: 16px;
            font-weight: bold;
            color: #2563eb;
            text-align: right;
            text-transform: uppercase;
        }

        .doc-meta {
            font-size: 8.5px;
            color: #475569;
            text-align: right;
        }

        h2 {
            font-size: 10.5px;
            text-transform: uppercase;
            color: #0f172a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 3px;
            margin: 16px 0 6px;
        }

        .details td {
            padding: 2px 0;
            vertical-align: top;
        }

        .details .label {
            width: 26%;
            color: #64748b;
        }

        table.items th {
            background: #1e293b;
            color: #ffffff;
            font-size: 8.5px;
            text-align: left;
            padding: 5px 6px;
        }

        table.items td {
            padding: 5px 6px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        table.items .amount {
            text-align: right;
            white-space: nowrap;
            width: 24%;
        }

        table.items tr.total td {
            font-weight: bold;
            border-top: 2px solid #1e293b;
            border-bottom: none;
        }

        ul {
            margin: 0;
            padding-left: 16px;
        }

        li {
            margin-bottom: 3px;
        }

        .signatures {
            margin-top: 34px;
        }

        .signatures td {
            width: 50%;
            padding-right: 30px;
            vertical-align: bottom;
        }

        .signature-line {
            border-top: 1px solid #334155;
            padding-top: 3px;
            margin-top: 34px;
        }

        .muted {
            color: #64748b;
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
                    <div class="company-meta">{{ $company['address'] }}</div>
                </td>
                <td style="width: 45%;">
                    <div class="doc-title">{{ $title }}</div>
                    <div class="doc-meta">
                        No. {{ $number }}<br>
                        Date: {{ $issuedOn }}<br>
                        Project Ref.: {{ $project['reference_no'] }}
                    </div>
                </td>
            </tr>
        </table>
    </header>

    <footer>
        <table>
            <tr>
                <td style="width: 60%;">{{ $company['name'] }} &middot; {{ $title }} {{ $number }}</td>
                <td style="width: 40%; text-align: right;">Page <span class="page-number"></span></td>
            </tr>
        </table>
    </footer>

    <main>
        <h2>{{ $kind === 'contract' ? 'Parties' : 'Client' }}</h2>
        <table class="details">
            @if ($client['company'])
                <tr><td class="label">Company</td><td>{{ $client['company'] }}</td></tr>
            @endif
            <tr><td class="label">{{ $client['company'] ? 'Represented by' : 'Name' }}</td><td>{{ $client['name'] }}</td></tr>
            <tr><td class="label">Address</td><td>{{ $client['address'] }}</td></tr>
            <tr><td class="label">Contact</td><td>{{ $client['contact_number'] }} &middot; {{ $client['email'] }}</td></tr>
            @if ($kind === 'contract')
                <tr><td class="label">Contractor</td><td>{{ $company['name'] }}, {{ $company['address'] }}</td></tr>
            @endif
        </table>

        <h2>Project</h2>
        <table class="details">
            <tr><td class="label">Project</td><td>{{ $project['name'] }}</td></tr>
            <tr><td class="label">Site address</td><td>{{ $project['address'] }}</td></tr>
            <tr><td class="label">Type of work</td><td>{{ $types }}</td></tr>
            <tr><td class="label">Scope</td><td>{{ $project['description'] }}</td></tr>
            @if ($kind !== 'assessment')
                <tr><td class="label">Target completion</td><td>{{ $targetDate }}</td></tr>
            @endif
        </table>

        @if ($kind === 'assessment')
            <h2>Findings</h2>
            <ul>
                @foreach ($findings as $finding)
                    <li>{{ $finding }}</li>
                @endforeach
            </ul>

            <h2>Recommendation</h2>
            <p>{{ $recommendation }}</p>

            <h2>Estimated Work Duration</h2>
            <p>{{ $duration }}</p>
        @else
            <h2>{{ $kind === 'contract' ? 'Contract Price' : 'Cost Breakdown' }}</h2>
            <table class="items">
                <thead>
                    <tr>
                        <th style="width: 6%;">#</th>
                        <th>Description</th>
                        <th class="amount">Amount (PHP)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $index => $item)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $item['description'] }}</td>
                            <td class="amount">{{ number_format($item['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td></td>
                        <td>Total, VAT inclusive</td>
                        <td class="amount">{{ number_format($total, 2) }}</td>
                    </tr>
                </tbody>
            </table>

            <h2>Terms</h2>
            <ul>
                @if ($kind === 'quotation')
                    <li>This quotation is valid for 30 days from the date above.</li>
                @endif
                <li>Payment: 50% down payment upon confirmation, 40% on progress billing, and 10% upon turnover.</li>
                <li>Workmanship is warranted for one (1) year from turnover. Equipment carries the manufacturer's warranty.</li>
                <li>Work is done Monday to Saturday, 8:00 AM to 5:00 PM, unless a different schedule is agreed with the client.</li>
                @if ($kind === 'contract')
                    <li>Changes to the scope are made in writing and may change the contract price and schedule.</li>
                    <li>The contractor carries out the work in line with the Philippine Mechanical Code and building administration rules.</li>
                @endif
            </ul>
        @endif

        <table class="signatures">
            <tr>
                <td>
                    <div class="signature-line">
                        <strong>{{ $preparedBy['name'] }}</strong><br>
                        <span class="muted">{{ $preparedBy['position'] }}, {{ $company['name'] }}</span>
                    </div>
                </td>
                <td>
                    <div class="signature-line">
                        <strong>{{ $client['name'] }}</strong><br>
                        <span class="muted">{{ $kind === 'assessment' ? 'Client' : 'Conforme' }}{{ $client['company'] ? ', '.$client['company'] : '' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </main>
</body>

</html>
