@extends('layouts.publicSite')

@section('title', 'Contact Us - ' . $content->siteTitle())

@section('content')
    @php
        $banner = $content->image('contact.banner');
        $mapEmbed = $content->get('contact.map_embed');

        $details = collect([
            ['key' => 'contact.address', 'icon' => 'bi-geo-alt', 'label' => 'Address'],
            ['key' => 'contact.phone', 'icon' => 'bi-telephone', 'label' => 'Phone'],
            ['key' => 'contact.email', 'icon' => 'bi-envelope', 'label' => 'Email'],
            ['key' => 'contact.office_hours', 'icon' => 'bi-clock', 'label' => 'Office Hours'],
        ])->filter(fn (array $detail): bool => $content->has($detail['key']));
    @endphp

    <section class="public-page-banner" @if ($banner) style="background-image: url('{{ $banner }}')" @endif>
        <div class="container">
            <p class="public-eyebrow mb-2">Contact</p>
            <h1 class="public-section-heading text-white mb-2">{{ $content->get('contact.heading') }}</h1>
            <p class="mb-0 text-white-50 public-prewrap">{{ $content->get('contact.description') }}</p>
        </div>
    </section>

    <section class="public-section">
        <div class="container">
            <div class="row g-4">
                @forelse ($details as $detail)
                    <div class="col-md-6 col-lg-3">
                        <div class="public-contact-card">
                            <span class="public-contact-icon">
                                <i class="bi {{ $detail['icon'] }}" aria-hidden="true"></i>
                            </span>
                            <div>
                                <div class="public-detail-label mb-1">{{ $detail['label'] }}</div>
                                <div class="public-prewrap">{{ $content->get($detail['key']) }}</div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="public-empty-state">
                            <i class="bi bi-info-circle fs-3 d-block mb-2" aria-hidden="true"></i>
                            Contact details have not been published yet.
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="public-section public-section-tinted pt-0">
        <div class="container">
            <div class="row g-4 g-lg-5">

                <div class="col-lg-6">
                    <h2 class="public-section-heading mb-3">Send Us a Message</h2>
                    <p class="text-secondary">
                        Tell us what you need and we will come back to you with an assessment schedule.
                    </p>

                    {{-- The structure is in place; there is no inquiries table to
                         post to yet, so the fields are shown disabled rather than
                         accepting a message the system would silently drop. --}}
                    <form class="row g-3 mt-1" aria-describedby="contactFormNotice">
                        <div class="col-md-6">
                            <label class="form-label" for="contactName">Name</label>
                            <input type="text" class="form-control" id="contactName" name="name" disabled>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="contactEmail">Email</label>
                            <input type="email" class="form-control" id="contactEmail" name="email" disabled>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="contactSubject">Subject</label>
                            <input type="text" class="form-control" id="contactSubject" name="subject" disabled>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="contactMessage">Message</label>
                            <textarea class="form-control" id="contactMessage" name="message" rows="5" disabled></textarea>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-brand-blue px-4" disabled>Send Message</button>

                            <p class="text-secondary small mt-3 mb-0" id="contactFormNotice">
                                Online enquiries are not being accepted yet.
                                @if ($content->has('contact.email'))
                                    Please email <strong>{{ $content->get('contact.email') }}</strong> in the meantime.
                                @elseif ($content->has('contact.phone'))
                                    Please call <strong>{{ $content->get('contact.phone') }}</strong> in the meantime.
                                @endif
                            </p>
                        </div>
                    </form>
                </div>

                <div class="col-lg-6">
                    <h2 class="public-section-heading mb-3">Find Us</h2>

                    @if ($mapEmbed)
                        <iframe class="public-map" src="{{ $mapEmbed }}" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade" title="Our location on a map"
                            allowfullscreen></iframe>
                    @else
                        <div class="public-image-placeholder" style="min-height: 380px;">
                            <i class="bi bi-map fs-3" aria-hidden="true"></i>
                            <span>A map appears here once its embed link is set.</span>
                        </div>
                    @endif
                </div>

            </div>
        </div>
    </section>
@endsection
