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
                    {{ \App\Support\BusinessTime::now()->format('l, '.\App\Support\BusinessTime::DATE) }}
                    <span aria-hidden="true">&middot;</span>
                    <span data-dashboard-clock>{{ \App\Support\BusinessTime::now()->format('g:i A') }}</span>
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
                                {{ $item['is_overdue'] ? \App\Models\Project::LABEL_NEEDS_RESCHEDULING : $item['status_label'] }}
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
                        </a>
                    @empty
                        <p class="dash-empty">Nothing is scheduled from today onwards.</p>
                    @endforelse
                </div>
            </section>

            {{-- What is waiting on somebody. This replaced Quick Actions,
                 which was the sidebar again one row lower; a dashboard is
                 opened to find out what needs doing.

                 Every entry is a real backlog with a real count, and one that
                 has been cleared is absent rather than drawn as a zero - so an
                 empty section means there is nothing to do, which is worth
                 saying in as many words. Each View opens the page already
                 filtered to the records behind the figure. --}}
            <section class="dash-panel dash-panel-quiet">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Urgent Actions</h2>
                </div>

                <ul class="dash-urgent">
                    @forelse ($urgentActions as $action)
                        <li class="dash-urgent-item">
                            <span class="dash-urgent-icon" aria-hidden="true">
                                <i class="bi {{ $action['icon'] }}"></i>
                            </span>

                            {{-- Most entries say everything in the label
                                 ("3 Projects Need Rescheduling"). One whose number needs
                                 a qualifier explains it underneath instead of
                                 cramming it into the heading. --}}
                            <span class="dash-urgent-label">
                                {{ $action['label'] }}

                                @if ($action['detail'])
                                    <small class="dash-urgent-detail">{{ $action['detail'] }}</small>
                                @endif
                            </span>

                            {{-- 'View' on everything that opens a list; an
                                 entry whose link opens the screen where the
                                 work is actually done names that instead. --}}
                            <a class="dash-urgent-view" href="{{ $action['url'] }}"
                                data-urgent-action="{{ $action['key'] }}">
                                {{ $action['action'] }}
                            </a>
                        </li>
                    @empty
                        <li class="dash-empty">Nothing needs attention right now.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        {{-- ================= ACTIVE TECHNICIANS + TASKS ==================== --}}
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
                        @php
                            // Opens the person's details on the Technicians
                            // page. Read defensively: a panel cached before
                            // the id was part of the row simply does not link
                            // until the cache turns over.
                            $personUrl = isset($person['technician_id'])
                                ? route('super-admin.technicians.index', ['technician' => $person['technician_id']])
                                : null;
                        @endphp
                        <li>
                            @if ($person['avatar_url'])
                                @if ($personUrl)
                                    <a href="{{ $personUrl }}" class="technician-link" tabindex="-1" aria-hidden="true">
                                        <img class="user-avatar user-avatar-md" src="{{ $person['avatar_url'] }}" alt=""
                                            loading="lazy">
                                    </a>
                                @else
                                    <img class="user-avatar user-avatar-md" src="{{ $person['avatar_url'] }}" alt=""
                                        loading="lazy">
                                @endif
                            @endif

                            <span class="dash-person-body">
                                @if ($personUrl)
                                    <a href="{{ $personUrl }}" class="dash-person-name technician-link"
                                        title="View {{ $person['name'] }}'s details">{{ $person['name'] }}</a>
                                @else
                                    <span class="dash-person-name">{{ $person['name'] }}</span>
                                @endif
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

            {{-- Today's tasks and the late ones, a tab each. Today includes
                 what is already finished, so it reads as the day's progress;
                 Late is open work past its due date. Each row opens the task
                 on the Tasks board. --}}
            @php
                $taskLists = [
                    'today' => ['label' => 'Today', 'empty' => 'No tasks today.'],
                    'late' => ['label' => 'Late', 'empty' => 'No late tasks.'],
                ];
            @endphp

            <section class="dash-panel">
                <div class="dash-panel-head">
                    <h2 class="dash-panel-title">Task Monitoring</h2>
                    <a class="dash-ghost-btn" href="{{ route('super-admin.tasks.index') }}">See All</a>
                </div>

                <div class="dash-task-tabs nav" role="tablist">
                    @foreach ($taskLists as $key => $list)
                        <button type="button" role="tab" id="task-tab-{{ $key }}"
                            class="dash-task-tab {{ $loop->first ? 'active' : '' }}"
                            data-bs-toggle="tab" data-bs-target="#task-list-{{ $key }}"
                            aria-controls="task-list-{{ $key }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                            {{ $list['label'] }}
                            <span class="dash-task-tab-count tone-{{ $key }}">{{ $taskMonitoring[$key.'_count'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="tab-content">
                    @foreach ($taskLists as $key => $list)
                        @php
                            $rows = $taskMonitoring[$key];
                            $more = $taskMonitoring[$key.'_count'] - count($rows);
                        @endphp

                        <ul class="dash-tasks tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                            id="task-list-{{ $key }}" role="tabpanel" aria-labelledby="task-tab-{{ $key }}">
                            @forelse ($rows as $task)
                                <li>
                                    <span class="dash-tasks-body">
                                        <a class="dash-tasks-title" href="{{ $task['url'] }}">{{ $task['title'] }}</a>
                                        <span class="dash-tasks-meta">
                                            {{ $task['project'] }}
                                            <span aria-hidden="true">&middot;</span>
                                            {{ $task['technician'] ?? 'Unassigned' }}
                                        </span>
                                    </span>

                                    @if ($task['days_late'] > 0)
                                        <span class="dash-tasks-late">
                                            {{ $task['days_late'] }} {{ Str::plural('day', $task['days_late']) }} late
                                        </span>
                                    @else
                                        <span class="badge rounded-pill {{ $task['status_badge_class'] }}">
                                            {{ $task['status_label'] }}
                                        </span>
                                    @endif
                                </li>
                            @empty
                                <li class="dash-empty">{{ $list['empty'] }}</li>
                            @endforelse

                            @if ($more > 0)
                                <li class="dash-tasks-more">
                                    <a href="{{ route('super-admin.tasks.index') }}">and {{ $more }} more</a>
                                </li>
                            @endif
                        </ul>
                    @endforeach
                </div>
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
