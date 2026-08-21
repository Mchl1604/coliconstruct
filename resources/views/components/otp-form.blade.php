{{--
    The code-entry card shared by every verification screen: registration, a
    forgotten password, and a change of email.

    One component rather than three copies, so the field, the countdown and
    the resend rules cannot drift apart between flows.

    @param string $action        Where the code is submitted.
    @param string $resendAction  Where another code is asked for.
    @param string $email         The address the code went to, shown back.
    @param int    $retryAfter    Seconds before resending is allowed.
    @param string $backUrl       Where "start over" goes.
--}}
@props([
    'action',
    'resendAction',
    'email',
    'retryAfter' => 0,
    'backUrl' => null,
    'backLabel' => 'Back to sign in',
    'buttonLabel' => 'Verify',
])

<form method="POST" action="{{ $action }}" novalidate>
    @csrf

    <div class="mb-3 text-start">
        <label class="form-label" for="otpCode">Verification Code</label>
        <input type="text" id="otpCode" name="code" class="form-control form-control-lg text-center"
            style="letter-spacing:.6rem; font-weight:600;" inputmode="numeric" autocomplete="one-time-code"
            pattern="[0-9]*" maxlength="6" placeholder="000000" required autofocus>
        <div class="form-text">
            Code expires in {{ \App\Services\OtpService::VALID_MINUTES }} minutes.
        </div>
    </div>

    <div class="d-grid mb-3">
        <button type="submit" class="btn btn-primary btn-lg">{{ $buttonLabel }}</button>
    </div>
</form>

<form method="POST" action="{{ $resendAction }}" class="mb-3" data-otp-resend-form>
    @csrf
    <button type="submit" class="btn btn-link p-0 text-decoration-none" data-otp-resend
        data-retry-after="{{ $retryAfter }}" @disabled($retryAfter > 0)>
        <span data-otp-resend-label>
            {{ $retryAfter > 0 ? 'Resend code in ' . $retryAfter . 's' : 'Resend code' }}
        </span>
    </button>
</form>

@if ($backUrl)
    <div class="text-muted small">
        <a href="{{ $backUrl }}" class="text-decoration-none">{{ $backLabel }}</a>
    </div>
@endif

<script>
    // The 60-second resend cooldown, counted down in the page so the button
    // says how long is left rather than simply refusing. The server enforces
    // the same wait either way - this is courtesy, not the control.
    (function () {
        var button = document.querySelector('[data-otp-resend]');
        var label = document.querySelector('[data-otp-resend-label]');

        if (!button || !label) {
            return;
        }

        var remaining = parseInt(button.dataset.retryAfter || '0', 10);

        if (remaining <= 0) {
            return;
        }

        var tick = setInterval(function () {
            remaining -= 1;

            if (remaining <= 0) {
                clearInterval(tick);
                button.disabled = false;
                label.textContent = 'Resend code';
                return;
            }

            label.textContent = 'Resend code in ' + remaining + 's';
        }, 1000);
    })();

    // Digits only, so a pasted "123 456" still submits cleanly.
    document.getElementById('otpCode')?.addEventListener('input', function (event) {
        event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6);
    });
</script>
