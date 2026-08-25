@props([
    // The client being asked. Used only to word the request, which differs
    // between somebody who agreed to an earlier version and somebody who has
    // never agreed to any.
    'user',
])

{{--
    The Terms and Conditions, to agree to.

    Deliberately a second dialog rather than a mode of x-terms-modal. That one
    is the reading copy - the footer link and the registration form open it,
    and opening it records nothing. This one is a decision, and the two must
    not be able to be mistaken for each other: there is no close button, no
    backdrop to click away and no Escape key, because the only two ways out are
    the two buttons at the bottom.

    Nothing here is the security boundary. A dialog is markup, and markup can
    be removed from a console or skipped by typing a URL - what actually holds
    a client outside their portal is EnsureTermsAreAccepted, on the whole web
    group. This is the part that explains why, and offers the way through.

    The version is not posted. TermsController reads it from the settings, so
    what a client agrees to is what the system currently publishes rather than
    whatever a request body happened to say.
--}}
@php
    $terms = app(\App\Services\SystemContentService::class)->terms();
    $isUpdate = $user->hasAcceptedEarlierTerms();
@endphp

<div class="modal fade" id="termsAgreementModal" tabindex="-1" data-bs-backdrop="static"
    data-bs-keyboard="false" aria-labelledby="termsAgreementModalLabel" data-terms-agreement>
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="termsAgreementModalLabel">
                    @if ($isUpdate)
                        Our Terms and Conditions have been updated
                    @else
                        Please review our Terms and Conditions
                    @endif
                </h5>
                {{-- No close button. Closing is not one of the choices. --}}
            </div>

            <div class="modal-body">
                <div class="alert alert-warning border-0 d-flex gap-2 align-items-start" role="alert">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <div class="small mb-0">
                        @if ($isUpdate)
                            We have updated our Terms and Conditions since you last agreed to them.
                            Please read the current version below and accept it to carry on using
                            your account. If you would rather not accept them now, you can log out
                            and we will ask again next time you sign in.
                        @else
                            Please read the Terms and Conditions below and accept them to carry on
                            using your account. If you would rather not accept them now, you can log
                            out and we will ask again next time you sign in.
                        @endif
                    </div>
                </div>

                <div class="border rounded p-3 text-start small" style="white-space: pre-wrap; max-height: 45vh; overflow-y: auto;">{{ $terms }}</div>
            </div>

            <div class="modal-footer justify-content-between">
                {{-- Logging out is the same endpoint the account menu uses, so
                     declining leaves the session in exactly the state signing
                     out normally would - and records nothing. --}}
                <form method="POST" action="{{ route('auth.logout') }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">Log Out</button>
                </form>

                <form method="POST" action="{{ route('terms.accept') }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn btn-primary">Agree</button>
                </form>
            </div>

        </div>
    </div>
</div>

@push('scripts')
    <script src="/js/termsAgreement.js"></script>
@endpush
