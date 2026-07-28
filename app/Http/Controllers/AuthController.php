<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\QrCodes;
use App\Services\TwoFactor;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Sign-in. Credentials are checked first; a user with two-factor enabled is
     * then parked in the session and must clear the challenge before the login
     * is actually established — the session is never authenticated in between.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttle per email+IP: neither alone is enough. Per-IP only would let
        // one attacker spray many accounts; per-email only would let a botnet
        // grind a single account from many addresses.
        $key = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.
                    ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        $user = User::where('email', Str::lower($credentials['email']))->first();

        if ($user === null || ! Auth::getProvider()->validateCredentials($user, $credentials)) {
            RateLimiter::hit($key, 900);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Those credentials do not match our records.']);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('2fa.id', $user->id);
            $request->session()->put('2fa.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function showChallenge(Request $request)
    {
        abort_unless($request->session()->has('2fa.id'), 403);

        return view('auth.two-factor-challenge');
    }

    public function challenge(Request $request, TwoFactor $twoFactor)
    {
        $userId = $request->session()->get('2fa.id');
        abort_unless($userId, 403);

        $request->validate(['code' => ['required', 'string']]);

        $user = User::findOrFail($userId);
        $code = trim($request->string('code')->toString());

        $key = 'two-factor|'.$userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Request a fresh code and try again shortly.',
            ]);
        }

        // A recovery code is accepted in place of a TOTP, and is consumed so it
        // works exactly once.
        $passed = $twoFactor->verify($user->twoFactorSecret() ?? '', $code)
            || $twoFactor->consumeRecoveryCode($user, $code);

        if (! $passed) {
            RateLimiter::hit($key, 900);

            return back()->withErrors(['code' => 'That code is not valid.']);
        }

        RateLimiter::clear($key);

        Auth::login($user, (bool) $request->session()->pull('2fa.remember', false));
        $request->session()->forget('2fa.id');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        // The same message either way: whether an address has an account is not
        // something an unauthenticated caller should be able to enumerate.
        return back()->with('status', $status === Password::RESET_LINK_SENT
            ? 'If that address has an account, a reset link is on its way.'
            : 'If that address has an account, a reset link is on its way.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Password updated. Sign in with your new password.');
    }

    /**
     * Renders the enrolment QR for the signed-in user's own pending secret.
     *
     * The URI is rebuilt server-side from that user's stored secret rather than
     * accepted from the request, so this endpoint cannot be used to render an
     * arbitrary provisioning URI or to fish another account's secret.
     */
    public function twoFactorQr(Request $request, TwoFactor $twoFactor, QrCodes $qr)
    {
        $user = $request->user();
        $secret = $user->twoFactorSecret();

        abort_if($secret === null, 404);

        return response($qr->svg($twoFactor->provisioningUri($user, $secret), 340), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = User::findOrFail($id);

        // Constant-time comparison of the signed hash, as Laravel's own
        // verification middleware does.
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->route('dashboard')->with('status', 'Email verified.');
    }
}
