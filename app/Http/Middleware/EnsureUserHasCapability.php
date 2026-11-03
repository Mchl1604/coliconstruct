<?php

namespace App\Http\Middleware;

use App\Support\PortalHome;
use App\Support\RoleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the roles holding one power, inside a part of the portal
 * more roles reach - `capability:create-projects`. See RoleAccess.
 *
 * Answers a wrong turn the way EnsureUserCanReachArea does, except that a form
 * being sent is told why rather than losing it to a redirect home.
 */
class EnsureUserHasCapability
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('auth.login'));
        }

        if (RoleAccess::can($user, $capability)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(403, 'Your account cannot do this.');
        }

        return redirect(PortalHome::url($user));
    }
}
