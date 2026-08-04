@extends('layouts.publicSite')

@section('title', 'My Projects - ' . $content->siteTitle())

@section('content')
    <section class="public-section">
        <div class="container">

            <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="public-section-heading mb-1">My Projects</h1>
                    <p class="text-secondary mb-0">
                        @if ($isClient)
                            Work Coliconstruct is carrying out for you, newest first.
                        @elseif ($isGuest)
                            Sign in to follow the work booked under your email address.
                        @else
                            This page shows a client's own projects.
                        @endif
                    </p>
                </div>

                @if ($isClient && $projects->isNotEmpty())
                    <span class="badge bg-secondary">
                        {{ $projects->count() }} {{ Str::plural('project', $projects->count()) }}
                    </span>
                @endif
            </div>

            @if (! $isClient)
                {{-- Nobody without a client account is shown project information
                     at all - not a name, not a date, not a status. --}}
                <div class="public-empty-state">
                    @if ($isGuest)
                        <i class="bi bi-lock fs-1 d-block mb-3 text-secondary" aria-hidden="true"></i>
                        <h2 class="h5 fw-bold text-dark mb-2">Please log in to view your projects.</h2>
                        <p class="mb-4">
                            Your projects appear here once you sign in with the email address they were booked
                            under.
                        </p>
                        <a class="btn btn-brand-blue px-4" href="{{ route('auth.login') }}">Client Login</a>
                    @else
                        {{-- Signed in, but as staff: telling them to log in would
                             be nonsense, so they are pointed at their own portal. --}}
                        <i class="bi bi-person-badge fs-1 d-block mb-3 text-secondary" aria-hidden="true"></i>
                        <h2 class="h5 fw-bold text-dark mb-2">This page is for client accounts.</h2>
                        <p class="mb-4">
                            You are signed in as {{ auth()->user()->roleLabel() }}. Your work lives in your own
                            portal.
                        </p>
                        <a class="btn btn-brand-blue px-4"
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
                {{-- The card grid of Figure 11: coloured header carrying the
                     reference and status, then the project's facts. --}}
                <div class="row g-4">
                    @foreach ($cards as $card)
                        <div class="col-md-6 col-xl-4">
                            <article class="project-card">

                                <header class="project-card-header {{ $card['header_class'] }}">
                                    <span class="project-card-reference">
                                        Reference: {{ $card['reference_no'] ?? 'Not assigned' }}
                                    </span>
                                    <span class="project-card-status">{{ $card['status_label'] }}</span>
                                </header>

                                <div class="project-card-body">
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

                                    <div class="project-card-progress" role="progressbar"
                                        aria-valuenow="{{ $card['progress'] }}" aria-valuemin="0" aria-valuemax="100"
                                        aria-label="Project progress">
                                        <span style="width: {{ $card['progress'] }}%"></span>
                                    </div>

                                    <div class="project-card-progress-label">
                                        <span>{{ $card['progress'] }}% complete</span>
                                        @if ($card['updated_at'])
                                            <span>Updated {{ $card['updated_at']->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                </div>

                                <footer class="project-card-footer">
                                    <a href="{{ $card['url'] }}">View details &rarr;</a>
                                </footer>

                            </article>
                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </section>
@endsection
