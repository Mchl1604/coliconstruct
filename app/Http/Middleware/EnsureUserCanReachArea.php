<?php

namespace App\Http\Middleware;

use App\Support\PortalHome;
use App\Support\RoleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to the office roles that reach one part of the
 * portal.
 *
 * Used as `area:scheduling` or `area:inventory` - see RoleAccess for who
 * reaches which - or `area:scheduling,inventory` for a page either side may
 * open, like Configuration. Answers a wrong turn exactly the way the role
 * middleware does: a page sends the reader to their own home, a JSON request
 * gets a 403.
 */
class EnsureUserCanReachArea
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$areas): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('auth.login'));
        }

        foreach ($areas as $area) {
            if (RoleAccess::allows($user, $area)) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            abort(403, 'This area is not available to your account.');
        }

        return redirect(PortalHome::url($user));
    }
}
