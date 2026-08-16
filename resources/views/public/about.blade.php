@extends('layouts.publicSite')

@section('title', 'About - ' . $content->siteTitle())

@section('content')
    @php
        $journeyImage = $content->image('about.team_image');
    @endphp

    {{-- The rounded grey introduction panel every inner page opens with. --}}
    <section class="public-page-head">
        <div class="container">
            <div class="public-page-head-panel">
                @if ($content->has('about.eyebrow'))
                    <p class="public-eyebrow mb-2">{{ $content->get('about.eyebrow') }}</p>
                @endif

                <h1 class="public-page-head-heading">{{ $content->get('about.heading') }}</h1>

                <p class="public-page-head-text public-prewrap mb-0">{{ $content->get('about.description') }}</p>
            </div>
        </div>
    </section>

    {{-- Our journey: the photograph leads, the story sits beside it. --}}
    <section class="public-section pt-0">
        <div class="container">
            <div class="row g-4 g-lg-5 align-items-center">
                <div class="col-lg-5">
                    @if ($journeyImage)
                        <div class="about-journey-figure">
                            <img src="{{ $journeyImage }}" alt="{{ $content->get('branding.company_name') }}"
                                loading="lazy">
                        </div>
                    @else
                        <div class="public-image-placeholder">
                            <i class="bi bi-people fs-3" aria-hidden="true"></i>
                            <span>Company photo</span>
                        </div>
                    @endif
                </div>

                <div class="col-lg-7">
                    @if ($content->has('about.journey_eyebrow'))
                        <p class="public-eyebrow mb-2">{{ $content->get('about.journey_eyebrow') }}</p>
                    @endif

                    <h2 class="about-journey-heading">{{ $content->get('about.journey_heading') }}</h2>

                    <p class="about-journey-text public-prewrap mb-0">{{ $content->get('about.history') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- What drives us. The one blue field on the page, so the values are what
         the eye lands on between the story and the people. --}}
    @if ($coreValues->isNotEmpty())
        <section class="about-values">
            <div class="container">
                <div class="text-center about-values-head">
                    @if ($content->has('about.values_eyebrow'))
                        <p class="public-eyebrow about-values-eyebrow mb-1">
                            {{ $content->get('about.values_eyebrow') }}
                        </p>
                    @endif

                    <h2 class="about-values-heading">{{ $content->get('about.values_heading') }}</h2>
                </div>

                <div class="row g-4 g-lg-5">
                    @foreach ($coreValues as $value)
                        <div class="col-md-6 col-lg-4">
                            <span class="about-value-icon" aria-hidden="true">
                                <i class="bi bi-bullseye"></i>
                            </span>

                            <h3 class="about-value-title">{{ $value['title'] }}</h3>
                            <p class="about-value-text">{{ $value['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- The people behind it. Shown only once somebody has been listed - an
         empty row of placeholder faces says less than no section at all. --}}
    @if ($team->isNotEmpty())
        <section class="public-section about-team">
            <div class="container">
                <div class="text-center about-team-head">
                    @if ($content->has('about.team_eyebrow'))
                        <p class="public-eyebrow mb-1">{{ $content->get('about.team_eyebrow') }}</p>
                    @endif

                    <h2 class="about-team-heading">{{ $content->get('about.team_heading') }}</h2>
                </div>

                <div class="row g-4 justify-content-center">
                    @foreach ($team as $member)
                        <div class="col-6 col-md-3 text-center">
                            @if ($member['photo'])
                                <img class="about-team-photo" src="{{ $member['photo'] }}"
                                    alt="{{ $member['name'] }}" loading="lazy">
                            @else
                                <span class="about-team-photo about-team-photo-empty" aria-hidden="true">
                                    <i class="bi bi-person"></i>
                                </span>
                            @endif

                            <h3 class="about-team-name">{{ $member['name'] }}</h3>
                            <p class="about-team-role">{{ $member['role'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="public-cta">
        <div class="container">
            <div class="public-cta-inner">
                <div>
                    <h2 class="public-cta-heading">{{ $content->get('about.cta_heading') }}</h2>
                    <p class="public-cta-text public-prewrap">{{ $content->get('about.cta_body') }}</p>
                </div>

                <a class="btn btn-brand-blue btn-pill px-4" href="{{ route('public.contact') }}">
                    {{ $content->get('about.cta_button_label') }}
                </a>
            </div>
        </div>
    </section>
@endsection
