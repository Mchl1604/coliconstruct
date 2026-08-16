<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\OtpService;
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
     * A deactivated or archived account fails exactly like a wrong password -
     * same message either way, so nothing here tells an attacker which
     * addresses exist and which are merely switched off.
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
            $pending = Auth::user();
            Auth::logout();

            RateLimiter::clear($this->throttleKey($request));

            return $this->sendVerificationCode($request, $pending);
        }

        // The credentials matched; now check the account is allowed in at all.
        if ($attempted && ! Auth::user()->canLogin()) {
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

            // Deactivated, archived, wrong password, unusable hash: one message
            // for all of them, so nothing here tells a stranger which addresses
            // exist and which are merely switched off.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
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
     * The account is created unverified and is not signed in. A code emailed
     * to the address is the only thing that activates it - see
     * EmailVerificationController - so an address nobody can read never
     * becomes a working account.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:32', User::CONTACT_NUMBER_RULE],
            // Nobody under 18 gets an account, whichever form opens it.
            'birthdate' => AccountAge::rules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'email.unique' => 'An account already exists for that email address.',
            'contact_number.regex' => 'Enter a valid contact number.',
            'password.confirmed' => 'The two passwords do not match.',
        ] + AccountAge::messages());

        $user = app(UserAccountService::class)->registerClient($validated);

        return $this->sendVerificationCode($request, $user);
    }

    /**
     * Start - or restart - the verification of a registered address.
     *
     * Shared by registration and by a sign-in attempt against an account that
     * never finished one, so both land on the same page having sent the same
     * kind of code.
     */
    private function sendVerificationCode(Request $request, User $account)
    {
        $request->session()->put(EmailVerificationController::SESSION_KEY, $account->email);

        try {
            app(OtpService::class)->issue(
                $account->email,
                OtpVerification::PURPOSE_REGISTRATION,
                $account
            );
        } catch (RuntimeException $exception) {
            // A code was sent moments ago, or too many have been asked for.
            // Either way the page to be on is the same one.
            return redirect()->route('auth.verify')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('auth.verify')
            ->with('success', 'We sent a verification code to '.$account->email.'.');
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
        return redirect()->route('landing.home')->with('success', 'You have been signed out.');
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

        $this->activityLogger->record(
            ActivityLog::PASSWORD_CHANGED,
            $user,
            sprintf('%s changed their own password.', $user->fullName())
        );

        return redirect(PortalHome::url($user))
            ->with('success', 'Your password has been updated.');
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
