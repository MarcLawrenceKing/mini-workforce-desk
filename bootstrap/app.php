<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
