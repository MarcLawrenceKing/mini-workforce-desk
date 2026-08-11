<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration is a one-time bootstrap step: it exists only to create the very
 * first administrator on a fresh install. Once an admin exists the route closes
 * for good and every further account is created from /users by a signed-in user.
 */
class EnsureNoAdminExists
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::adminExists()) {
            if ($request->expectsJson()) {
                abort(403, 'Registration is closed.');
            }

            return redirect()->route('login')->with(
                'error',
                'Registration is closed — an administrator account already exists.',
            );
        }

        return $next($request);
    }
}
