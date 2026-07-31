<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $post['title'] }} · {{ config('opes.brand.name') }} Blog</title>
    <meta name="description" content="{{ $post['excerpt'] }}">

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

    <style>
        /* The post's own body typography — the rest of the page uses utility
           classes, but prose generated from markdown-lite needs real spacing
           between headings and paragraphs. */
        .prose h2 { margin-top: 1.75em; margin-bottom: 0.5em; font-size: 19px; font-weight: 700; letter-spacing: -0.01em; }
        .prose h2:first-child { margin-top: 0; }
        .prose p { margin-bottom: 1em; line-height: 1.75; }
    </style>
</head>

<body class="min-h-full">

@include('marketing.partials.nav')

<main class="mx-auto max-w-2xl px-5 py-16 sm:py-20">
    <a href="{{ route('marketing.blog') }}" class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        All posts
    </a>

    <p class="mt-6 text-[12.5px] font-medium text-faint">
        {{ \Illuminate\Support\Carbon::parse($post['published_at'])->format('F j, Y') }}
        · {{ $post['read_minutes'] }} min read
    </p>
    <h1 class="mt-2 text-[28px] font-bold leading-tight tracking-[-0.02em] text-ink sm:text-[34px]">{{ $post['title'] }}</h1>

    <div class="prose mt-8 text-[15.5px] text-ink-2">
        {!! $bodyHtml !!}
    </div>

    <div class="mt-12 border-t border-border pt-8 text-center">
        <p class="text-[15px] font-semibold text-ink">See it running with your own login.</p>
        <a href="{{ route('demo.request') }}"
           class="tap focusable mt-4 inline-flex h-11 items-center justify-center rounded-xl bg-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
            Try a demo
        </a>
    </div>
</main>

@include('marketing.partials.footer')

</body>
</html>
