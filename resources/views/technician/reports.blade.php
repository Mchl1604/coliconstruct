@extends('layouts.portalNav')

@section('title', 'My Reports')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">My Reports</h4>
            <p class="text-secondary small mb-0">Reports filed under your name.</p>
        </div>

        <span class="badge bg-secondary">{{ $reports->count() }} reports</span>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Report ID</th>
                            <th>Project</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th class="text-center">Images</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td class="text-secondary">#{{ $report->id }}</td>
                                <td>{{ $report->project?->name ?? 'Project removed' }}</td>
                                <td class="fw-semibold">{{ $report->report_title }}</td>
                                <td><span class="badge {{ $report->typeBadgeClass() }}">{{ $report->typeLabel() }}</span></td>
                                <td class="text-center">{{ $report->images->count() }}</td>
                                <td>{{ $report->report_date?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-secondary text-center py-4">
                                    You have not filed any reports yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
