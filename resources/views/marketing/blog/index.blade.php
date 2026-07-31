<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Blog · {{ config('opes.brand.name') }}</title>
    <meta name="description" content="Notes on offline-first commerce, QR verification, loyalty and running a business on {{ config('opes.brand.name') }}.">

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
</head>

<body class="min-h-full">

@include('marketing.partials.nav')

<main>
    <section class="mx-auto max-w-3xl px-5 pb-4 pt-16 text-center sm:pt-20">
        <h1 class="text-[30px] font-bold tracking-[-0.02em] text-ink sm:text-[36px]">Blog</h1>
        <p class="mt-4 text-[15.5px] leading-relaxed text-muted">
            Notes on offline-first commerce, verification, loyalty, and running a business on {{ config('opes.brand.name') }}.
        </p>
    </section>

    <section class="mx-auto max-w-3xl px-5 py-12 sm:py-16">
        <div class="space-y-5">
            @foreach ($posts as $slug => $post)
                <a href="{{ route('marketing.blog.show', $slug) }}"
                   class="card focusable block p-6 transition-colors hover:border-brand/40">
                    <p class="text-[12.5px] font-medium text-faint">
                        {{ \Illuminate\Support\Carbon::parse($post['published_at'])->format('F j, Y') }}
                        · {{ $post['read_minutes'] }} min read
                    </p>
                    <h2 class="mt-1.5 text-[19px] font-bold tracking-[-0.01em] text-ink">{{ $post['title'] }}</h2>
                    <p class="mt-2 text-[14.5px] leading-relaxed text-muted">{{ $post['excerpt'] }}</p>
                    <span class="mt-3 inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand">
                        Read more
                        <x-icon name="chevron-right" class="size-[15px]" stroke-width="2.2" />
                    </span>
                </a>
            @endforeach
        </div>
    </section>
</main>

@include('marketing.partials.footer')

</body>
</html>
