<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Platform admin · {{ config('opes.brand.name') }}</title>
    <meta name="robots" content="noindex">

    <script @cspNonce>
        (function () {
            try {
                var stored = localStorage.getItem('opes-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && system));
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css'])
</head>

<body class="flex min-h-full items-center justify-center bg-surface-2 px-5 py-10 dark:bg-canvas">
<div class="w-full max-w-[380px]">

    <div class="text-center">
        <span class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-ink shadow-[var(--shadow-raised)]">
            <x-icon name="cog" class="size-[24px] text-white" stroke-width="1.7" />
        </span>
        <div class="mt-4 text-[26px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </div>
        <p class="mt-2 text-[13px] font-semibold uppercase tracking-wide text-faint">Platform Admin</p>
    </div>

    <div class="card mt-7 p-6">
        @if ($errors->any())
            <div class="mb-5 flex items-center gap-2.5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                <x-icon name="alert" class="size-[18px] shrink-0" stroke-width="2" />
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-[13.5px] font-semibold text-ink-2">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username" value="{{ old('email') }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
            </div>

            <div>
                <label for="password" class="block text-[13.5px] font-semibold text-ink-2">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
            </div>

            <button type="submit"
                    class="tap focusable mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-ink text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Sign in
            </button>
        </form>
    </div>

    @if (config('opes.demo.enabled'))
        {{-- Same one-tap pattern as the business login page: a plain POST of the
             seeded admin credential through the real login flow, not a shortcut
             around it — same throttle, same session handling. --}}
        <div class="card mt-4 p-5">
            <div class="flex items-center gap-2">
                <span class="flex size-[30px] items-center justify-center rounded-lg bg-tint-purple">
                    <x-icon name="spark" class="size-[17px] text-accent-purple" stroke-width="1.9" />
                </span>
                <div>
                    <p class="text-[14.5px] font-semibold text-ink">Just want a look inside?</p>
                    <p class="text-[12.5px] text-muted">Sign in to the demo admin account with one tap.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.login.attempt') }}" class="mt-4">
                @csrf
                <input type="hidden" name="email" value="{{ config('opes.demo.admin_email') }}">
                <input type="hidden" name="password" value="{{ config('opes.demo.password') }}">
                <button type="submit"
                        class="tap focusable flex h-auto w-full flex-col items-start gap-0.5 rounded-xl border border-border bg-surface px-4 py-3 text-left transition-colors hover:border-brand/40 hover:bg-surface-2">
                    <span class="text-[14px] font-semibold text-ink">Demo platform admin</span>
                    <span class="text-[12px] leading-snug text-muted">Full read access across every demo business</span>
                </button>
            </form>
        </div>
    @endif

    <p class="mt-6 text-center text-[13px] text-faint">
        <a href="{{ route('login') }}" class="hover:text-ink-2">Not staff? Business sign-in</a>
    </p>
</div>
</body>
</html>
