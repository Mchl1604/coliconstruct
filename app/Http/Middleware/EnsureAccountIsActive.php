<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a deactivated or archived account away mid-session, on every route
 * rather than only on the ones that ask about a role.
 *
 * This check used to live inside EnsureUserHasRole, which meant it ran for the
 * two staff portals and nowhere else. Everything a client ever touches - the
 * public site's My Projects, the project details page, the sign-off endpoint -
 * carries `auth` and `password.changed` and no role, so a client whose account
 * had just been switched off kept full access to their projects and could
 * still sign a project off as complete. The same hole covered Profile and
 * Notifications for every role.
 *
 * So the rule is stated once, on the whole web group, where it cannot depend
 * on which portal a route happens to belong to. A guest passes straight
 * through: there is no account to judge, and the routes that need one are
 * already behind `auth`.
 */
class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->canLogin()) {
            return $next($request);
        }

        // Signing out is what would have happened anyway; doing it here means
        // the session cannot be used for one more request after the account
        // was disabled.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(403, 'That account is no longer active.');
        }

        return redirect()
            ->route('auth.login')
            ->with('error', 'That account is no longer active.');
    }
}
