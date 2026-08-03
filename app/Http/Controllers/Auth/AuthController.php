<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CredentialDelivery;
use App\Services\UserAccountService;
use App\Support\PortalHome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        // The credentials matched; now check the account is allowed in at all.
        if ($attempted && ! Auth::user()->canLogin()) {
            Auth::logout();
            $attempted = false;
        }

        if (! $attempted) {
            RateLimiter::hit($this->throttleKey($request), self::LOCKOUT_SECONDS);

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
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s\-().]{5,20}$/'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'email.unique' => 'An account already exists for that email address.',
            'contact_number.regex' => 'Enter a valid contact number.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $user = app(UserAccountService::class)->registerClient($validated);

        Auth::login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect(PortalHome::url($user))
            ->with('success', 'Welcome to Coliconstruct.');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('auth.login')->with('success', 'You have been signed out.');
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

        return redirect(PortalHome::url($user))
            ->with('success', 'Your password has been updated.');
    }

    // ------------------------------------------------------------------
    // Forgotten password
    // ------------------------------------------------------------------

    public function showForgotPassword(CredentialDelivery $credentials)
    {
        if (Auth::check()) {
            return redirect(PortalHome::url(Auth::user()));
        }

        // Without a real mailer there is nowhere to send a link, so the page
        // says so rather than pretending one is on its way.
        return view('auth.forgot-password', [
            'mailEnabled' => $credentials->isDeliverable(),
        ]);
    }

    /**
     * Email a reset link.
     *
     * The answer is the same whether or not the address is on file: telling a
     * stranger which addresses exist is the one thing this page must not do.
     */
    public function sendResetLink(Request $request, CredentialDelivery $credentials)
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        if (! $credentials->isDeliverable()) {
            return back()->with(
                'error',
                'Email is not configured on this system, so a reset link cannot be sent. '
                    .'Ask an administrator to reset your password from Configuration.'
            );
        }

        Password::sendResetLink($request->only('email'));

        return back()->with(
            'success',
            'If that address has an account, a reset link is on its way. The link expires in 60 minutes.'
        );
    }

    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    /**
     * Set the new password against a link's token.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
            'password.min' => 'The new password must be at least 8 characters.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                // Whatever the account was opened with, this is now the user's
                // own - so the forced first change no longer applies.
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is no longer valid. Ask for a new one.',
            ]);
        }

        return redirect()
            ->route('auth.login')
            ->with('success', 'Your password has been reset. Sign in with it now.');
    }

    // ------------------------------------------------------------------
    // Internals
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
}
