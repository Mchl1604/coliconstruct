@extends('layouts.publicSite')

@section('title', $content->siteTitle())

@section('content')
    @php
        $heroImage = $content->image('home.hero_image');
    @endphp

    {{-- Hero. One blue field carrying the whole pitch: a badge, the headline,
         a sentence, the two doors out of it, and the photograph. Centred
         rather than left-aligned, so the framed photograph below it reads as
         part of the same block. --}}
    <section class="home-hero">
        <div class="container">

            @if ($content->has('home.hero_badge'))
                <span class="home-hero-badge">{{ $content->get('home.hero_badge') }}</span>
            @endif

            <h1 class="home-hero-heading">{{ $content->get('home.hero_heading') }}</h1>

            <p class="home-hero-description public-prewrap">{{ $content->get('home.hero_description') }}</p>

            <div class="home-hero-actions">
                {{-- The yellow one leads to the client's own work, which is
                     what this website is really for; the quiet one leads to
                     the company's story. --}}
                <a class="btn btn-brand-yellow btn-pill px-4" href="{{ route('public.projects') }}">
                    {{ $content->get('home.hero_primary_label') }}
                </a>

                <a class="btn btn-hero-ghost btn-pill px-4" href="{{ route('public.about') }}">
                    {{ $content->get('home.hero_secondary_label') }}
                </a>
            </div>

            <div class="home-hero-figure">
                @if ($heroImage)
                    <img src="{{ $heroImage }}" alt="{{ $content->get('branding.company_name') }}">
                @else
                    {{-- Holds the frame's shape before a photograph is
                         uploaded, so the hero never collapses into text. --}}
                    <div class="home-hero-figure-empty">
                        <i class="bi bi-image" aria-hidden="true"></i>
                        <span>Hero image</span>
                    </div>
                @endif
            </div>

        </div>
    </section>

    {{-- Services. Numbered in the order they are typed into System Contents,
         so the badge is a reading order rather than a ranking. --}}
    @if ($services->isNotEmpty())
        <section class="public-section home-services">
            <div class="container">
                <div class="text-center home-services-head">
                    @if ($content->has('home.services_eyebrow'))
                        <p class="public-eyebrow mb-1">{{ $content->get('home.services_eyebrow') }}</p>
                    @endif

                    <h2 class="home-section-heading">{{ $content->get('home.services_heading') }}</h2>

                    @if ($content->has('home.services_intro'))
                        <p class="public-section-intro mx-auto mt-2">{{ $content->get('home.services_intro') }}</p>
                    @endif
                </div>

                <div class="row g-4 g-lg-5 justify-content-center">
                    @foreach ($services as $service)
                        <div class="col-md-6 col-lg-4">
                            <article class="home-service-card">
                                <span class="home-service-number" aria-hidden="true">
                                    {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                                </span>

                                @if ($service['image'])
                                    <img class="home-service-image" src="{{ $service['image'] }}"
                                        alt="{{ $service['title'] }}" loading="lazy">
                                @else
                                    {{-- Keeps an image-free legacy service card balanced and complete. --}}
                                    <div class="home-service-image home-service-image-empty" aria-hidden="true">
                                        <i class="bi bi-image"></i>
                                    </div>
                                @endif

                                <h3 class="home-service-title">{{ $service['title'] }}</h3>
                                <p class="home-service-text">{{ $service['description'] }}</p>
                            </article>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- The closing strip. Yellow, so the one thing being asked for is the one
         thing on the page that is neither blue nor white. --}}
    <section class="public-cta">
        <div class="container">
            <div class="public-cta-inner">
                <div>
                    <h2 class="public-cta-heading">{{ $content->get('home.promo_heading') }}</h2>
                    <p class="public-cta-text public-prewrap">{{ $content->get('home.promo_body') }}</p>
                </div>

                <a class="btn btn-brand-blue btn-pill px-4" href="{{ route('public.contact') }}">
                    {{ $content->get('home.promo_button_label') }}
                </a>
            </div>
        </div>
    </section>
@endsection
