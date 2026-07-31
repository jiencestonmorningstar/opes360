<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Account suspended · {{ config('opes.brand.name') }}</title>

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

<body class="flex h-full items-center justify-center px-5 py-10">
<div class="w-full max-w-[420px] text-center">
    <span class="mx-auto flex size-[70px] items-center justify-center rounded-full bg-tint-orange">
        <x-icon name="alert" class="size-8 text-warning" stroke-width="1.8" />
    </span>

    <h1 class="mt-5 text-[21px] font-bold tracking-[-0.02em] text-ink">This business account is suspended</h1>
    <p class="mt-2 text-[14px] leading-relaxed text-muted">
        Sign-in works, but the business itself is temporarily locked. Contact
        {{ config('opes.contact.support_email') }} if you believe this is a mistake.
    </p>

    <form method="POST" action="{{ route('logout') }}" class="mt-7">
        @csrf
        <button type="submit"
                class="tap focusable flex h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[14.5px] font-semibold text-ink transition-colors hover:border-brand/40">
            Sign out
        </button>
    </form>
</div>
</body>
</html>
