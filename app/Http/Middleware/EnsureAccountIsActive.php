<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kills the session of a user that was disabled *after* they logged in.
 * LoginRequest blocks them at the door; this blocks them on every request after.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_disabled) {
            // A token client (Postman, a mobile app) has no session to tear down,
            // and asking for one would throw. Only browser requests get logged out.
            if ($request->hasSession()) {
                Auth::guard('web')->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                abort(403, 'Your account has been disabled.');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been disabled. Please contact your administrator.',
            ]);
        }

        return $next($request);
    }
}
