@extends('layouts.superadminNav')

@section('title', 'Dashboard')

@push('styles')
    <link href="/css/super-admin/dashboard.css" rel="stylesheet">
@endpush

@section('content')
    @php
        // The pastel each upcoming card wears, cycled so a row of three is
        // never two of the same colour.
        $cardTones = ['blue', 'lilac', 'peach'];
    @endphp

    <div class="dashboard-page">

        {{-- ================================ HEADER ================================ --}}
        <header class="dash-header">
            <div>
                <h1 class="dash-title">Welcome back, {{ $viewer->first_name ?: $viewer->fullName() }}</h1>
                <p class="dash-subtitle">
                    {{ $viewer->roleLabel() }}
                    <span aria-hidden="true">&middot;</span>
                    {{ now()->format('l, F j, Y') }}
                    <span aria-hidden="true">&middot;</span>
                    <span data-dashboard-clock>{{ now()->format('g:i A') }}</span>
                </p>
            </div>

            <a class="dash-primary-btn" href="{{ route('super-admin.projects.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                New Project
            </a>
        </header>

        {{-- ============================== THE FIGURES ============================== --}}
        {{-- A label and a number. Nothing here needs reading twice. --}}
        <div class="dash-stats" data-dashboard-stats>
            @foreach ($summaryCards as $card)
                @php $tag = $card['url'] ? 'a' : 'div'; @endphp

                <{{ $tag }} class="dash-stat tone-{{ $card['tone'] }}"
                    @if ($card['url']) href="{{ $card['url'] }}" @endif
                    data-stat-key="{{ $card['key'] }}">
                    <span class="dash-stat-label">{{ $card['label'] }}</span>
                    <span class="dash-stat-value" data-stat-value>{{ $card['value'] }}</span>
                </{{ $tag }}>
            @endforeach
        </div>

        {{-- ===================== UPCOMING WORK + THE RING ===================== --}}
        <div class="dash-grid-main">

            <section class="dash-panel">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Upcoming Work</h2>
                    <a class="dash-ghost-btn" href="{{ route('super-admin.schedules.index') }}">See All</a>
                </div>

                <div class="dash-upcoming">
                    @forelse ($upcoming as $index => $item)
                        <a class="dash-task tone-{{ $cardTones[$index % count($cardTones)] }}"
                            href="{{ $item['url'] }}">
                            <span class="dash-task-chip">
                                {{ $item['is_overdue'] ? 'Overdue' : $item['status_label'] }}
                            </span>

                            <span class="dash-task-title">{{ Str::limit($item['title'], 34) }}</span>

                            <span class="dash-task-meta">
                                {{ $item['start']->format('M j') }} &ndash; {{ $item['end']->format('M j') }}
                            </span>

                            <span class="dash-task-team">
                                @forelse ($item['team'] as $avatar)
                                    <img class="dash-task-face" src="{{ $avatar }}" alt="" loading="lazy">
                                @empty
                                    <span class="dash-task-meta">No team yet</span>
                                @endforelse
                            </span>

                            <span class="dash-task-foot">
                                <span class="dash-task-bar">
                                    <span style="width: {{ $item['percent'] }}%"></span>
                                </span>
                                <span class="dash-task-count">{{ $item['done'] }}/{{ $item['total'] }}</span>
                            </span>
                        </a>
                    @empty
                        <p class="dash-empty">Nothing is scheduled from today onwards.</p>
                    @endforelse
                </div>
            </section>

            <section class="dash-panel dash-panel-quiet">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Project Status</h2>
                    <span class="dash-panel-note">Total {{ $totalProjects }}</span>
                </div>

                <div class="dash-ring-row">
                    <ul class="dash-legend">
                        @forelse ($statusBreakdown as $slice)
                            <li>
                                <span class="dash-legend-dot" style="background: {{ $slice['colour'] }}"></span>
                                <span class="dash-legend-label">{{ $slice['label'] }}</span>
                                <span class="dash-legend-value">{{ $slice['percent'] }}%</span>
                            </li>
                        @empty
                            <li class="dash-empty">No projects yet.</li>
                        @endforelse
                    </ul>

                    <div class="dash-ring">
                        <canvas data-status-ring aria-hidden="true"></canvas>
                        <div class="dash-ring-centre">
                            <strong>{{ $totalProjects }}</strong>
                            <span>Projects</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        {{-- ======================= WORKLOAD + ACTIVITY ======================= --}}
        <div class="dash-grid-foot">

            <section class="dash-panel">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Technician Workload</h2>
                    <a class="dash-ghost-btn" href="{{ route('super-admin.technicians.index') }}">See All</a>
                </div>

                <ul class="dash-people">
                    @forelse ($workload as $person)
                        <li>
                            @if ($person['avatar_url'])
                                <img class="user-avatar user-avatar-md" src="{{ $person['avatar_url'] }}" alt=""
                                    loading="lazy">
                            @endif

                            <span class="dash-person-body">
                                <span class="dash-person-name">{{ $person['name'] }}</span>
                                <span class="dash-person-role">{{ $person['role'] }}</span>
                            </span>

                            <span class="dash-person-count">
                                {{ $person['value'] }}
                                <span>{{ Str::plural('project', $person['value']) }}</span>
                            </span>
                        </li>
                    @empty
                        <li class="dash-empty">No technicians yet.</li>
                    @endforelse
                </ul>
            </section>

            <section class="dash-panel">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Recent Activity</h2>
                    <a class="dash-ghost-btn" href="{{ route('super-admin.configuration.index') }}">See All</a>
                </div>

                <ul class="dash-activity">
                    @forelse ($recentActivity as $entry)
                        <li>
                            <span class="dash-activity-dot" aria-hidden="true"></span>

                            <span class="dash-activity-body">
                                @if ($entry['url'])
                                    <a class="dash-activity-action" href="{{ $entry['url'] }}">
                                        {{ $entry['action'] }}
                                    </a>
                                @else
                                    <span class="dash-activity-action">{{ $entry['action'] }}</span>
                                @endif

                                <span class="dash-activity-meta">
                                    {{ $entry['actor_name'] }}
                                    <span aria-hidden="true">&middot;</span>
                                    {{ $entry['time']?->diffForHumans() }}
                                </span>
                            </span>

                            <span class="dash-activity-module">{{ $entry['module'] }}</span>
                        </li>
                    @empty
                        <li class="dash-empty">Nothing has happened yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>

    </div>

    @push('scripts')
        <script>
            window.dashboardData = {
                summaryUrl: @json(route('super-admin.dashboard.summary')),
                // The ring draws the same numbers the legend already shows, so
                // it needs no request of its own.
                ring: @json($statusBreakdown),
            };
        </script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
        <script src="/js/super-admin/dashboard.js"></script>
    @endpush
@endsection
