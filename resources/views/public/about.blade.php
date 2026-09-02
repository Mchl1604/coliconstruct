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

    {{-- Owners only appear when they have been published in System Settings. --}}
    @if ($owners->isNotEmpty())
        <section class="public-section about-owners">
            <div class="container">
                <div class="text-center about-owners-head">
                    @if ($content->has('about.owners_eyebrow'))
                        <p class="public-eyebrow mb-1">{{ $content->get('about.owners_eyebrow') }}</p>
                    @endif

                    <h2 class="about-owners-heading">{{ $content->get('about.owners_heading') }}</h2>
                </div>

                <div class="row g-4 justify-content-center">
                    @foreach ($owners as $owner)
                        <div class="col-12 col-sm-6 col-lg-3 text-center">
                            @if ($owner['image'])
                                <img class="about-owner-photo" src="{{ $owner['image'] }}"
                                    alt="{{ $owner['name'] }}" loading="lazy">
                            @else
                                <span class="about-owner-photo about-owner-photo-empty" aria-hidden="true">
                                    <i class="bi bi-person"></i>
                                </span>
                            @endif

                            <h3 class="about-owner-name">{{ $owner['name'] }}</h3>
                            <p class="about-owner-contact public-prewrap">{{ $owner['contact'] }}</p>
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
