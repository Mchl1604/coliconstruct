<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AccountStatusMail;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\UserAccountService;
use App\Support\PortalHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Proving a registered address is real.
 *
 * Registration creates nothing in `users`: the details wait in
 * tbl_pending_registrations, and the code sent to the address is what turns
 * them into an account. A registration nobody finishes is therefore a row
 * that expires, not an account holding an address forever.
 *
 * Two things can be waiting on a code here:
 *
 *   - a PendingRegistration, which is every registration made since
 *     tbl_pending_registrations existed; verifying it creates the account.
 *   - an unverified User, which can only be an account registered before
 *     that - deployed databases still hold them. Verifying one marks the
 *     account it already has.
 *
 * Either way the address is never taken from the request. It is read from the
 * session the registration put it in, so this page cannot be pointed at
 * somebody else's registration.
 */
class EmailVerificationController extends Controller
{
    /** Where the address awaiting verification is held between requests. */
    public const SESSION_KEY = 'verification.email';

    public function __construct(
        private readonly OtpService $otp,
        private readonly ActivityLogger $activityLogger,
        private readonly EmailService $email,
        private readonly UserAccountService $accounts,
    ) {}

    /**
     * The code-entry page.
     */
    public function show(Request $request)
    {
        $subject = $this->awaiting($request);

        if (! $subject) {
            return redirect()->route('auth.login')
                ->with('error', 'Start by signing in or registering.');
        }

        if ($subject instanceof User && ! $subject->requiresEmailVerification()) {
            return redirect()->route('auth.login')
                ->with('success', 'That address is already verified. Sign in below.');
        }

        return view('auth.verify-email', [
            'email' => $subject->email,
            'retryAfter' => $this->otp->secondsUntilResend($subject->email, OtpVerification::PURPOSE_REGISTRATION),
        ]);
    }

    /**
     * Check the code, make or mark the account, and let it in.
     */
    public function verify(Request $request): RedirectResponse
    {
        $subject = $this->awaiting($request);

        if (! $subject) {
            return redirect()->route('auth.login')
                ->with('error', 'That verification session has expired. Sign in to start again.');
        }

        $request->validate(
            ['code' => ['required', 'string', 'max:12']],
            ['code.required' => 'Enter the emailed code.']
        );

        try {
            $this->otp->verify(
                $subject->email,
                OtpVerification::PURPOSE_REGISTRATION,
                $request->string('code')->toString()
            );
        } catch (RuntimeException $exception) {
            // Nothing above this line wrote anything, so a wrong code leaves
            // the registration exactly as it was - still pending, still
            // resendable, and with one fewer attempt on the code.
            return back()->with('error', $exception->getMessage());
        }

        if ($subject instanceof PendingRegistration) {
            $account = $this->accounts->completeRegistration($subject);
        } else {
            $account = $subject;
            $account->forceFill([
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
            ])->save();
        }

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
            ->with('success', 'Email verified. Welcome to '.config('company.name').'.');
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
        $subject = $this->awaiting($request);

        if (! $subject) {
            return redirect()->route('auth.login')
                ->with('error', 'That verification session has expired. Sign in to start again.');
        }

        try {
            $this->otp->issue(
                $subject->email,
                OtpVerification::PURPOSE_REGISTRATION,
                $subject instanceof User ? $subject : null,
                $subject->fullName()
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'New code sent to '.$subject->email.'.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Whatever is waiting on a code for the address in the session.
     *
     * A pending registration is looked for first: it is the only thing
     * registration produces now. An unverified account is the older shape and
     * is still answered so that one created before this table existed can be
     * finished rather than stranded.
     */
    private function awaiting(Request $request): PendingRegistration|User|null
    {
        $email = $request->session()->get(self::SESSION_KEY);

        if (! is_string($email) || $email === '') {
            return null;
        }

        return PendingRegistration::liveFor($email)
            ?? User::where('email', $email)->first();
    }
}
