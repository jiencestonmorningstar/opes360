{{-- bottomNav=false hides the mobile tab bar on focused flows (forms with their
     own fixed action bar), where the two would otherwise stack and collide. --}}
@props(['title' => null, 'active' => 'home', 'bottomNav' => true])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=5">
    <title>{{ $title ? $title.' · '.config('opes.brand.name') : config('opes.brand.name') }}</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($companyId = app(\App\Support\CurrentCompany::class)->id())
        {{-- Names the tenant whose IndexedDB database this session uses. --}}
        <meta name="opes-company" content="{{ $companyId }}">
    @endif
    <meta name="description" content="{{ config('opes.brand.tagline') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('opes.brand.name') }}">
    <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">

    {{-- Theme colour must track the resolved theme or the iOS status bar fights the header. --}}
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

    {{--
        Runs before first paint so the correct theme is already on <html>.
        Anything slower produces a white flash on every load in dark mode.
    --}}
    <script @cspNonce>
        (function () {
            try {
                var stored = localStorage.getItem('opes-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                var dark = stored === 'dark' || (stored !== 'light' && system);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {
                /* Private mode with storage disabled — fall back to the light theme. */
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="h-full bg-surface antialiased dark:bg-canvas lg:bg-canvas">
<div x-data="opesShell()" class="min-h-full lg:flex">

    {{-- Desktop sidebar / mobile drawer --}}
    @include('partials.sidebar', ['active' => $active])

    {{-- Scrim behind the mobile drawer --}}
    <div x-cloak x-show="drawer" x-transition.opacity @click="drawer = false"
         class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" aria-hidden="true"></div>

    <div class="flex min-w-0 flex-1 flex-col">
        @include('partials.offline-banner')
        @include('partials.topbar')

        {{-- Bottom padding clears the fixed bottom bar and the iOS home indicator;
             from `lg` the bottom bar is gone and the inset collapses to normal padding. --}}
        <main class="flex-1 {{ $bottomNav ? 'pb-[calc(6rem+env(safe-area-inset-bottom))]' : 'pb-6' }} lg:pb-6 lg:pr-6">
            <div class="lg:card lg:min-h-[calc(100vh-7.5rem)]">
                {{ $slot }}
            </div>
        </main>
    </div>

    @if ($bottomNav)
        @include('partials.bottom-nav', ['active' => $active])
    @endif
</div>

@livewireScripts
</body>
</html>
