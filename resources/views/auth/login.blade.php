<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sign in · {{ config('opes.brand.name') }}</title>
    <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">

    <script>
        (function () {
            try {
                var stored = localStorage.getItem('opes-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && system));
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="flex h-full items-center justify-center px-5 py-10">
<div class="w-full max-w-[400px]">

    <div class="text-center">
        <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </div>
        <p class="mt-2 text-[14px] text-muted">{{ config('opes.brand.tagline') }}</p>
    </div>

    <div class="card mt-7 p-6">
        <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">Welcome back</h1>
        <p class="mt-1 text-[14px] text-muted">Sign in to continue to your business.</p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-[13.5px] font-semibold text-ink-2">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="email"
                       value="{{ old('email') }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                       placeholder="you@business.com">
            </div>

            <div>
                <label for="password" class="block text-[13.5px] font-semibold text-ink-2">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                       placeholder="••••••••">
            </div>

            <div class="flex items-center justify-between gap-3 pt-1">
                <label class="flex items-center gap-2.5 text-[14px] text-ink-2">
                    <input type="checkbox" name="remember" value="1"
                           class="size-[18px] rounded border-border-strong text-brand focus:ring-brand/30">
                    Keep me signed in
                </label>
                <a href="{{ route('password.request') }}" class="text-[13.5px] font-semibold text-brand hover:underline">
                    Forgot password?
                </a>
            </div>

            <button type="submit"
                    class="tap focusable mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Sign in
            </button>
        </form>

        <p class="mt-5 text-center text-[13.5px] text-muted">
            New to {{ config('opes.brand.name') }}?
            <a href="{{ route('register') }}" class="font-semibold text-brand hover:underline">Create your business</a>
        </p>
    </div>

    <p class="mt-6 text-center text-[12.5px] text-muted">
        Built by <a href="{{ config('opes.brand.vendor_url') }}" class="font-medium text-brand hover:underline">{{ config('opes.brand.vendor') }}</a>
    </p>
</div>
</body>
</html>
