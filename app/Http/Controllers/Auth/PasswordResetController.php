<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Forgetting a password, and setting a new one.
 *
 * Three steps: name the address, prove you can read its inbox, choose a new
 * password. The proof is a one-time code rather than a link, so nothing that
 * grants access is left sitting in a mailbox or a browser history.
 *
 * Two rules run through all of it:
 *
 *   - The first step answers identically whether or not the address is on
 *     file. Telling a stranger which addresses have accounts is the one thing
 *     this page must not do.
 *   - The final step re-checks the verification against the database rather
 *     than trusting the session alone, so a session that outlived its code
 *     cannot set a password.
 */
class PasswordResetController extends Controller
{
    /** The address the reset is for. */
    public const SESSION_EMAIL = 'password_reset.email';

    /** Set once a code has been confirmed for that address. */
    public const SESSION_VERIFIED = 'password_reset.verified';

    /**
     * How long a confirmed code keeps the reset page open.
     *
     * Long enough to choose a password, short enough that walking away from a
     * shared machine does not leave the account open to the next person.
     */
    private const VERIFIED_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly OtpService $otp,
        private readonly ActivityLogger $activityLogger,
        private readonly EmailService $email,
    ) {}

    // ------------------------------------------------------------------
    // Step one: name the address
    // ------------------------------------------------------------------

    public function request(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('profile.edit');
        }

        return view('auth.forgot-password', [
            'mailEnabled' => $this->email->isDeliverable(),
        ]);
    }

    /**
     * Email a code, and say so whether or not there was anywhere to send it.
     */
    public function sendCode(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        if (! $this->email->isDeliverable()) {
            return back()->with(
                'error',
                'Verification codes cannot be sent right now. '
                    .'Ask an administrator to reset your password from Configuration.'
            );
        }

        $address = mb_strtolower(trim($request->string('email')->toString()));
        $account = User::where('email', $address)->first();

        // Only a real, admissible account gets a code. Everyone else gets the
        // same page and the same words, and nothing in the inbox.
        if ($account && $account->canLogin()) {
            try {
                $this->otp->issue($address, OtpVerification::PURPOSE_FORGOT_PASSWORD, $account);
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        $this->activityLogger->recordAnonymous(
            ActivityLog::PASSWORD_RESET_REQUESTED,
            $account?->fullName() ?? $address,
            $account,
            sprintf('A password reset code was requested for %s.', $address)
        );

        $request->session()->put(self::SESSION_EMAIL, $address);
        $request->session()->forget(self::SESSION_VERIFIED);

        return redirect()
            ->route('auth.password.verify')
            ->with('success', 'Code sent.');
    }

    // ------------------------------------------------------------------
    // Step two: prove you can read the inbox
    // ------------------------------------------------------------------

    public function showVerify(Request $request)
    {
        $address = $this->addressInProgress($request);

        if (! $address) {
            return redirect()->route('auth.password.request');
        }

        return view('auth.verify-reset-code', [
            'email' => $address,
            'retryAfter' => $this->otp->secondsUntilResend($address, OtpVerification::PURPOSE_FORGOT_PASSWORD),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $address = $this->addressInProgress($request);

        if (! $address) {
            return redirect()->route('auth.password.request');
        }

        $request->validate(
            ['code' => ['required', 'string', 'max:12']],
            ['code.required' => 'Enter the emailed code.']
        );

        try {
            $this->otp->verify(
                $address,
                OtpVerification::PURPOSE_FORGOT_PASSWORD,
                $request->string('code')->toString()
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Regenerated so the session that proved the address is not the one an
        // attacker may have fixed beforehand.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_EMAIL, $address);
        $request->session()->put(self::SESSION_VERIFIED, now()->toIso8601String());

        return redirect()->route('auth.password.reset');
    }

    public function resend(Request $request): RedirectResponse
    {
        $address = $this->addressInProgress($request);

        if (! $address) {
            return redirect()->route('auth.password.request');
        }

        $account = User::where('email', $address)->first();

        if ($account && $account->canLogin()) {
            try {
                $this->otp->issue($address, OtpVerification::PURPOSE_FORGOT_PASSWORD, $account);
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        return back()->with('success', 'New code sent.');
    }

    // ------------------------------------------------------------------
    // Step three: choose a new password
    // ------------------------------------------------------------------

    public function showReset(Request $request)
    {
        if (! $this->verifiedAddress($request)) {
            return redirect()->route('auth.password.request')
                ->with('error', 'Verify your email address before setting a new password.');
        }

        return view('auth.reset-password', [
            'email' => $this->verifiedAddress($request),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $address = $this->verifiedAddress($request);

        if (! $address) {
            return redirect()->route('auth.password.request')
                ->with('error', 'That reset has expired. Ask for a new code.');
        }

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
            'password.min' => 'The new password must be at least 8 characters.',
        ]);

        $account = User::where('email', $address)->first();

        if (! $account || ! $account->canLogin()) {
            return redirect()->route('auth.password.request')
                ->with('error', 'That reset is no longer valid. Ask for a new code.');
        }

        $account->forceFill([
            'password' => $request->string('password')->toString(),
            // The password is now the account holder's own, so any outstanding
            // demand that they choose one is satisfied.
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        // Every session this account had open is ended. Somebody resetting a
        // forgotten password is often doing it because another person knows
        // the old one, and until now the new password took effect while
        // whoever was already signed in stayed signed in. None of them is the
        // session making this request - a reset is done signed out.
        app(SessionGuard::class)->logOutOtherSessions($account, exceptCurrent: false);

        // The code and the session that carried it are both spent.
        $this->otp->clear($address, OtpVerification::PURPOSE_FORGOT_PASSWORD);
        $request->session()->forget([self::SESSION_EMAIL, self::SESSION_VERIFIED]);

        $this->activityLogger->recordAnonymous(
            ActivityLog::PASSWORD_RESET_COMPLETED,
            $account->fullName(),
            $account,
            sprintf('%s reset their password after verifying %s.', $account->fullName(), $address)
        );

        return redirect()
            ->route('auth.login')
            ->with('success', 'Password reset. Sign in with it now.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The address this reset is for, from the session that started it.
     */
    private function addressInProgress(Request $request): ?string
    {
        $address = $request->session()->get(self::SESSION_EMAIL);

        return is_string($address) && $address !== '' ? $address : null;
    }

    /**
     * The address whose code has been confirmed, and confirmed recently enough
     * to still count.
     *
     * The database is consulted as well as the session: a session flag on its
     * own would let a copied cookie set a password long after the code that
     * earned it had been invalidated.
     */
    private function verifiedAddress(Request $request): ?string
    {
        $address = $this->addressInProgress($request);
        $verifiedAt = $request->session()->get(self::SESSION_VERIFIED);

        if (! $address || ! is_string($verifiedAt)) {
            return null;
        }

        $confirmed = $this->otp->hasVerified(
            $address,
            OtpVerification::PURPOSE_FORGOT_PASSWORD,
            self::VERIFIED_WINDOW_MINUTES
        );

        return $confirmed ? $address : null;
    }
}
