@extends('layouts.portalNav')

@section('title', 'My Schedule')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">My Schedule</h4>
            <p class="text-secondary small mb-0">Every date range you are booked on.</p>
        </div>

        <div class="d-flex gap-2">
            <span class="badge bg-primary">{{ $activeCount }} active now</span>
            <span class="badge bg-warning text-dark">{{ $upcomingCount }} upcoming</span>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Project No.</th>
                            <th>Project</th>
                            <th>Dates</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            @php
                                $project = $entry['project'];
                                $isNow = $entry['start']->lte($today) && $entry['end']->gte($today);
                            @endphp
                            <tr>
                                <td class="text-secondary">{{ $project->reference_no }}</td>
                                <td class="fw-semibold">
                                    {{ $project->name }}
                                    @if ($isNow)
                                        <span class="badge bg-primary ms-1">Now</span>
                                    @endif
                                </td>
                                <td>{{ $entry['start']->format('M j, Y') }} &ndash; {{ $entry['end']->format('M j, Y') }}</td>
                                <td>
                                    <span class="badge {{ $project->statusBadgeClass() }}">
                                        {{ $project->statusLabel() }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-secondary text-center py-4">
                                    You have no scheduled work at the moment.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
