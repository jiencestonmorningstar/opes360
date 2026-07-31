<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlatformAdmin;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for platform staff, on the 'admin' guard. Deliberately not the same
 * controller as the business AuthController — the two credential stores,
 * throttle keys and sessions never touch each other.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login|'.Str::lower($credentials['email']).'|'.$request->ip();

        // A per-(email+IP) key alone lets an attacker try five guesses
        // against unlimited *different* admin emails from one IP without
        // ever tripping it. This second key is IP-only and much looser
        // (30/15min vs 5/15min), so it only bites that enumeration pattern
        // and doesn't make normal typo-retries from a shared office IP
        // painful.
        $ipKey = 'admin-login-ip|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.
                    ceil(max(RateLimiter::availableIn($key), RateLimiter::availableIn($ipKey)) / 60).' minute(s).',
            ]);
        }

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($ipKey, 900);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Those credentials do not match our records.']);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($ipKey);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::broker('platform_admins')->sendResetLink($request->only('email'));

        // Same message either way: whether an address has an admin account
        // is not something an unauthenticated caller should be able to
        // enumerate.
        return back()->with('status', $status === Password::RESET_LINK_SENT
            ? 'If that address has an admin account, a reset link is on its way.'
            : 'If that address has an admin account, a reset link is on its way.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker('platform_admins')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (PlatformAdmin $admin, string $password) {
                $admin->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($admin));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('admin.login')->with('status', 'Password updated. Sign in with your new password.');
    }
}
