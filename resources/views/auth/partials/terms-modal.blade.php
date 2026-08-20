{{--
    The Terms and Conditions, opened from the registration form.

    A dialog rather than a page on purpose: registration is the one form in the
    system somebody fills in before they have an account, and sending them away
    to read this would cost them everything they had typed. Closing it puts
    them back exactly where they were, with the form untouched.
--}}
@php
    $company = \App\Support\CompanyBranding::toArray();
    $companyName = $company['name'] ?: 'Coliconstruct';
@endphp

<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">Terms and Conditions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body text-start">
                <p class="text-muted small">
                    Please read these terms before opening a {{ $companyName }} client account.
                </p>

                <h6 class="fw-bold mt-4">1. Your account</h6>
                <p class="small mb-3">
                    A client account is for following the work {{ $companyName }} is carrying out for you. You
                    are responsible for the accuracy of the details you register with, for keeping your password
                    to yourself, and for everything done through your account. Tell us at once if you believe
                    somebody else has access to it.
                </p>

                <h6 class="fw-bold mt-4">2. Who may register</h6>
                <p class="small mb-3">
                    You must be at least 18 years old. Registering on behalf of a company means you are
                    authorised to do so. Accounts for {{ $companyName }} staff are created by an administrator
                    and are never opened from this form.
                </p>

                <h6 class="fw-bold mt-4">3. Verifying your email address</h6>
                <p class="small mb-3">
                    Your account is not active until you enter the code we send to the address you register
                    with. That address is how we identify you, how your projects reach your account, and how we
                    contact you about them.
                </p>

                <h6 class="fw-bold mt-4">4. Project information</h6>
                <p class="small mb-3">
                    Schedules, progress reports, photographs and completion records shown in your account
                    describe work as it stands and may change as the work proceeds. Where a project is reported
                    as complete you will be asked to confirm it; if you do not respond within
                    {{ \App\Models\Project::COMPLETION_CONFIRMATION_DAYS }} days, the system records the project
                    as completed on your behalf. Quotations, contracts and any other document in your account
                    remain governed by the signed agreement between us.
                </p>

                <h6 class="fw-bold mt-4">5. Your information</h6>
                <p class="small mb-3">
                    We collect your name, email address, contact number and date of birth to operate your
                    account, and we use them for that purpose. We do not sell your information. Records
                    connected to your projects are retained as part of our business records.
                </p>

                <h6 class="fw-bold mt-4">6. Acceptable use</h6>
                <p class="small mb-3">
                    Do not attempt to reach another client's projects, interfere with the system, or use it for
                    anything unlawful. We may deactivate an account that is used this way.
                </p>

                <h6 class="fw-bold mt-4">7. Availability and changes</h6>
                <p class="small mb-3">
                    The system may be unavailable during maintenance or for reasons outside our control. We may
                    update these terms; continuing to use your account after a change means you accept the
                    updated terms.
                </p>

                <h6 class="fw-bold mt-4">8. Contact</h6>
                <p class="small mb-0">
                    Questions about these terms can be sent to
                    @if ($company['email'])
                        <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>
                    @else
                        our contact address
                    @endif
                    @if ($company['phone'])
                        or raised on {{ $company['phone'] }}
                    @endif.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>
