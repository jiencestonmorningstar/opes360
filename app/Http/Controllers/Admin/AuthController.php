<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
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

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.
                    ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 900);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Those credentials do not match our records.']);
        }

        RateLimiter::clear($key);
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
}
