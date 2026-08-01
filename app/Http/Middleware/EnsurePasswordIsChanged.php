<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an account on the change-password screen until it has replaced the
 * temporary password it was issued.
 *
 * Applied to every portal, so there is no route a user can reach with a
 * password an administrator has seen.
 */
class EnsurePasswordIsChanged
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        // The change screen and signing out are the only things still allowed.
        if ($request->routeIs('auth.password.*', 'auth.logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Choose a new password before continuing.');
        }

        return redirect()->route('auth.password.change');
    }
}
