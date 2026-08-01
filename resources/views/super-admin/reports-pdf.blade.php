@php
    // Titles for the rasterised charts the browser posted, in print order.
    $chartTitles = [
        'currentProjectBreakdown' => 'Current Project Breakdown',
        'completedProjects' => 'Completed Projects',
        'projectsByType' => 'Projects by Project Type',
        'residentialVsCommercial' => 'Residential vs Commercial Projects',
        'totalQuotation' => 'Total Quotation',
        'topClients' => 'Top 10 Clients by Total Quotation',
    ];

    // Which charts belong to which report type, so a Project Report doesn't
    // carry quotation graphs. The technician, schedule and task reports have
    // no graphs of their own; their tables carry the detail.
    $chartGroups = [
        'projects' => ['currentProjectBreakdown', 'completedProjects', 'projectsByType', 'residentialVsCommercial'],
        'quotations' => ['totalQuotation', 'topClients'],
        'technicians' => [],
        'schedules' => [],
        'tasks' => [],
    ];

    $relevantChartKeys = $reportType === 'complete'
        ? array_keys($chartTitles)
        : ($chartGroups[$reportType] ?? []);

    $printableCharts = collect($relevantChartKeys)
        ->filter(fn($key) => ! empty($charts[$key]))
        ->values();

    $peso = fn($value) => 'PHP ' . number_format((float) $value, 2);
    $count = fn($value) => number_format((int) $value);
