<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        // Task 9: every route in here is prefixed with /api and named api.*
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Task 9: lets our own Vue frontend authenticate against /api/* with the
        // session cookie it already has, instead of carrying a bearer token in
        // JavaScript. Third-party clients (Postman) still use a token.
        $middleware->statefulApi();

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureAccountIsActive::class,
            'setup' => \App\Http\Middleware\EnsureNoAdminExists::class,
        ]);

        // Task 3: guests bounced off private routes, auth users bounced off /login.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('my-account'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*'),
        );

        // Task 4: an unauthorized role gets a hard 403 for JSON/API clients, and a
        // redirect to /my-account with a flash message for browser page visits.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 403) {
                return $response;
            }

            if (! $request->user() || $request->expectsJson() || $request->is('api/*')) {
                return $response;
            }

            return redirect()
                ->route('my-account')
                ->with('error', 'You do not have permission to open that page.');
        });
    })->create();
