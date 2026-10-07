<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Where a signed-in reader goes when a page fails: back to their own portal,
 * with a toast saying what happened, rather than onto an error page.
 *
 * The error pages in resources/views/errors are the last resort. They are
 * still what a guest sees, what a fetch gets (as JSON), what maintenance mode
 * shows, and what a status this does not handle shows. They are also what a
 * failure shows when the portal itself is what failed: a redirect that lands
 * on another failure is a loop, so the request straight after one of these
 * redirects always gets the page instead.
 *
 * Like ErrorRecovery, nothing here may throw. Whatever cannot be worked out
 * falls back to the error page by returning null.
 */
class PortalErrorRedirect
{
    /**
     * The toast for each status this handles. Anything else keeps its page.
     *
     * @var array<int, string>
     */
    private const MESSAGES = [
        403 => 'You do not have access to that page.',
        404 => 'That page could not be found.',
        419 => 'Your session expired. Please try again.',
        500 => 'Something went wrong. Please try again.',
    ];

    /**
     * Flashed with every redirect this makes, so the request it lands on can
     * tell it is already the way out of a failure.
     */
    public const MARKER = 'portal_error_redirected';

    /**
     * The redirect for this failure, or null to render the error page.
     */
    public static function respond(Throwable $exception, Request $request): ?RedirectResponse
    {
        try {
            return self::redirectFor($exception, $request);
        } catch (Throwable) {
            return null;
        }
    }

    private static function redirectFor(Throwable $exception, Request $request): ?RedirectResponse
    {
        // These carry their own response - a sign-in redirect, a form sent
        // back with its errors - and are not failures to recover from.
        if ($exception instanceof AuthenticationException
            || $exception instanceof ValidationException
            || $exception instanceof HttpResponseException) {
            return null;
        }

        // A fetch expects an answer, not a page to follow.
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // Only somebody navigating to a page is sent anywhere. A picture, a
        // frame or a script that fails keeps its status: redirecting it would
        // draw a whole portal nobody sees and leave its toast waiting on the
        // next page they open.
        if (! self::isPageNavigation($request)) {
            return null;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        if (! isset(self::MESSAGES[$status])) {
            return null;
        }

        // A developer needs the stack trace, not a toast.
        if ($status === 500 && config('app.debug')) {
            return null;
        }

        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return null;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        // The way out failed too: show the page rather than go round again.
        if ($request->session()->get(self::MARKER)) {
            return null;
        }

        // A role with no portal of its own has nowhere to be sent.
        if (PortalHome::routeName($user) === 'auth.login') {
            return null;
        }

        $message = self::MESSAGES[$status];

        // A failed form goes back to the form with what was typed, the way
        // an expired session or a validation error already does.
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $previous = self::previousUrl($request);

            if ($previous !== null) {
                return redirect()->to($previous)
                    ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                    ->with('error', $message)
                    ->with(self::MARKER, true);
            }
        }

        $home = PortalHome::url($user);

        if (rtrim($request->url(), '/') === rtrim($home, '/')) {
            return null;
        }

        return redirect()->to($home)
            ->with('error', $message)
            ->with(self::MARKER, true);
    }

    /**
     * Whether the browser is loading this as the page itself. Browsers name
     * the destination outright; where one does not, a page load is the
     * request that asks for HTML.
     */
    private static function isPageNavigation(Request $request): bool
    {
        $destination = $request->headers->get('Sec-Fetch-Dest');

        if (filled($destination)) {
            return $destination === 'document';
        }

        return str_contains((string) $request->headers->get('Accept'), 'text/html');
    }

    /**
     * The page the failed form was sent from, when it is one of this site's
     * own pages and not the address that just failed.
     */
    private static function previousUrl(Request $request): ?string
    {
        $previous = $request->headers->get('referer') ?: $request->session()->previousUrl();

        if (! is_string($previous) || $previous === '') {
            return null;
        }

        if (! str_starts_with($previous, $request->root().'/') && $previous !== $request->root()) {
            return null;
        }

        return rtrim($previous, '/') === rtrim($request->url(), '/') ? null : $previous;
    }
}
