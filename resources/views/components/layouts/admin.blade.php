@props(['title' => null, 'active' => 'dashboard'])

{{--
    Same sidebar/topbar shell as the business app (partials.sidebar/topbar +
    the opesShell() Alpine component), scaled down to what a two-page staff
    tool needs — no company switcher, no notifications bell, no bottom tab
    bar. Kept visually related rather than identical: the ADMIN badge and a
    slate accent instead of brand blue say "you are not inside a business"
    at a glance, which matters given platform admins can browse any
    company's real data.
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · Platform Admin' : 'Platform Admin' }} · {{ config('opes.brand.name') }}</title>
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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="h-full bg-surface antialiased dark:bg-canvas lg:bg-canvas">
<div x-data="opesShell()" class="min-h-full lg:flex">

    @include('admin.partials.sidebar', ['active' => $active])

    <div x-cloak x-show="drawer" x-transition.opacity @click="drawer = false"
         class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" aria-hidden="true"></div>

    <div class="flex min-w-0 flex-1 flex-col">
        @include('admin.partials.topbar')

        <main class="flex-1 pb-6 lg:pr-6">
            <div class="lg:card lg:min-h-[calc(100vh-7.5rem)]">
                {{ $slot }}
            </div>
        </main>
    </div>
</div>

@livewireScripts
</body>
</html>
