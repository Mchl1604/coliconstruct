<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
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