@endphp
    <!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>{{ $reportTitle }}</title>
    <style>
        @page {
            margin: 120px 34px 70px 34px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5px;
            color: #1e293b;
            margin: 0;
        }

        /* Repeated on every page by dompdf because of the fixed position. */
        header {
            position: fixed;
            top: -100px;
            left: 0;
            right: 0;
            height: 92px;
        }

        footer {
            position: fixed;
            bottom: -50px;
            left: 0;
            right: 0;
            height: 34px;
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
            height: 40px;
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
            font-size: 13px;
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
            margin: 16px 0 8px;
        }

        h3.subsection {
            font-size: 10px;
            color: #334155;
            margin: 12px 0 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table.data th {
            background: #eff6ff;
            color: #1e293b;
            font-size: 8.5px;
            text-align: left;
            padding: 5px 6px;
            border-bottom: 1px solid #cbd5e1;
        }

        table.data td {
            font-size: 8.5px;
            padding: 4px 6px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }

        table.data tr:nth-child(even) td {
            background: #f8fafc;
        }

        .num {
            text-align: right;
        }

        /* Executive summary tiles, two columns via a plain table so dompdf
           lays them out predictably. */
        table.summary td {
            width: 50%;
            vertical-align: top;
            padding: 0 5px 8px 0;
        }

        .summary-box {
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 7px 9px;
        }

        .summary-box-title {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }

        .summary-line {
            font-size: 8.5px;
            padding: 1px 0;
        }

        .summary-line .value {
            font-weight: bold;
            color: #0f172a;
        }

        .chart-block {
            margin-bottom: 14px;
            page-break-inside: avoid;
        }

        .chart-caption {
            font-size: 9px;
            font-weight: bold;
            color: #334155;
            margin-bottom: 4px;
        }

        .chart-block img {
            width: 100%;
            border: 1px solid #e5e7eb;
        }

        .muted {
            color: #94a3b8;
            font-style: italic;
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
                        Reporting Period: {{ $period['label'] }}<br>
                        {{ $period['start']->format('M j, Y') }} &ndash;
                        {{ $period['end']->format('M j, Y') }}<br>
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
                    Generated {{ $generatedAt->format('M j, Y g:i A') }}
                </td>
                <td style="width: 25%; text-align: right;">
                    Page <span class="page-number"></span>
                </td>
            </tr>
        </table>
    </footer>

    <main>

        {{-- ---------------- Executive summary ---------------- --}}
        <h2 class="section">Executive Summary</h2>

        <table class="summary">
            <tr>
                <td>
                    <div class="summary-box">
                        <div class="summary-box-title">Projects</div>
                        <div class="summary-line">Total: <span class="value">{{ $count($summary['projects']['total']) }}</span></div>
                        <div class="summary-line">Pending: <span class="value">{{ $count($summary['projects']['pending']) }}</span></div>
                        <div class="summary-line">Ongoing: <span class="value">{{ $count($summary['projects']['ongoing']) }}</span></div>
                        <div class="summary-line">Completed: <span class="value">{{ $count($summary['projects']['completed']) }}</span></div>
                        <div class="summary-line">Cancelled: <span class="value">{{ $count($summary['projects']['cancelled']) }}</span></div>
                        <div class="summary-line">Archived: <span class="value">{{ $count($summary['projects']['archived']) }}</span></div>
                        <div class="summary-line">Overdue: <span class="value">{{ $count($summary['projects']['overdue']) }}</span></div>
                    </div>
                </td>
                <td>
                    <div class="summary-box">
                        <div class="summary-box-title">Approved Quotations</div>
                        <div class="summary-line">Total Approved: <span class="value">{{ $count($summary['quotations']['total_approved']) }}</span></div>
                        <div class="summary-line">Total Value: <span class="value">{{ $peso($summary['quotations']['total_value']) }}</span></div>
                        <div class="summary-line">Average Value: <span class="value">{{ $peso($summary['quotations']['average_value']) }}</span></div>
                        <div class="summary-line">Approved This Period: <span class="value">{{ $count($summary['quotations']['created_in_period']) }}</span></div>
                        <div class="summary-line">Value This Period: <span class="value">{{ $peso($summary['quotations']['value_in_period']) }}</span></div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="summary-box">
                        <div class="summary-box-title">Technicians</div>
                        <div class="summary-line">Total: <span class="value">{{ $count($summary['technicians']['total']) }}</span></div>
                        <div class="summary-line">Currently Assigned: <span class="value">{{ $count($summary['technicians']['assigned']) }}</span></div>
                        <div class="summary-line">Available: <span class="value">{{ $count($summary['technicians']['available']) }}</span></div>
                        <div class="summary-line">Utilization Rate: <span class="value">{{ $summary['technicians']['utilization'] }}%</span></div>
                    </div>
                </td>
                <td>
                    <div class="summary-box">
                        <div class="summary-box-title">Schedules</div>
                        <div class="summary-line">Scheduled Projects: <span class="value">{{ $count($summary['schedules']['scheduled_projects']) }}</span></div>
                        <div class="summary-line">Active Schedules: <span class="value">{{ $count($summary['schedules']['active_schedules']) }}</span></div>
                        <div class="summary-line">Overdue Projects: <span class="value">{{ $count($summary['schedules']['overdue_projects']) }}</span></div>
                        <div class="summary-line">Avg Duration: <span class="value">{{ $summary['schedules']['average_duration_days'] }} days</span></div>
                        <div class="summary-line">Schedule Extensions: <span class="value">{{ $count($summary['schedules']['extensions']) }}</span></div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="summary-box">
                        <div class="summary-box-title">Tasks</div>
                        <div class="summary-line">Total: <span class="value">{{ $count($summary['tasks']['total']) }}</span></div>
                        <div class="summary-line">Pending: <span class="value">{{ $count($summary['tasks']['pending']) }}</span></div>
                        <div class="summary-line">In Progress: <span class="value">{{ $count($summary['tasks']['ongoing']) }}</span></div>
                        <div class="summary-line">Completed: <span class="value">{{ $count($summary['tasks']['completed']) }}</span></div>
                        <div class="summary-line">Avg Completion: <span class="value">{{ $summary['tasks']['average_completion_days'] }} days</span></div>
                    </div>
                </td>
                <td></td>
            </tr>
        </table>

        {{-- ---------------- Data tables ---------------- --}}

        @if (isset($tables['projects']))
            <h2 class="section">Projects</h2>
            @if ($tables['projects']->isEmpty())
                <p class="muted">No projects on record.</p>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:18%">Reference No.</th>
                            <th>Project</th>
                            <th style="width:15%">Status</th>
                            <th style="width:15%">Start Date</th>
                            <th style="width:15%">End Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables['projects'] as $row)
                            <tr>
                                <td>{{ $row['reference_no'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['start_date'] }}</td>
                                <td>{{ $row['end_date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif

        @if (isset($tables['technicians']))
            <h2 class="section">Technicians</h2>
            @if ($tables['technicians']->isEmpty())
                <p class="muted">No technicians on record.</p>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:22%">Technician</th>
                            <th style="width:16%">Position</th>
                            <th style="width:14%" class="num">Assigned Projects</th>
                            <th style="width:12%" class="num">Utilization</th>
                            <th>Specialties</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables['technicians'] as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['position'] }}</td>
                                <td class="num">{{ $count($row['assigned_projects']) }}</td>
                                <td class="num">{{ $row['utilization'] }}%</td>
                                <td>{{ $row['specialties'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="muted" style="font-size:8px;">
                    Utilization compares each technician's active project count with the busiest technician.
                </p>
            @endif
        @endif

        @if (isset($tables['schedules']))
            <h2 class="section">Schedules</h2>
            @if ($tables['schedules']->isEmpty())
                <p class="muted">No scheduled projects on record.</p>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:18%">Reference No.</th>
                            <th style="width:22%">Project</th>
                            <th>Current Schedule</th>
                            <th style="width:12%" class="num">Duration</th>
                            <th style="width:13%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables['schedules'] as $row)
                            <tr>
                                <td>{{ $row['reference_no'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['schedule'] }}</td>
                                <td class="num">{{ $count($row['duration_days']) }} days</td>
                                <td>{{ $row['status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif

        @if (isset($tables['tasks']))
            <h2 class="section">Tasks</h2>
            @if ($tables['tasks']->isEmpty())
                <p class="muted">No tasks on record.</p>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:26%">Task</th>
                            <th style="width:24%">Project</th>
                            <th style="width:20%">Technician</th>
                            <th style="width:14%">Status</th>
                            <th style="width:16%">Completion Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables['tasks'] as $row)
                            <tr>
                                <td>{{ $row['title'] }}</td>
                                <td>{{ $row['project'] }}</td>
                                <td>{{ $row['technician'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['completion_date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif

        @if (isset($tables['quotations']))
            <h2 class="section">Approved Quotations</h2>
            @if ($tables['quotations']->isEmpty())
                <p class="muted">No approved quotations on record.</p>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:20%">Quotation No.</th>
                            <th style="width:24%">Project</th>
                            <th>Client</th>
                            <th style="width:15%">Date Approved</th>
                            <th style="width:17%" class="num">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables['quotations'] as $row)
                            <tr>
                                <td>{{ $row['reference_no'] }}</td>
                                <td>{{ $row['project'] }}</td>
                                <td>{{ $row['client'] }}</td>
                                <td>{{ $row['date_approved'] }}</td>
                                <td class="num">{{ $peso($row['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" style="text-align:right;">Total</th>
                            <th class="num">{{ $peso($tables['quotations']->sum('amount')) }}</th>
                        </tr>
                    </tfoot>
                </table>
                <p class="muted" style="font-size:8px;">
                    The system stores approved quotations only, as the amount on each project, so the project
                    reference number identifies the quotation.
                </p>
            @endif
        @endif

        {{-- ---------------- Graphs ---------------- --}}

        @if ($printableCharts->isNotEmpty())
            <h2 class="section" style="page-break-before: always;">Graphs</h2>

            @foreach ($printableCharts as $key)
                <div class="chart-block">
                    <div class="chart-caption">{{ $chartTitles[$key] }}</div>
                    <img src="{{ $charts[$key] }}" alt="{{ $chartTitles[$key] }}">
                </div>
            @endforeach
        @endif

    </main>
</body>

</html>
