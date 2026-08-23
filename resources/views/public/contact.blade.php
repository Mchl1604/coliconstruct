@extends('layouts.publicSite')

@section('title', 'Contact Us - ' . $content->siteTitle())

@section('content')
    @php
        $mapEmbed = $content->get('contact.map_embed');

        // The lengths the form accepts, read from the model that stores the
        // message - so the browser stops somebody at exactly the point the
        // validator and the column would have.
        $limits = [
            'name' => \App\Models\Inquiry::MAX_NAME,
            'email' => \App\Models\Inquiry::MAX_EMAIL,
            'subject' => \App\Models\Inquiry::MAX_SUBJECT,
            'message' => \App\Models\Inquiry::MAX_MESSAGE,
        ];

        // The name of the field nobody sees, read from config for the same
        // reason the lengths are read from the model: the form and the guard
        // that inspects it must not be able to drift apart.
        $honeypotField = config('inquiries.honeypot_field', 'company_website');

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

            {{-- The message form. Every message is stored and appears in
                 Configuration > Inquiries, so it is open whether or not a mail
                 server is reachable - what somebody types is never dropped. --}}
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
                            id="contactName" name="name" value="{{ old('name') }}" maxlength="{{ $limits['name'] }}"
                            autocomplete="name" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="contactEmail">Email</label>
                        <input type="email" class="form-control contact-field @error('email') is-invalid @enderror"
                            id="contactEmail" name="email" value="{{ old('email') }}" maxlength="{{ $limits['email'] }}"
                            autocomplete="email" required>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="contactSubject">Subject</label>
                        <input type="text" class="form-control contact-field @error('subject') is-invalid @enderror"
                            id="contactSubject" name="subject" value="{{ old('subject') }}" maxlength="{{ $limits['subject'] }}"
                            required>
                        @error('subject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="contactMessage">Message</label>
                        <textarea class="form-control contact-field @error('message') is-invalid @enderror"
                            id="contactMessage" name="message" rows="5" maxlength="{{ $limits['message'] }}"
                            required>{{ old('message') }}</textarea>
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
                        <input type="text" id="contactCompanyWebsite" name="{{ $honeypotField }}" value=""
                            tabindex="-1" autocomplete="off">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-brand-blue px-4">
                            {{ $content->get('contact.form_button_label') }}
                        </button>

                        {{-- Editable from Configuration, and shown only when
                             something has been written there. --}}
                        @if ($content->has('contact.form_note'))
                            <p class="contact-form-note mb-0" id="contactFormNotice">
                                {{ $content->get('contact.form_note') }}
                            </p>
                        @endif
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
