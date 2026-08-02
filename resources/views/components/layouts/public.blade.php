@props([
    'title' => null,
    'description' => null,
    'robots' => null,
    // Layout of the page inside the shell:
    //   'column' — a centred, readable column: auth, forms, profiles, errors
    //   'page'   — full-bleed sections that manage their own width: marketing
    //   'bare'   — no chrome and almost no padding: iframe embeds
    'variant' => 'column',
    'width' => 'max-w-[480px]',
    'bodyClass' => '',
])

{{--
    The shared document shell for every page outside the authenticated app.

    It exists because there wasn't one. Twenty-four views each wrote their own
    <html> and <head>, and the copies had drifted: only seven set a background
    colour on <body>, so the other seventeen fell through to the browser default
    — white — while the text tokens had already flipped to their dark values.
    In dark mode that is white text on a white page, and it was live on the
    login screen, the public business profile and every marketing page.

    Everything a page can legitimately differ on is a prop. Everything else is
    fixed here on purpose, so it cannot drift again.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- No maximum-scale: these pages are read by people who did not choose
         this app and may need to zoom. viewport-fit=cover lets the safe-area
         insets below actually resolve on a notched phone. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title>{{ $title ? $title.' · '.config('opes.brand.name') : config('opes.brand.name') }}</title>
    <meta name="description" content="{{ $description ?? config('opes.brand.tagline') }}">
    @if ($robots)
        <meta name="robots" content="{{ $robots }}">
    @endif

    <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">

    {{-- Matches --color-canvas in each theme, so the iOS status bar and the
         Android address bar continue the page rather than framing it. --}}
    <meta name="theme-color" content="#eef2f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

    {{-- Runs before first paint so the correct theme is already on <html>.
         Anything slower produces a white flash on every load in dark mode. --}}
    @include('partials.theme-boot')

    {{-- app.js goes everywhere, not just where someone remembered it. Seven of
         these pages shipped Alpine directives with no Alpine on the page, so
         the markup was inert and nothing said so. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{ $head ?? '' }}
</head>

<body class="min-h-full bg-canvas antialiased {{ $bodyClass }}">

@if ($variant === 'page')
    {{ $slot }}
@elseif ($variant === 'bare')
    <main class="w-full px-1 py-2">
        {{ $slot }}
    </main>
@else
    {{-- The safe-area padding is what stops the last card sitting under the iOS
         home indicator, which is the single most common way a page that is fine
         in a desktop browser feels broken on a phone. --}}
    <main class="mx-auto flex w-full flex-col items-center px-5 pt-8"
          style="padding-bottom: calc(2.5rem + env(safe-area-inset-bottom))">
        <div class="w-full {{ $width }}">
            {{ $slot }}
        </div>
    </main>
@endif

@livewireScripts
</body>
</html>
