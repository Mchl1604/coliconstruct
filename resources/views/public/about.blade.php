@extends('layouts.publicSite')

@section('title', 'About - ' . $content->siteTitle())

@section('content')
    @php
        $banner = $content->image('about.banner');
        $teamImage = $content->image('about.team_image');
    @endphp

    <section class="public-page-banner" @if ($banner) style="background-image: url('{{ $banner }}')" @endif>
        <div class="container">
            <p class="public-eyebrow mb-2">About</p>
            <h1 class="public-section-heading text-white mb-2">{{ $content->get('about.heading') }}</h1>
            <p class="mb-0 text-white-50 public-prewrap">{{ $content->get('about.description') }}</p>
        </div>
    </section>

    {{-- History, alongside the team photograph when one has been uploaded. --}}
    <section class="public-section">
        <div class="container">
            <div class="row g-4 g-lg-5 align-items-center">
                <div class="col-lg-7">
                    <p class="public-eyebrow mb-2">Our Story</p>
                    <h2 class="public-section-heading mb-3">Company History</h2>
                    <p class="text-secondary public-prewrap mb-0">{{ $content->get('about.history') }}</p>
                </div>

                <div class="col-lg-5">
                    @if ($teamImage)
                        <div class="public-figure ratio ratio-4x3">
                            <img src="{{ $teamImage }}" alt="The Coliconstruct team" loading="lazy">
                        </div>
                    @else
                        <div class="public-image-placeholder">
                            <i class="bi bi-people fs-3" aria-hidden="true"></i>
                            <span>Team photo</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="public-section public-section-tinted">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="public-pillar">
                        <span class="public-pillar-icon"><i class="bi bi-flag" aria-hidden="true"></i></span>
                        <h2 class="h5 fw-bold">Mission</h2>
                        <p class="text-secondary mb-0 public-prewrap">{{ $content->get('about.mission') }}</p>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="public-pillar public-pillar-yellow">
                        <span class="public-pillar-icon"><i class="bi bi-eye" aria-hidden="true"></i></span>
                        <h2 class="h5 fw-bold">Vision</h2>
                        <p class="text-secondary mb-0 public-prewrap">{{ $content->get('about.vision') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if ($coreValues->isNotEmpty())
        <section class="public-section">
            <div class="container">
                <div class="text-center mb-5">
                    <p class="public-eyebrow mb-2">What We Stand For</p>
                    <h2 class="public-section-heading">Core Values</h2>
                </div>

                <div class="row g-4">
                    @foreach ($coreValues as $value)
                        <div class="col-md-6 col-lg-3">
                            <div class="public-service-card {{ $loop->even ? 'public-alt-accent' : '' }}">
                                <span class="public-service-icon">
                                    <i class="bi bi-award" aria-hidden="true"></i>
                                </span>
                                <h3 class="public-service-title h6">{{ $value['title'] }}</h3>
                                <p class="public-service-text">{{ $value['description'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($photos->isNotEmpty())
        <section class="public-section public-section-tinted">
            <div class="container">
                <div class="text-center mb-5">
                    <p class="public-eyebrow mb-2">Our Work</p>
                    <h2 class="public-section-heading">Company Photos</h2>
                </div>

                <div class="row g-3">
                    @foreach ($photos as $photo)
                        <div class="col-md-4">
                            <div class="public-figure public-gallery-item">
                                <img src="{{ $photo }}" alt="Company photograph" loading="lazy">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
