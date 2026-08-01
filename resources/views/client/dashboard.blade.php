@extends('layouts.portalNav')

@section('title', 'My Projects')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">My Projects</h4>
            <p class="text-secondary small mb-0">Work Coliconstruct is carrying out for you.</p>
        </div>

        <span class="badge bg-secondary">{{ $projects->count() }} projects</span>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Project No.</th>
                            <th>Project Name</th>
                            <th>Schedule</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($projects as $project)
                            @php
                                $start = $project->schedules->min('start_datetime');
                                $end = $project->schedules->max('end_datetime');
                            @endphp
                            <tr>
                                <td class="text-secondary">{{ $project->reference_no }}</td>
                                <td class="fw-semibold">{{ $project->name }}</td>
                                <td>
                                    @if ($start)
                                        {{ \Carbon\CarbonImmutable::parse($start)->format('M j, Y') }}
                                        &ndash;
                                        {{ \Carbon\CarbonImmutable::parse($end ?? $start)->format('M j, Y') }}
                                    @else
                                        <span class="text-muted small">Not yet scheduled</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $project->statusBadgeClass() }}">
                                        {{ $project->statusLabel() }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-secondary text-center py-5">
                                    You have no projects with us yet. Once work is booked under your email
                                    address it will appear here.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
