<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Two-factor verification · Platform admin · {{ config('opes.brand.name') }}</title>
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
        <h1 class="text-[19px] font-bold tracking-[-0.02em] text-ink">Two-factor verification</h1>
        <p class="mt-1 text-[14px] text-muted">
            Enter the 6-digit code from your authenticator app, or one of your recovery codes.
        </p>

        @if ($errors->any())
            <div class="mt-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.two-factor.verify') }}" class="mt-6 space-y-4">
            @csrf
            <label class="block">
                <span class="mb-1.5 block text-[13.5px] font-semibold text-ink-2">Code</span>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       required autofocus placeholder="000000"
                       class="tnum h-12 w-full rounded-xl border border-border bg-surface px-4 text-center text-[18px] tracking-[0.3em] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
            </label>

            <button type="submit"
                    class="tap focusable mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-ink text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Verify
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-[13px] text-faint">
        <a href="{{ route('admin.login') }}" class="hover:text-ink-2">Back to sign in</a>
    </p>
</div>
</body>
</html>
