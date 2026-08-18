<?php

namespace App\Http\Middleware;

use App\Support\PortalHome;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to the roles that own it.
 *
 * Used as `role:super_admin,admin`. A signed-in user who reaches the wrong
 * portal is sent to their own rather than shown a 403 - being in the wrong
 * place is a navigation mistake, not an attack.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('auth.login'));
        }

        // Whether the account may still be here at all is EnsureAccountIsActive's
        // question, and it is asked of the whole web group - this middleware
        // is only about which portal a role belongs in.

        if (in_array($user->role, $roles, true)) {
            return $next($request);
        }

        // An API-shaped request gets a status code; a page gets sent home.
        if ($request->expectsJson()) {
            abort(403, 'This area is not available to your account.');
        }

        return redirect(PortalHome::url($user));
    }
}
