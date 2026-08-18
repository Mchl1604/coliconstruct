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

            {{-- The message form. It posts for real when the system has a mailer
                 and somewhere to send to; when it does not, the fields are
                 disabled and the note says why - a form that silently drops what
                 somebody typed is worse than one that admits it is not open. --}}
            <div class="contact-form-card">
                <h2 class="contact-form-heading">{{ $content->get('contact.form_heading') }}</h2>
                <p class="contact-form-intro public-prewrap">{{ $content->get('contact.form_intro') }}</p>

                @if (session('success'))
                    <div class="alert alert-success" role="alert">
                        <i class="bi bi-check-circle me-1"></i>
                        {{ session('success') }}
                    </div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        {{ session('error') }}
                    </div>
                @endif

                <form class="row g-3 mt-1" method="POST" action="{{ route('public.contact.send') }}"
                    aria-describedby="contactFormNotice">
                    @csrf

                    <div class="col-md-6">
                        <label class="form-label" for="contactName">Name</label>
                        <input type="text" class="form-control contact-field @error('name') is-invalid @enderror"
                            id="contactName" name="name" value="{{ old('name') }}" maxlength="120"
                            autocomplete="name" required @disabled(! $canSendInquiries)>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="contactEmail">Email</label>
                        <input type="email" class="form-control contact-field @error('email') is-invalid @enderror"
                            id="contactEmail" name="email" value="{{ old('email') }}" maxlength="255"
                            autocomplete="email" required @disabled(! $canSendInquiries)>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="contactSubject">Subject</label>
                        <input type="text" class="form-control contact-field @error('subject') is-invalid @enderror"
                            id="contactSubject" name="subject" value="{{ old('subject') }}" maxlength="150" required
                            @disabled(! $canSendInquiries)>
                        @error('subject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="contactMessage">Message</label>
                        <textarea class="form-control contact-field @error('message') is-invalid @enderror"
                            id="contactMessage" name="message" rows="5" maxlength="2000" required
                            @disabled(! $canSendInquiries)>{{ old('message') }}</textarea>
                        @error('message')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Not a field anybody sees. A bot that fills every input
                         gives itself away here; `tabindex` and `aria-hidden`
                         keep it away from anyone using a keyboard or a screen
                         reader, and autocomplete is off so no browser offers to
                         fill it in. --}}
                    <div class="contact-honeypot" aria-hidden="true">
                        <label for="contactCompanyWebsite">Company website</label>
                        <input type="text" id="contactCompanyWebsite" name="company_website" value=""
                            tabindex="-1" autocomplete="off">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-brand-blue px-4" @disabled(! $canSendInquiries)>
                            {{ $content->get('contact.form_button_label') }}
                        </button>

                        @unless ($canSendInquiries)
                            <p class="contact-form-note mb-0" id="contactFormNotice">
                                {{ $content->get('contact.form_note') }}
                            </p>
                        @endunless
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
