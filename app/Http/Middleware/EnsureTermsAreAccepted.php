<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a client outside their own portal until they have agreed to the Terms
 * and Conditions as they currently stand.
 *
 * The dialog on the home page is what a client sees; this is what makes it
 * mean anything. A modal is markup, and markup can be dismissed from a console,
 * skipped by typing a URL, or never rendered at all by a request that asks for
 * JSON - so the rule is stated here, on the whole web group, where every route
 * into the client's portal has to pass it whatever the browser did.
 *
 * Three things stay open while somebody is held, because a lock with no way out
 * is a lock on the person rather than on the portal:
 *
 *   - the public website, which is where the dialog is rendered and where the
 *     terms can be read;
 *   - agreeing, which is the way through;
 *   - signing out, which is the other one.
 *
 * Clients only. An employee is bound by their employment rather than by a
 * dialog, and a rewrite of the terms must never shut an administrator out of
 * the portal - least of all the Super Admin, who would then be locked away
 * from the settings page that holds the document causing it.
 */
class EnsureTermsAreAccepted
{
    /**
     * Routes that stay reachable while a client is being held.
     *
     * `terms.*` is the agreement itself. `auth.logout` is the way out.
     * `auth.password.*` is left open so a client who must also change their
     * password is not caught between two gates, each redirecting to the
     * other's page. `media.system` serves the logo the held page draws.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'terms.*',
        'auth.logout',
        'auth.password.*',
        'media.system',
    ];

    /**
     * The public website: readable by anybody at all, so being signed in and
     * held cannot be a reason to see less of it than a stranger does.
     *
     * The home page is also where the agreement dialog renders, which is why a
     * blocked request is sent there rather than to a terms page of its own -
     * the existing modal is the interface, and a second screen saying the same
     * thing would be the same feature twice. The Contact form's POST is
     * deliberately absent: reading the public site is not writing to it.
     *
     * @var array<int, string>
     */
    private const PUBLIC_PAGES = [
        'landing.home',
        'public.about',
        'public.contact',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresTermsAcceptance()) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED) || $request->routeIs(...self::PUBLIC_PAGES)) {
            return $next($request);
        }

        $message = 'Please accept the updated Terms and Conditions to continue.';

        // The schedule panel, the notification feed and every other fetch the
        // portal makes are answered rather than redirected: a 302 to an HTML
        // page arriving where JSON was expected reads as a broken endpoint,
        // not as a rule.
        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route('landing.home')->with('error', $message);
    }
}
