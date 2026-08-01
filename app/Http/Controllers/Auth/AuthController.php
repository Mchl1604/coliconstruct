<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserAccountService;
use App\Support\PortalHome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

        $attempted = Auth::attempt($credentials, $request->boolean('remember'));

        // The credentials matched; now check the account is allowed in at all.
        if ($attempted && ! Auth::user()->canLogin()) {
            Auth::logout();
            $attempted = false;
        }

        if (! $attempted) {
            RateLimiter::hit($this->throttleKey($request), self::LOCKOUT_SECONDS);

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
