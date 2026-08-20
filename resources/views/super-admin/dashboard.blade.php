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

        {{-- ================= UPCOMING WORK + QUICK ACTIONS ================= --}}
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
                                {{ $item['schedule_label'] }}
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

            {{-- The doors into the rest of the portal. Built in the
                 controller so an action nobody may open is absent rather than
                 drawn disabled, and every one of them points at a route that
                 already exists. --}}
            <section class="dash-panel dash-panel-quiet">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Quick Actions</h2>
                </div>

                <div class="dash-actions">
                    @forelse ($quickActions as $action)
                        <a class="dash-action" href="{{ $action['url'] }}" data-quick-action="{{ $action['key'] }}">
                            <span class="dash-action-icon">
                                <i class="bi {{ $action['icon'] }}" aria-hidden="true"></i>
                            </span>
                            <span class="dash-action-label">{{ $action['label'] }}</span>
                        </a>
                    @empty
                        <p class="dash-empty">No modules are available to your account.</p>
                    @endforelse
                </div>
            </section>
        </div>

        {{-- ================= ACTIVE TECHNICIANS + ACTIVITY ================= --}}
        <div class="dash-grid-foot">

            {{-- Who is on site today, decided by the same booked date ranges
                 the Active Today figure above counts, so the two can never
                 describe different days. --}}
            <section class="dash-panel">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Active Technicians Today</h2>
                    <a class="dash-ghost-btn" href="{{ route('super-admin.technicians.index') }}">See All</a>
                </div>

                <ul class="dash-people">
                    @forelse ($activeTechnicians as $person)
                        <li>
                            @if ($person['avatar_url'])
                                <img class="user-avatar user-avatar-md" src="{{ $person['avatar_url'] }}" alt=""
                                    loading="lazy">
                            @endif

                            <span class="dash-person-body">
                                <span class="dash-person-name">{{ $person['name'] }}</span>
                                <span class="dash-person-role">{{ $person['role'] }}</span>
                            </span>

                            {{-- What they are on, so the panel says where
                                 somebody is rather than only that they are
                                 busy. --}}
                            <span class="dash-person-count">
                                {{ implode(', ', $person['projects']) }}
                            </span>
                        </li>
                    @empty
                        <li class="dash-empty">Nobody is scheduled to work today.</li>
                    @endforelse

                    @if ($activeTechnicianCount > $activeTechnicians->count())
                        <li class="dash-empty">
                            and {{ $activeTechnicianCount - $activeTechnicians->count() }} more.
                        </li>
                    @endif
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
            };
        </script>
        <script src="/js/super-admin/dashboard.js"></script>
    @endpush
@endsection
