<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TokenAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Issuing and revoking API tokens.
 *
 * Deliberately mirrors AuthController's throttling: the same email+IP key and
 * the same limits, so a token endpoint cannot be used to grind passwords at a
 * rate the login form would have refused.
 */
class TokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            // Omit these and the token gets `*` — see TokenAbilities. Asking
            // only for names that do not exist is a mistake worth failing on
            // rather than quietly upgrading to full access.
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', Rule::in(TokenAbilities::all())],
        ]);

        $key = 'api-token|'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.
                    ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        $user = User::where('email', Str::lower($credentials['email']))->first();

        if ($user === null || $user->password === null
            || ! Auth::getProvider()->validateCredentials($user, $credentials)) {
            RateLimiter::hit($key, 900);

            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        /*
         * A second factor cannot be completed over a single request, and
         * quietly skipping it here would make the API a way around it. Until
         * there is a proper challenge/response step, these accounts keep using
         * the web session.
         */
        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'email' => 'This account uses two-factor authentication. Sign in on the web to issue a token.',
            ]);
        }

        RateLimiter::clear($key);

        $abilities = TokenAbilities::normalise($credentials['abilities'] ?? null);

        return response()->json([
            'token' => $user->createToken($credentials['device_name'], $abilities)->plainTextToken,
            'abilities' => $abilities,
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'current_company_id' => $user->current_company_id,
                // What this token may do, so a client can hide what it cannot
                // reach rather than discovering it through 403s.
                'abilities' => $user->currentAccessToken()?->abilities ?? [],
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
