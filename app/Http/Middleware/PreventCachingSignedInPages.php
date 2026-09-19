<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nothing drawn for a signed-in person is kept by the browser.
 *
 * `no-cache` alone lets a browser hold the page and show it again from its
 * back/forward cache without asking the server - so pressing Back after
 * signing out put the last project, address and documents back on screen for
 * whoever used the computer next. `no-store` is what keeps it out of both
 * caches. Guests' pages are left alone: the public website is meant to cache.
 */
class PreventCachingSignedInPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Asked after the request has run, so the page that signs somebody
        // out is covered as well as every page they saw while signed in.
        if ($request->user() !== null || $request->routeIs('auth.logout')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
