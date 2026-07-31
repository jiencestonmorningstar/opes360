<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Check your email · {{ config('opes.brand.name') }}</title>

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
<div class="w-full max-w-[440px]">
    <div class="card flex flex-col items-center p-8 text-center">
        <span class="flex size-[74px] items-center justify-center rounded-full bg-tint-green">
            <x-icon name="check-circle" class="size-9 text-positive" stroke-width="1.8" />
        </span>
        <h1 class="mt-4 text-[23px] font-bold tracking-[-0.02em] text-ink">Check your email</h1>
        <p class="mt-1.5 text-[14px] leading-snug text-muted">
            Your demo account is ready. We've sent the login details
            @if ($email)to <strong class="font-semibold text-ink-2">{{ $email }}</strong>@endif.
        </p>
        <a href="{{ route('login') }}"
           class="tap focusable mt-6 flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
            Go to sign in
        </a>
    </div>
</div>
</body>
</html>
