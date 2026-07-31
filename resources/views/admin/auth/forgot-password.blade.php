<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Reset password · Platform admin · {{ config('opes.brand.name') }}</title>
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
        <h1 class="text-[19px] font-bold tracking-[-0.02em] text-ink">Reset your password</h1>
        <p class="mt-1 text-[14px] text-muted">We'll email you a link to choose a new one.</p>

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

        <form method="POST" action="{{ route('admin.password.email') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-[13.5px] font-semibold text-ink-2">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username" value="{{ old('email') }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
            </div>

            <button type="submit"
                    class="tap focusable mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-ink text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Send reset link
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-[13px] text-faint">
        <a href="{{ route('admin.login') }}" class="hover:text-ink-2">Back to sign in</a>
    </p>
</div>
</body>
</html>
