@extends('layouts.publicSite')

@section('title', 'Contact Us - ' . $content->siteTitle())

@section('content')
    @php
        $mapEmbed = $content->get('contact.map_embed');

        // The three ways to reach the company, each on its own blue card. The
        // labels name the field rather than repeating its value, which is what
        // lets a phone number and an address share one shape.
        //
        // Three and no more: they stand beside the map, and a fourth card
        // leaves the column hanging below it.
        $details = collect([
            ['key' => 'contact.phone', 'icon' => 'bi-telephone', 'label' => 'Mobile number'],
            ['key' => 'contact.email', 'icon' => 'bi-envelope', 'label' => 'Email'],
            ['key' => 'contact.address', 'icon' => 'bi-geo-alt', 'label' => 'Main office'],
        ])->filter(fn (array $detail): bool => $content->has($detail['key']));
    @endphp

    {{-- A pale blue band rather than the grey panel the other pages open with:
         Contact is where a visitor is being invited to act. --}}
    <section class="contact-hero">
        <div class="container text-center">
            <h1 class="contact-hero-heading">{{ $content->get('contact.heading') }}</h1>
            <p class="contact-hero-text public-prewrap mb-0">{{ $content->get('contact.description') }}</p>
        </div>
    </section>

    <section class="public-section contact-body">
        <div class="container">

            {{-- The message form. Every field is disabled and the note says
                 why: there is no inquiries table to post to, and a form that
                 silently drops what somebody typed is worse than one that
                 says it is not open yet. --}}
            <div class="contact-form-card">
                <h2 class="contact-form-heading">{{ $content->get('contact.form_heading') }}</h2>
                <p class="contact-form-intro public-prewrap">{{ $content->get('contact.form_intro') }}</p>

                <form class="row g-3 mt-1" aria-describedby="contactFormNotice">
                    <div class="col-md-6">
                        <label class="form-label" for="contactName">Name</label>
                        <input type="text" class="form-control contact-field" id="contactName" name="name"
                            disabled>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="contactEmail">Email</label>
                        <input type="email" class="form-control contact-field" id="contactEmail" name="email"
                            disabled>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="contactSubject">Subject</label>
                        <input type="text" class="form-control contact-field" id="contactSubject" name="subject"
                            disabled>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="contactMessage">Message</label>
                        <textarea class="form-control contact-field" id="contactMessage" name="message" rows="5"
                            disabled></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-brand-blue px-4" disabled>
                            {{ $content->get('contact.form_button_label') }}
                        </button>

                        <p class="contact-form-note mb-0" id="contactFormNotice">
                            {{ $content->get('contact.form_note') }}
                        </p>
                    </div>
                </form>
            </div>

            <h2 class="contact-info-heading">{{ $content->get('contact.info_heading') }}</h2>
            <p class="contact-info-intro public-prewrap">{{ $content->get('contact.info_intro') }}</p>

            <div class="row g-4">
                <div class="col-lg-5">
                    {{-- The cards share the map's height between them, so the
                         two columns start and finish together however many
                         details have been published. --}}
                    <div class="contact-info-cards">
                        @forelse ($details as $detail)
                            <div class="contact-info-card">
                                <i class="bi {{ $detail['icon'] }} contact-info-icon" aria-hidden="true"></i>
                                <h3 class="contact-info-label">{{ $detail['label'] }}</h3>
                                <p class="contact-info-value public-prewrap mb-0">
                                    {{ $content->get($detail['key']) }}
                                </p>
                            </div>
                        @empty
                            <div class="public-empty-state">
                                <i class="bi bi-info-circle fs-3 d-block mb-2" aria-hidden="true"></i>
                                Contact details have not been published yet.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="col-lg-7">
                    @if ($mapEmbed)
                        <iframe class="public-map" src="{{ $mapEmbed }}" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade" title="Our location on a map"
                            allowfullscreen></iframe>
                    @else
                        <div class="public-image-placeholder h-100">
                            <i class="bi bi-map fs-3" aria-hidden="true"></i>
                            <span>A map appears here once its embed link is set.</span>
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </section>
@endsection
