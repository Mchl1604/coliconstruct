@extends('layouts.publicSite')

@section('title', 'My Projects - ' . $content->siteTitle())

@section('content')
    {{-- A page heading rather than the full introduction panel the other
         public pages wear. This page is a working list a client comes back to,
         not a page being introduced to them, and the panel took the top third
         of the screen away from the cards that are the point of it. --}}
    <section class="public-page-title">
        <div class="container">
            <div class="public-page-title-row">
                <div>
                    <h1 class="public-page-title-heading">My Projects</h1>
                    <p class="public-page-title-text mb-0">
                        @if ($isClient)
                            Work {{ $content->get('branding.short_name') }} is carrying out for you, newest first.
                        @elseif ($isGuest)
                            Sign in to view your projects.
                        @else
                            This page shows a client's own projects.
                        @endif
                    </p>
                </div>

                @if ($isClient && $projects->isNotEmpty())
                    <span class="public-page-title-count" data-project-count>
                        {{ $projects->count() }} {{ Str::plural('project', $projects->count()) }}
                    </span>
                @endif
            </div>
        </div>
    </section>

    <section class="public-section pt-0">
        <div class="container">

            @if (! $isClient)
                {{-- Nobody without a client account is shown project information
                     at all - not a name, not a date, not a status. --}}
                <div class="public-empty-state">
                    @if ($isGuest)
                        <i class="bi bi-lock fs-1 d-block mb-3 text-secondary" aria-hidden="true"></i>
                        <h2 class="h5 fw-bold text-dark mb-2">Sign in to view your projects.</h2>
                        <p class="mb-4">
                            Your projects appear once you sign in with the email address they were booked
                            under.
                        </p>
                        <a class="btn btn-brand-blue btn-pill px-4" href="{{ route('auth.login') }}">Client Login</a>
                    @else
                        {{-- Signed in, but as staff: telling them to log in would
                             be nonsense, so they are pointed at their own portal. --}}
                        <i class="bi bi-person-badge fs-1 d-block mb-3 text-secondary" aria-hidden="true"></i>
                        <h2 class="h5 fw-bold text-dark mb-2">This page is for client accounts.</h2>
                        <p class="mb-4">
                            You are signed in as {{ auth()->user()->roleLabel() }} - open your portal instead.
                        </p>
                        <a class="btn btn-brand-blue btn-pill px-4"
                            href="{{ \App\Support\PortalHome::url(auth()->user()) }}">Go to My Portal</a>
                    @endif
                </div>
            @elseif ($projects->isEmpty())
                <div class="public-empty-state">
                    <i class="bi bi-folder2-open fs-1 d-block mb-3 text-secondary" aria-hidden="true"></i>
                    <h2 class="h5 fw-bold text-dark mb-2">No projects yet.</h2>
                    <p class="mb-0">
                        Once work is booked under <strong>{{ auth()->user()->email }}</strong> it will appear here
                        automatically.
                    </p>
                </div>
            @else
                {{-- Narrowing happens in the browser: a client's list is small
                     and already on the page, so a filter or a keystroke must
                     not cost a round trip. --}}
                @php
                    $statusFilters = [
                        'all' => 'All',
                        'pending' => 'Pending',
                        'ongoing' => 'Ongoing',
                        'overdue' => 'Overdue',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ];
                    $filterCounts = collect($cards)->countBy('filter_status');
                @endphp

                <div class="card project-filter-card mb-4" data-project-filters>
                    <div class="card-body p-3 p-lg-4">
                        <div class="row g-3 align-items-center">
                            <div class="col-lg-7">
                                <div class="project-filter-tabs" role="group" aria-label="Filter projects by status">
                                    @foreach ($statusFilters as $value => $label)
                                        <button type="button"
                                            class="project-filter-tab {{ $value === 'all' ? 'active' : '' }}"
                                            data-project-filter="{{ $value }}"
                                            aria-pressed="{{ $value === 'all' ? 'true' : 'false' }}">
                                            {{ $label }}
                                            <span class="project-filter-count">
                                                {{ $value === 'all' ? count($cards) : ($filterCounts[$value] ?? 0) }}
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="col-lg-5">
                                <label class="visually-hidden" for="projectSearch">Search projects</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-search" aria-hidden="true"></i>
                                    </span>
                                    <input type="search" class="form-control" id="projectSearch"
                                        placeholder="Search ID, reference, type, client or address&hellip;"
                                        data-project-search>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="public-empty-state d-none" data-project-empty>
                    <i class="bi bi-search fs-1 d-block mb-3 text-secondary" aria-hidden="true"></i>
                    <h2 class="h6 fw-bold text-dark mb-1">No projects match this view.</h2>
                    <p class="mb-0">Try another status, or clear the search box.</p>
                </div>

                {{-- The card grid of Figure 11: coloured header carrying the
                     reference and status, then the project's facts. --}}
                <div class="row g-4" data-project-grid>
                    @foreach ($cards as $card)
                        <div class="col-md-6 col-xl-4" data-project-card
                            data-status="{{ $card['filter_status'] }}"
                            data-search="{{ $card['search_text'] }}">
                            <article class="project-card">

                                <header class="project-card-header {{ $card['header_class'] }}">
                                    <span class="project-card-reference">
                                        Reference: {{ $card['reference_no'] ?? 'Not assigned' }}
                                    </span>
                                    {{-- One label for the state, from the model, so this
                                         header and the badge on the details page cannot
                                         name the same thing two ways. --}}
                                    <span class="project-card-status">{{ $card['short_status_label'] }}</span>
                                </header>

                                <div class="project-card-body">
                                    {{-- The only card on this page asking for something, so it
                                         says so before anything else about the project. These
                                         cards sort to the top of the list as well - see
                                         ClientProjects::STATUS_RANK. --}}
                                    @if ($card['awaiting_confirmation'])
                                        <div class="alert alert-success py-2 px-3 small mb-3" role="status">
                                            <i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>
                                            <strong>Please confirm this completed project.</strong>
                                            @if ($card['confirmation_countdown'])
                                                {{ $card['confirmation_countdown'] }} before it completes
                                                automatically.
                                            @endif
                                        </div>
                                    @endif

                                    <h2 class="project-card-title">{{ $card['name'] }}</h2>

                                    @if ($card['service'])
                                        <p class="project-card-meta">
                                            <strong>Service:</strong> {{ $card['service'] }}
                                        </p>
                                    @endif

                                    @if ($card['start_date'])
                                        <p class="project-card-meta">
                                            <strong>Timeline:</strong>
                                            {{ \Carbon\CarbonImmutable::parse($card['start_date'])->format('M j, Y') }}
                                            &ndash;
                                            {{ \Carbon\CarbonImmutable::parse($card['end_date'] ?? $card['start_date'])->format('M j, Y') }}
                                        </p>
                                    @else
                                        <p class="project-card-meta text-secondary">
                                            <strong>Timeline:</strong> Not yet scheduled
                                        </p>
                                    @endif

                                    @if ($card['location'])
                                        <p class="project-card-meta">
                                            <i class="bi bi-geo-alt-fill text-danger" aria-hidden="true"></i>
                                            <strong>Location:</strong> {{ $card['location'] }}
                                        </p>
                                    @endif

                                    <p class="project-card-meta">
                                        <strong>Lead Technician:</strong>
                                        {{ $card['lead_technician'] ?? 'To be assigned' }}
                                    </p>

                                    @if ($card['description'])
                                        <p class="project-card-description">
                                            {{ Str::limit($card['description'], 90) }}
                                        </p>
                                    @endif

                                    @if ($card['updated_at'])
                                        <div class="project-card-updated">
                                            Updated {{ $card['updated_at']->diffForHumans() }}
                                        </div>
                                    @endif
                                </div>

                                <footer class="project-card-footer">
                                    <a href="{{ $card['url'] }}">View details &rarr;</a>
                                </footer>

                            </article>
                        </div>
                    @endforeach
                </div>

                @push('scripts')
                    <script src="/js/clientProjects.js"></script>
                @endpush
            @endif

        </div>
    </section>
@endsection
