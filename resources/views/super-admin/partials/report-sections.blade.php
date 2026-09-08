@php
    /**
     * The body of an exported report: every section, its table, and its
     * summary. Shared by the on-screen preview and the dompdf export so the
     * two cannot drift - what the reviewer approves on screen is what the
     * printer and the PDF put on paper.
     *
     * Only the markup lives here. The preview styles it from
     * css/super-admin/report-print.css and the PDF from its own inline sheet,
     * because dompdf and a browser do not read the same CSS.
     *
     * Statuses print as plain words. A formal report is read on paper and
     * photocopied in black and white, so meaning carried by a fill colour is
     * meaning that does not survive the trip.
     */

    /** Several values in one cell, stacked rather than run together. */
    $stack = function (array $values, string $empty = '—'): string {
        if ($values === []) {
            return '<span class="muted">' . e($empty) . '</span>';
        }

        return implode('', array_map(fn($value) => '<div class="stacked">' . e($value) . '</div>', $values));
    };
@endphp

@if ($report['is_empty'])
    <div class="empty-notice">
        No records found for the selected reporting period and filters.
    </div>
@endif

@foreach ($report['sections'] as $section)
    @php
        $rows = $section['rows'] ?? collect();
        $groups = $section['groups'] ?? collect();
        // The technician schedule arrives split into Past and Future rather
        // than as one list; it has something to print when either half does.
        $subsections = $section['subsections'] ?? collect();
        $sectionEmpty =
            $rows->isEmpty() &&
            $groups->isEmpty() &&
            $subsections->every(fn($subsection) => $subsection['rows']->isEmpty());
    @endphp

    <h2 class="section">{{ $section['title'] }}</h2>

    @if ($sectionEmpty)
        <p class="muted">No records for this section.</p>
    @else

        {{-- ---------------- Project Report ---------------- --}}
        @if ($section['key'] === 'projects')
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:11%">Reference No.</th>
                        {{-- When the job arrived, beside the reference it
                             arrived under. Same format as every other date in
                             this report. --}}
                        <th style="width:10%">Created</th>
                        <th style="width:18%">Client</th>
                        <th style="width:9%">Client Type</th>
                        <th style="width:18%">Project Type</th>
                        <th style="width:12%">Status</th>
                        <th>Schedules</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="nowrap">{{ $row['reference_no'] }}</td>
                            <td class="nowrap">{{ $row['created_on'] }}</td>
                            <td>{{ $row['client'] }}</td>
                            <td>{{ $row['client_type'] }}</td>
                            <td>{!! $stack($row['project_types'], 'No Project Type') !!}</td>
                            <td>{{ $row['status_label'] ?: '—' }}</td>
                            <td>{!! $stack($row['schedules'], 'No Schedule') !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- ------------- New Projects Report ------------- --}}
        @if ($section['key'] === 'new_projects')
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:12%">Reference No.</th>
                        <th style="width:11%">Opened</th>
                        <th style="width:20%">Client</th>
                        <th style="width:10%">Client Type</th>
                        <th style="width:20%">Project Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="nowrap">{{ $row['reference_no'] }}</td>
                            <td class="nowrap">{{ $row['opened_on'] }}</td>
                            <td>{{ $row['client'] }}</td>
                            <td>{{ $row['client_type'] }}</td>
                            <td>{!! $stack($row['project_types'], 'No Project Type') !!}</td>
                            <td>{{ $row['status_label'] ?: '—' }}</td>
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
                            <td>{{ $row['status_label'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- ---------------- Assigned Projects ---------------- --}}
        {{-- One row per technician per project. Assignment Status and Removed
             Date come from the membership history; Schedule is the dates that
             technician actually held, never the project's range. The project's
             own status is the last column and is a different fact from the
             technician's - a completed project can carry an active
             assignment. --}}
        @if ($section['key'] === 'assigned')
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:16%">Technician</th>
                        <th style="width:12%">Reference No.</th>
                        <th style="width:18%">Client</th>
                        <th style="width:10%">Assignment Status</th>
                        <th style="width:11%">Removed Date</th>
                        <th style="width:20%">Schedule</th>
                        <th>Project Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>
                                {{ $row['technician'] }}
                                <div class="sub">{{ $row['position'] }}</div>
                            </td>
                            <td class="nowrap">{{ $row['reference_no'] }}</td>
                            <td>{{ $row['client'] }}</td>
                            <td>{{ $row['is_removed'] ? 'Removed' : 'Active' }}</td>
                            <td class="nowrap">{{ $row['removed_on'] }}</td>
                            <td>{!! $stack($row['schedules'], 'No scheduled dates') !!}</td>
                            <td>{{ $row['status_label'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- ---------------- Technician schedules ---------------- --}}
        {{-- Two tables: what has been worked, and what is booked. Each row is
             a run of consecutive dates one technician was actually assigned
             for, never the project's range handed out to whoever is on the
             team - see TechnicianAssignedDates. Both halves are printed even
             when empty, so a report that shows no Past Schedule is saying
             there was none rather than leaving the reader to wonder which half
             they are looking at. --}}
        @if ($section['key'] === 'technician_schedule')
            @foreach ($subsections as $subsection)
                <h3 class="group">{{ strtoupper($subsection['title']) }}</h3>

                @if ($subsection['rows']->isEmpty())
                    <p class="muted">
                        No {{ strtolower($subsection['title']) }} for this reporting period.
                    </p>
                @else
                    <table class="data">
                        <thead>
                            <tr>
                                <th style="width:18%">Technician</th>
                                <th style="width:13%">Reference No.</th>
                                <th style="width:22%">Client</th>
                                <th style="width:23%">Schedule</th>
                                <th style="width:11%" class="num">Scheduled Days</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subsection['rows'] as $row)
                                <tr>
                                    <td>
                                        {{ $row['technician'] }}
                                        <div class="sub">{{ $row['position'] }}</div>
                                    </td>
                                    <td class="nowrap">{{ $row['reference_no'] }}</td>
                                    <td>{{ $row['client'] }}</td>
                                    <td>{{ $row['schedule'] }}</td>
                                    <td class="num nowrap">
                                        {{ $row['duration'] }} {{ $row['duration'] === 1 ? 'day' : 'days' }}
                                    </td>
                                    <td>{{ $row['status_label'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
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
                            <th style="width:11%">Reference No.</th>
                            <th style="width:18%">Client</th>
                            <th style="width:23%">Task</th>
                            {{-- Which stage of the project the work sits in.
                                 The number is what the column is for; the
                                 title under it says what that stage is. --}}
                            <th style="width:12%">Phase</th>
                            <th style="width:11%">Start Date</th>
                            <th style="width:11%">Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['rows'] as $row)
                            <tr>
                                <td class="nowrap">{{ $row['reference_no'] }}</td>
                                <td>{{ $row['client'] }}</td>
                                <td>{{ $row['task'] }}</td>
                                <td>
                                    @if ($row['phase_number'])
                                        {{ $row['phase_number'] }}
                                        <div class="stacked muted">{{ $row['phase_title'] }}</div>
                                    @else
                                        <span class="muted">&mdash;</span>
                                    @endif
                                </td>
                                <td class="nowrap">{{ $row['start_date'] }}</td>
                                <td class="nowrap">{{ $row['due_date'] }}</td>
                                <td>{{ $row['status_label'] ?: '—' }}</td>
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
