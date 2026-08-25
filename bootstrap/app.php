<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureTermsAreAccepted;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway terminates TLS at its edge and forwards plain HTTP with the
        // original scheme in X-Forwarded-Proto. Without trusting that proxy the
        // URL generator writes http:// form actions onto an https:// page, which
        // the browser refuses to submit.
        $middleware->trustProxies(at: '*');

        // A deactivated or archived account is turned away on every route it
        // could still be holding a session on, not only on the ones that ask
        // about a role. Appended to the whole web group because the routes
        // that were missing the check - My Projects, the client's sign-off,
        // Profile, Notifications - are exactly the ones with no role on them.
        // It is a no-op for guests.
        //
        // Terms acceptance is appended beside it for the same reason: a client
        // who has not agreed to the current Terms and Conditions must be held
        // out of their portal on every route that could still let them in, not
        // only on the ones somebody remembered to decorate. It runs after the
        // active-account check so a disabled account is turned away before it
        // is asked to agree to anything, and it is a no-op for guests and for
        // every non-client role - see EnsureTermsAreAccepted.
        $middleware->web(append: [EnsureAccountIsActive::class, EnsureTermsAreAccepted::class]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);

        // Guests are sent to the sign-in page rather than a named `login`
        // route, which this application does not have.
        $middleware->redirectGuestsTo(fn () => route('auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // An upload past PHP's `post_max_size` never reaches validation: PHP
        // discards the whole body first, so there are no fields to name and
        // no file to point at. Without this the person gets a bare 413 page
        // and loses everything they typed; with it they are sent back to the
        // form with an explanation and something they can act on.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $limit = ini_get('post_max_size') ?: 'the server limit';

            $message = sprintf(
                'Those files are too large to upload together (the total must stay under %s). '
                    .'Try uploading smaller files, or compress them and submit again.',
                $limit
            );

            if ($request->expectsJson()) {
                return response()->json(['error' => $message], 413);
            }

            return back()->withInput($request->except(['_token', 'password']))->with('error', $message);
        });
    })->create();
