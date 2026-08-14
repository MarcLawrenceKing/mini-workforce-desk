<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Token issuing for non-browser clients (Postman, Insomnia, curl, a future
 * mobile app).
 *
 * The Vue UI does NOT use any of this: it is already logged in through
 * /login and authenticates against /api/* with its session cookie, so no token
 * is ever exposed to JavaScript. See TASK-9-GUIDE.md §0.5.
 */
class AuthController extends Controller
{
    /**
     * Exchange credentials for a personal access token.
     *
     * Bad credentials come back as a 422 with an `errors` object, the same shape
     * every other validation failure uses, so a client needs one error handler.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Names the token so it can be revoked per-device later.
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        // Same rule as the web login: a disabled account never gets a credential.
        if ($user->is_disabled) {
            throw ValidationException::withMessages([
                'email' => 'Your account has been disabled. Please contact your administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        return response()->json([
            // Shown exactly once — only a hash is stored. Lose it and you mint a new one.
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'user' => $this->profile($user),
        ]);
    }

    /** Who does this token belong to, and what may it do? */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    /**
     * Revoke only the token that made this call, so signing out of Postman
     * doesn't sign you out of every other device.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'company_id' => $user->company_id,
            'roles' => $user->roles->pluck('name')->values(),
            'permissions' => $user->allPermissions()->pluck('name')->unique()->values(),
        ];
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return 'api-login|' . Str::transliterate(Str::lower($request->string('email')) . '|' . $request->ip());
    }
}
