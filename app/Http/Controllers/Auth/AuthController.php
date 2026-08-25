<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\OtpService;
use App\Services\SessionGuard;
use App\Services\UserAccountService;
use App\Support\AccountAge;
use App\Support\PortalHome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Signing in, signing out, and the forced first password change.
 *
 * Authentication is deliberately thin: the rule about who may sign in lives
 * on the User model (canLogin), so this controller and the Configuration
 * module can never disagree about what "deactivated" means.
 */
class AuthController extends Controller
{
    /** Failed attempts allowed before the address is locked out. */
    private const MAX_ATTEMPTS = 5;

    /** How long a lockout lasts, in seconds. */
    private const LOCKOUT_SECONDS = 60;

    /**
     * Failed sign-ins for one address within an hour before the super admins
     * are told about it. Matched to MAX_ATTEMPTS: one lockout is a forgotten
     * password, and the notification is for the run that keeps going.
     */
    private const FAILED_SIGN_IN_ALERT_THRESHOLD = 5;

    /**
     * What somebody with the right password for a switched-off account is told.
     *
     * Names no role, no reason and nobody in particular: it says the account is
     * off and who to ask, which is everything the holder can act on and nothing
     * an attacker who does not already have the password could learn.
     */
    public const DEACTIVATED_MESSAGE = 'Your account has been deactivated. Please contact an administrator for assistance.';

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications
    ) {}

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect(PortalHome::url(Auth::user()));
        }

        return view('auth.login');
    }

    /**
     * Verify credentials and open a session.
     *
     * One message covers every failure but one: a wrong password, an address
     * with no account behind it, an archived account and an unusable stored
     * hash all read the same, so nothing here tells a stranger which addresses
     * exist and which are merely switched off.
     *
     * The exception is a deactivated account whose password was correct, which
     * is told so plainly - see DEACTIVATED_MESSAGE. That is deliberate and it
     * gives nothing away: only somebody who already holds the right password
     * ever sees it, and to them "these credentials do not match our records"
     * is not a security boundary, it is a lie that sends the account's real
     * owner to reset a password that was never the problem.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $account = User::where('email', $credentials['email'])->first();

        // A stored value that is not a hash - a placeholder from a seed file,
        // a truncated import - makes the bcrypt hasher *throw* rather than
        // return false, so the attempt is headed off here. Such an account can
        // never sign in however correct the password is, which is exactly what
        // it looks like from the outside; the reason is logged so it is
        // findable, and `users:repair-passwords` fixes it.
        $unusable = $account && ! $account->hasUsablePassword();

        if ($unusable) {
            Log::warning('Sign-in impossible: the stored password is not a hash.', [
                'user_id' => $account->id,
                'email' => $account->email,
                'fix' => 'php artisan users:repair-passwords',
            ]);
        }

        // No "keep me signed in": a session that outlives the browser is a
        // liability on shared machines, and this is a work system. Somebody
        // locked out uses the reset link instead.
        $attempted = ! $unusable && Auth::attempt($credentials);

        // The credentials matched, but the address has never been proved. The
        // account is real and its password was right, so this is not a failure
        // to hide behind the generic message - it is sent to finish the
        // registration it started.
        if ($attempted && Auth::user()->requiresEmailVerification()) {
            $unproved = Auth::user();
            Auth::logout();

            RateLimiter::clear($this->throttleKey($request));

            // Only accounts that predate tbl_pending_registrations can be in
            // this state - registration no longer creates one - but they exist
            // in deployed databases and must still be able to finish.
            return $this->sendVerificationCode(
                $request,
                $unproved->email,
                $unproved->fullName(),
                $unproved
            );
        }

        // The credentials matched; now check the account is allowed in at all.
        // Which of the two reasons it was is remembered, because they are not
        // the same thing to the person in front of the screen: a deactivated
        // account is somebody's own account, switched off, and they need to be
        // told that rather than sent round the password reset.
        //
        // An archived account is not told apart. That is a record of somebody
        // who has left, kept for the history, and it is closer to "no such
        // account" than to "your account is off".
        $deactivated = false;

        if ($attempted && ! Auth::user()->canLogin()) {
            $deactivated = ! Auth::user()->is_archived
                && Auth::user()->status === User::STATUS_DEACTIVATED;

            Auth::logout();
            $attempted = false;
        }

        if (! $attempted) {
            RateLimiter::hit($this->throttleKey($request), self::LOCKOUT_SECONDS);

            // Recorded whether or not the address exists: a run of failures
            // against an address that has no account is exactly the pattern an
            // audit trail should show.
            $this->activityLogger->recordAnonymous(
                ActivityLog::LOGIN_FAILED,
                $account?->fullName() ?? $credentials['email'],
                $account,
                sprintf('Failed sign-in attempt for %s.', $credentials['email'])
            );

            $this->alertOnRepeatedFailures($credentials['email']);

            // Archived, wrong password, unusable hash, no such address: one
            // message for all of them, so nothing here tells a stranger which
            // addresses exist and which are merely switched off. The one
            // exception is above, and is reached only by somebody who already
            // had the password.
            throw ValidationException::withMessages([
                'email' => $deactivated
                    ? self::DEACTIVATED_MESSAGE
                    : 'Those credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        // A fresh session id, so a session fixed before sign-in is worthless.
        $request->session()->regenerate();

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->activityLogger->record(
            ActivityLog::LOGIN,
            $user,
            sprintf('%s signed in.', $user->fullName())
        );

        if ($user->must_change_password) {
            return redirect()->route('auth.password.change');
        }

        return redirect()->intended(PortalHome::url($user));
    }

    // ------------------------------------------------------------------
    // Public registration
    // ------------------------------------------------------------------

    public function showRegister()
    {
        if (Auth::check()) {
            return redirect(PortalHome::url(Auth::user()));
        }

        return view('auth.register');
    }

    /**
     * Open a client account from the public site.
     *
     * Registration is client-only by construction: the role is never read from
     * the request, so no crafted field can create an employee account. Staff
     * accounts exist solely because an admin or super admin created them in
     * Configuration.
     *
     * Nothing in `users` is created here. The details are parked in
     * tbl_pending_registrations and a code is emailed to the address; only
     * that code coming back makes the account - see
     * EmailVerificationController. An address nobody can read therefore never
     * takes an account, a user_code, or a place in Configuration's listings.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:'.User::CONTACT_NUMBER_LENGTH, User::CONTACT_NUMBER_RULE],
            // Nobody under 18 gets an account, whichever form opens it.
            'birthdate' => AccountAge::rules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            // Checked on the server as well as in the browser: a checkbox is
            // the easiest field in a form to leave out of a request.
            'terms' => ['accepted'],
        ], [
            'email.unique' => 'An account already exists for that email address.',
            'contact_number.regex' => User::CONTACT_NUMBER_MESSAGE,
            'contact_number.max' => User::CONTACT_NUMBER_MESSAGE,
            'password.confirmed' => 'The two passwords do not match.',
            'terms.accepted' => 'Accept the Terms and Conditions to continue.',
        ] + AccountAge::messages());

        // Never handed to the account service: agreeing is a precondition of
        // registering, not a column on the account.
        unset($validated['terms']);

        $pending = app(UserAccountService::class)->startRegistration($validated);

        return $this->sendVerificationCode($request, $pending->email, $pending->fullName());
    }

    /**
     * Start - or restart - the verification of a registered address.
     *
     * Shared by registration and by a sign-in attempt against an account that
     * never finished one, so both land on the same page having sent the same
     * kind of code. Takes the address rather than an account because in the
     * first case there is no account yet - only a pending registration - and
     * in the second there is.
     */
    private function sendVerificationCode(Request $request, string $email, string $name, ?User $account = null)
    {
        $request->session()->put(EmailVerificationController::SESSION_KEY, $email);

        try {
            app(OtpService::class)->issue(
                $email,
                OtpVerification::PURPOSE_REGISTRATION,
                $account,
                $name
            );
        } catch (RuntimeException $exception) {
            // A code was sent moments ago, or too many have been asked for.
            // Either way the page to be on is the same one.
            return redirect()->route('auth.verify')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('auth.verify')
            ->with('success', 'Verification code sent to '.$email.'.');
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Recorded before the session goes, while there is still an actor to
        // name.
        if ($user) {
            $this->activityLogger->record(
                ActivityLog::LOGOUT,
                $user,
                sprintf('%s signed out.', $user->fullName())
            );
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Home rather than the sign-in form: signing out should leave somebody
        // on the public website, where the header offers Login again.
        return redirect()->route('landing.home')->with('success', 'Signed out.');
    }

    // ------------------------------------------------------------------
    // First-login password change
    // ------------------------------------------------------------------

    public function showPasswordChange(Request $request)
    {
        // Nothing to do here once the password is the user's own.
        if (! $request->user()->must_change_password) {
            return redirect(PortalHome::url($request->user()));
        }

        return view('auth.change-password');
    }

    /**
     * Replace the issued password with one only this user knows.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'password.confirmed' => 'The two new passwords do not match.',
            'password.min' => 'The new password must be at least 8 characters.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        if ($validated['current_password'] === $validated['password']) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password different from the temporary one.',
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        // Anywhere else this account is signed in was signed in with the
        // temporary password an administrator handed over, so it does not get
        // to carry on. This session is kept - the person is using it.
        app(SessionGuard::class)->logOutOtherSessions($user);

        $this->activityLogger->record(
            ActivityLog::PASSWORD_CHANGED,
            $user,
            sprintf('%s changed their own password.', $user->fullName())
        );

        return redirect(PortalHome::url($user))
            ->with('success', 'Password updated.');
    }

    // ------------------------------------------------------------------
    // Internals
    //
    // Forgetting a password lives in PasswordResetController: it is three
    // pages and a one-time code rather than a single action, and it does not
    // belong in the middle of signing in.
    // ------------------------------------------------------------------

    /**
     * Stop credential stuffing: five failures against one address from one
     * client buys a minute of silence.
     */
    private function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return 'login|'.mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }

    /**
     * Tell the super admins once a run of failures against one address crosses
     * the threshold.
     *
     * Counted from the audit trail rather than the rate limiter: the limiter
     * forgets after a minute, and "five in the last hour" is the pattern worth
     * knowing about. Fires on the crossing only, so a persistent attacker does
     * not fill the bell.
     */
    private function alertOnRepeatedFailures(string $email): void
    {
        $failures = ActivityLog::query()
            ->where('action', ActivityLog::LOGIN_FAILED)
            ->where('description', 'like', '%'.$email.'%')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($failures === self::FAILED_SIGN_IN_ALERT_THRESHOLD) {
            $this->notifications->repeatedFailedSignIns($email, $failures);
        }
    }
}
