@extends('layouts.publicSite')

@section('title', $content->siteTitle())

@section('content')
    @php
        $heroImage = $content->image('home.hero_image');
        $overviewImage = $content->image('home.overview_image');
        $promoImage = $content->image('home.promo_image');
    @endphp

    {{-- Hero. The uploaded banner becomes the background; without one the
         gradient underneath carries the section on its own. --}}
    <section class="public-hero" @if ($heroImage) style="background-image: url('{{ $heroImage }}')" @endif>
        <div class="container">
            <div class="row">
                <div class="col-lg-8">
                    <p class="public-eyebrow mb-2">{{ $content->get('branding.company_name') }}</p>

                    <h1 class="public-hero-heading">{{ $content->get('home.hero_heading') }}</h1>

                    <p class="public-hero-description">{{ $content->get('home.hero_description') }}</p>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <a class="btn btn-brand-yellow btn-lg px-4" href="{{ route('public.projects') }}">
                            My Projects
                        </a>

                        <a class="btn btn-outline-light btn-lg px-4" href="{{ route('public.contact') }}">
                            Contact Us
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Featured services --}}
    @if ($services->isNotEmpty())
        <section class="public-section">
            <div class="container">
                <div class="text-center mb-5">
                    <p class="public-eyebrow mb-2">Our Services</p>
                    <h2 class="public-section-heading">{{ $content->get('home.services_heading') }}</h2>
                    <p class="public-section-intro mx-auto mt-2">{{ $content->get('home.services_intro') }}</p>
                </div>

                <div class="row g-4">
                    @foreach ($services as $service)
                        <div class="col-md-6 col-lg-4">
                            {{-- Alternating blue and yellow accents, so the grid
                                 carries both brand colours. --}}
                            <div class="public-service-card {{ $loop->even ? 'public-alt-accent' : '' }}">
                                <span class="public-service-icon">
                                    <i class="bi bi-wrench-adjustable" aria-hidden="true"></i>
                                </span>
                                <h3 class="public-service-title h6">{{ $service['title'] }}</h3>
                                <p class="public-service-text">{{ $service['description'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Company overview, mission and vision --}}
    <section class="public-section public-section-tinted">
        <div class="container">
            <div class="row g-4 g-lg-5 align-items-center">
                <div class="col-lg-6">
                    <p class="public-eyebrow mb-2">About Us</p>
                    <h2 class="public-section-heading mb-3">{{ $content->get('home.overview_heading') }}</h2>
                    <p class="text-secondary public-prewrap">{{ $content->get('home.overview_body') }}</p>

                    <a class="btn btn-outline-brand-blue mt-2" href="{{ route('public.about') }}">
                        Learn more about us
                    </a>
                </div>

                <div class="col-lg-6">
                    @if ($overviewImage)
                        <div class="public-figure ratio ratio-4x3">
                            <img src="{{ $overviewImage }}" alt="{{ $content->get('home.overview_heading') }}"
                                loading="lazy">
                        </div>
                    @else
                        <div class="public-image-placeholder">
                            <i class="bi bi-image fs-3" aria-hidden="true"></i>
                            <span>Company photo</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="row g-4 mt-4">
                <div class="col-md-6">
                    <div class="public-pillar">
                        <span class="public-pillar-icon"><i class="bi bi-flag" aria-hidden="true"></i></span>
                        <h3 class="h6 fw-bold">Mission</h3>
                        <p class="text-secondary mb-0 public-prewrap">{{ $content->get('home.mission') }}</p>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="public-pillar public-pillar-yellow">
                        <span class="public-pillar-icon"><i class="bi bi-eye" aria-hidden="true"></i></span>
                        <h3 class="h6 fw-bold">Vision</h3>
                        <p class="text-secondary mb-0 public-prewrap">{{ $content->get('home.vision') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Gallery. Shown only once there is something to show. --}}
    @if ($gallery->isNotEmpty())
        <section class="public-section">
            <div class="container">
                <div class="text-center mb-5">
                    <p class="public-eyebrow mb-2">Gallery</p>
                    <h2 class="public-section-heading">{{ $content->get('home.gallery_heading') }}</h2>
                </div>

                <div class="row g-3">
                    @foreach ($gallery as $image)
                        <div class="col-6 col-md-4">
                            <div class="public-figure public-gallery-item">
                                <img src="{{ $image }}" alt="Project photograph" loading="lazy">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Promotional strip --}}
    <section class="public-section {{ $gallery->isNotEmpty() ? 'public-section-tinted' : '' }}">
        <div class="container">
            <div class="public-promo" @if ($promoImage) style="background-image: url('{{ $promoImage }}')" @endif>
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <h2 class="public-section-heading text-white mb-2">
                            {{ $content->get('home.promo_heading') }}
                        </h2>
                        <p class="mb-0 text-white-50">{{ $content->get('home.promo_body') }}</p>
                    </div>

                    <div class="col-lg-4 text-lg-end">
                        <a class="btn btn-brand-yellow btn-lg px-4" href="{{ route('public.projects') }}">
                            View My Projects
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
