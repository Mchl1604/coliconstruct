@php
    /**
     * A status printed the way the application shows it on screen: the same
     * fill, and ink chosen to stay readable on it. Both come from
     * Project::STATUS_COLORS so there is one colour system, not two.
     */
    $badge = function (?string $key, ?string $label): string {
        [$background, $ink] = \App\Models\Project::statusColor((string) $key);

        return sprintf(
            '<span class="badge" style="background:%s;color:%s;">%s</span>',
            $background,
            $ink,
            e($label ?: '—')
        );
    };

    /** Several values in one cell, stacked rather than run together. */
    $stack = function (array $values, string $empty = '—'): string {
        if ($values === []) {
            return '<span class="muted">' . e($empty) . '</span>';
        }

        return implode('', array_map(fn($value) => '<div class="stacked">' . e($value) . '</div>', $values));
    };
@endphp
    <!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>{{ $reportTitle }}</title>
    <style>
        @page {
            margin: 118px 28px 60px 28px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1e293b;
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
            bottom: -44px;
            left: 0;
            right: 0;
            height: 30px;
            border-top: 1px solid #e5e7eb;
            padding-top: 5px;
            font-size: 8px;
            color: #64748b;
        }

        /* dompdf resolves counter(page) without needing PHP enabled. */
        .page-number:after {
            content: counter(page);
        }

        .brand-row {
            width: 100%;
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

        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #2563eb;
            text-align: right;
        }

        .report-meta {
            font-size: 8px;
            color: #475569;
            text-align: right;
        }

        h2.section {
            font-size: 11px;
            color: #0f172a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 3px;
            margin: 14px 0 7px;
        }

        h3.group {
            font-size: 9.5px;
            color: #1d4ed8;
            background: #eff6ff;
            padding: 4px 7px;
            margin: 10px 0 0;
        }

        h3.group .position {
            color: #64748b;
            font-weight: normal;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table.data th {
            background: #1e293b;
            color: #ffffff;
            font-size: 8px;
            text-align: left;
            padding: 5px 6px;
        }

        table.data td {
            font-size: 8.5px;
            padding: 4px 6px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
            /* Long client names wrap; reference numbers are never cut. */
            word-wrap: break-word;
        }

        table.data tr:nth-child(even) td {
            background: #f8fafc;
        }

        .nowrap {
            white-space: nowrap;
        }

        .num {
            text-align: right;
        }

        /* One stacked value per line, with room between them. */
        .stacked {
            padding: 1px 0;
        }

        /* How many bookings a duration was summed from, under the figure. */
        .sub {
            font-size: 7.5px;
            color: #64748b;
            font-weight: normal;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 7px;
            font-size: 7.5px;
            font-weight: bold;
        }

        /* The summary belongs to the table above it, so it is attached to it
           rather than floated off onto a page of its own. */
        .summary {
            border: 1px solid #cbd5e1;
            border-top: 2px solid #2563eb;
            background: #f8fafc;
            padding: 6px 9px;
            margin-top: 0;
            page-break-inside: avoid;
        }

        .summary-title {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 3px;
        }

        .summary td {
            font-size: 8.5px;
            padding: 1px 10px 1px 0;
            width: 25%;
        }

        .summary .value {
            font-weight: bold;
            color: #0f172a;
        }

        .muted {
            color: #94a3b8;
            font-style: italic;
        }

        .empty-notice {
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            color: #64748b;
            padding: 14px;
            text-align: center;
            font-size: 9.5px;
            margin: 10px 0;
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
                        ({{ $period['start']->format('M j, Y') }} &ndash; {{ $period['end']->format('M j, Y') }})<br>
                        @foreach ($appliedFilters as $filterLabel => $filterValue)
                            {{ $filterLabel }}: {{ $filterValue }}<br>
                        @endforeach
                        Generated By: {{ $generatedBy }}<br>
                        Generated: {{ $generatedAt->format('M j, Y g:i A') }}
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

        @if ($report['is_empty'])
            <div class="empty-notice">
                No records found for the selected reporting period and filters.
            </div>
        @endif

        @foreach ($report['sections'] as $section)
            <h2 class="section">{{ $section['title'] }}</h2>

            @php
                $rows = $section['rows'] ?? collect();
                $groups = $section['groups'] ?? collect();
                $sectionEmpty = $rows->isEmpty() && $groups->isEmpty();
            @endphp

            @if ($sectionEmpty)
                <p class="muted">No records for this section.</p>
            @else

                {{-- ---------------- Project Report ---------------- --}}
                @if ($section['key'] === 'projects')
                    <table class="data">
                        <thead>
                            <tr>
                                <th style="width:12%">Reference No.</th>
                                <th style="width:20%">Client</th>
                                <th style="width:10%">Client Type</th>
                                <th style="width:20%">Project Type</th>
                                <th style="width:13%">Status</th>
                                <th>Schedules</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="nowrap">{{ $row['reference_no'] }}</td>
                                    <td>{{ $row['client'] }}</td>
                                    <td>{{ $row['client_type'] }}</td>
                                    <td>{!! $stack($row['project_types'], 'No Project Type') !!}</td>
                                    <td>{!! $badge($row['status_key'], $row['status_label']) !!}</td>
                                    <td>{!! $stack($row['schedules'], 'No Schedule') !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- ---------------- Schedule Report ---------------- --}}
                @if ($section['key'] === 'schedules')
                    <table class="data">
                        <thead>
                            <tr>
                                <th style="width:14%">Reference No.</th>
                                <th style="width:26%">Client</th>
                                <th style="width:28%">Schedule</th>
                                <th style="width:14%" class="num">Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="nowrap">{{ $row['reference_no'] }}</td>
                                    <td>{{ $row['client'] }}</td>
                                    {{-- Every range that touches the period, in date order. --}}
                                    <td>{!! $stack($row['schedules'], 'No Schedule') !!}</td>
                                    <td class="num nowrap">
                                        {{ $row['duration'] }} {{ $row['duration'] === 1 ? 'day' : 'days' }}
                                        @if ($row['entries'] > 1)
                                            <div class="sub">{{ $row['entries'] }} bookings</div>
                                        @endif
                                    </td>
                                    <td>{!! $badge($row['status_key'], $row['status_label']) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- ---------------- Assigned Projects ---------------- --}}
                @if ($section['key'] === 'assigned')
                    <table class="data">
                        <thead>
                            <tr>
                                <th style="width:24%">Technician</th>
                                <th style="width:18%">Position</th>
                                <th>Assigned Projects</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ $row['technician'] }}</td>
                                    <td>{{ $row['position'] }}</td>
                                    <td>{!! $stack($row['projects'], 'No Assigned Projects') !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- ---------------- Technician schedules ---------------- --}}
                @if ($section['key'] === 'technician_schedule')
                    @foreach ($groups as $group)
                        <h3 class="group">
                            {{ $group['technician'] }}
                            <span class="position">&mdash; {{ $group['position'] }}</span>
                        </h3>
                        <table class="data">
                            <thead>
                                <tr>
                                    <th style="width:14%">Reference No.</th>
                                    <th style="width:26%">Client</th>
                                    <th style="width:28%">Schedule</th>
                                    <th style="width:14%" class="num">Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group['rows'] as $row)
                                    <tr>
                                        <td class="nowrap">{{ $row['reference_no'] }}</td>
                                        <td>{{ $row['client'] }}</td>
                                        <td>{!! $stack($row['schedules'], 'No Schedule') !!}</td>
                                        <td class="num nowrap">
                                            {{ $row['duration'] }} {{ $row['duration'] === 1 ? 'day' : 'days' }}
                                            @if ($row['entries'] > 1)
                                                <div class="sub">{{ $row['entries'] }} bookings</div>
                                            @endif
                                        </td>
                                        <td>{!! $badge($row['status_key'], $row['status_label']) !!}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endforeach
                @endif

                {{-- ---------------- Technician tasks ---------------- --}}
                @if ($section['key'] === 'technician_tasks')
                    @foreach ($groups as $group)
                        <h3 class="group">
                            {{ $group['technician'] }}
                            <span class="position">&mdash; {{ $group['position'] }}</span>
                        </h3>
                        <table class="data">
                            <thead>
                                <tr>
                                    <th style="width:12%">Reference No.</th>
                                    <th style="width:20%">Client</th>
                                    <th style="width:26%">Task</th>
                                    <th style="width:12%">Start Date</th>
                                    <th style="width:12%">Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group['rows'] as $row)
                                    <tr>
                                        <td class="nowrap">{{ $row['reference_no'] }}</td>
                                        <td>{{ $row['client'] }}</td>
                                        <td>{{ $row['task'] }}</td>
                                        <td class="nowrap">{{ $row['start_date'] }}</td>
                                        <td class="nowrap">{{ $row['due_date'] }}</td>
                                        <td>{!! $badge($row['status_key'], $row['status_label']) !!}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endforeach
                @endif
            @endif

            {{-- The section's own summary, directly under its own table. --}}
            <div class="summary">
                <div class="summary-title">{{ $section['title'] }} Summary</div>
                <table>
                    @foreach (collect($section['summary'])->chunk(4) as $line)
                        <tr>
                            @foreach ($line as $item)
                                <td>{{ $item['label'] }}: <span class="value">{{ $item['value'] }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach

    </main>
</body>

</html>
