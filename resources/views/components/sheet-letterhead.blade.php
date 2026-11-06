{{--
    The letterhead the Service Report and the quotation print under. The
    address, email and phone are the ones on Configuration > System Contents >
    Contact, so the public site and every sheet agree.
--}}
@php
    $company = \App\Support\CompanyBranding::letterhead();
    $contact = collect([
        filled($company['email']) ? 'Email: '.$company['email'] : null,
        filled($company['phone']) ? 'HP: '.$company['phone'] : null,
    ])->filter()->implode(' ');
@endphp

<div class="sr-letterhead">
    <img src="/img/coliconstructlogor.png" alt="" class="sr-logo">
    <div class="sr-company">
        <div class="sr-company-name">COLICONSTRUCT ENGINEERING SERVICES</div>
        <div class="sr-company-line">HEATING - VENTILATION - AIRCONDITIONING</div>
        @if (filled($company['address']))
            <div class="sr-company-small">{{ $company['address'] }}</div>
        @endif
        @if ($contact !== '')
            <div class="sr-company-small">{{ $contact }}</div>
        @endif
    </div>
</div>
