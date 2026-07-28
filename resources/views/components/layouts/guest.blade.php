@props(['title' => null])

{{-- Minimal shell for pre-auth pages: no nav, no tenant, just a centred column. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · '.config('opes.brand.name') : config('opes.brand.name') }}</title>
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
    @livewireStyles
</head>

<body class="flex min-h-full items-center justify-center px-5 py-10">
    {{ $slot }}
    @livewireScripts
</body>
</html>
