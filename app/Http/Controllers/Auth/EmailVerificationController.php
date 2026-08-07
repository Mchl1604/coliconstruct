<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AccountStatusMail;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Support\PortalHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Proving a newly registered address is real.
 *
 * A registration opens the account but does not admit it: the address is
 * unverified, the account cannot sign in, and a code sent to that address is
 * the only thing that changes either fact. The account id is never taken from
 * the request - it is read from the session put there by the registration
 * that created it - so this page cannot be pointed at somebody else's account.
 */
class EmailVerificationController extends Controller
{
    /** Where the address awaiting verification is held between requests. */
    public const SESSION_KEY = 'verification.email';

    public function __construct(
        private readonly OtpService $otp,
        private readonly ActivityLogger $activityLogger,
        private readonly EmailService $email,
    ) {}

    /**
     * The code-entry page.
     */
    public function show(Request $request)
    {
        $account = $this->pendingAccount($request);

        if (! $account) {
            return redirect()->route('auth.login')
                ->with('error', 'Start by signing in or registering.');
        }

        if (! $account->requiresEmailVerification()) {
            return redirect()->route('auth.login')
                ->with('success', 'That address is already verified. Sign in below.');
        }

        return view('auth.verify-email', [
            'email' => $account->email,
            'retryAfter' => $this->otp->secondsUntilResend($account->email, OtpVerification::PURPOSE_REGISTRATION),
        ]);
    }

    /**
     * Check the code, verify the address, and let the account in.
     */
    public function verify(Request $request): RedirectResponse
    {
        $account = $this->pendingAccount($request);

        if (! $account) {
            return redirect()->route('auth.login')
                ->with('error', 'That verification session has expired. Sign in to start again.');
        }

        $request->validate(
            ['code' => ['required', 'string', 'max:12']],
            ['code.required' => 'Enter the code that was emailed to you.']
        );

        try {
            $this->otp->verify(
                $account->email,
                OtpVerification::PURPOSE_REGISTRATION,
                $request->string('code')->toString()
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $account->forceFill([
            'email_verified_at' => now(),
            'status' => User::STATUS_ACTIVE,
        ])->save();

        $this->activityLogger->recordAnonymous(
            ActivityLog::REGISTRATION_VERIFIED,
            $account->fullName(),
            $account,
            sprintf('%s verified %s and activated their account.', $account->fullName(), $account->email)
        );

        // The account is now real, so the welcome that confirms it can go out.
        $this->email->sendTo($account, new AccountStatusMail($account, AccountStatusMail::ACTIVATED));

        $request->session()->forget(self::SESSION_KEY);

        Auth::login($account);
        $request->session()->regenerate();

        $account->forceFill(['last_login_at' => now()])->save();

        return redirect(PortalHome::url($account))
            ->with('success', 'Your email is verified. Welcome to '.config('company.name').'.');
    }

    /**
     * Issue a replacement code.
     *
     * The 60-second wait and the hourly cap are both enforced by OtpService,
     * so this endpoint cannot be used to mailbomb an address however often it
     * is called.
     */
    public function resend(Request $request): RedirectResponse
    {
        $account = $this->pendingAccount($request);

        if (! $account) {
            return redirect()->route('auth.login')
                ->with('error', 'That verification session has expired. Sign in to start again.');
        }

        try {
            $this->otp->issue(
                $account->email,
                OtpVerification::PURPOSE_REGISTRATION,
                $account
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'A new code is on its way to '.$account->email.'.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The account this page is about, or null when there is no session for it.
     */
    private function pendingAccount(Request $request): ?User
    {
        $email = $request->session()->get(self::SESSION_KEY);

        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::where('email', $email)->first();
    }
}
