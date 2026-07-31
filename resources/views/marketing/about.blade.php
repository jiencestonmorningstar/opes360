<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>About · {{ config('opes.brand.name') }}</title>

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
    <section class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
        <h1 class="text-[30px] font-bold tracking-[-0.02em] text-ink sm:text-[36px]">About {{ config('opes.brand.name') }}</h1>
        <p class="mt-4 text-[16px] leading-relaxed text-muted">
            {{ config('opes.brand.name') }} is built by <a href="{{ config('opes.brand.vendor_url') }}" class="font-semibold text-brand hover:underline">{{ config('opes.brand.vendor') }}</a>
            for small and growing businesses that need sales, invoicing and customer records to work
            from one place — including the moments the connection doesn't cooperate.
        </p>

        <div class="mt-10 space-y-8">
            <div class="card p-6">
                <h2 class="text-[17px] font-semibold text-ink">Why we built it</h2>
                <p class="mt-2 text-[14.5px] leading-relaxed text-muted">
                    Most business owners we spoke to were running invoicing out of a notebook, stock out
                    of memory, and customer records out of a phone's contact list. We wanted one suite
                    that replaced all three without asking for a fast, always-on connection to do it.
                </p>
            </div>

            <div class="card p-6">
                <h2 class="text-[17px] font-semibold text-ink">What we believe</h2>
                <ul class="mt-3 space-y-2.5 text-[14.5px] leading-relaxed text-muted">
                    <li class="flex gap-2.5"><x-icon name="check-circle" class="mt-0.5 size-[16px] shrink-0 text-brand" stroke-width="2" /> A quotation, invoice or receipt should be provably genuine — hence a QR on every one.</li>
                    <li class="flex gap-2.5"><x-icon name="check-circle" class="mt-0.5 size-[16px] shrink-0 text-brand" stroke-width="2" /> A patchy connection should never mean a lost sale — the app works offline and syncs later.</li>
                    <li class="flex gap-2.5"><x-icon name="check-circle" class="mt-0.5 size-[16px] shrink-0 text-brand" stroke-width="2" /> Pricing should be honest, in a currency the business actually uses.</li>
                </ul>
            </div>

            <div class="card p-6">
                <h2 class="text-[17px] font-semibold text-ink">Who's behind it</h2>
                <p class="mt-2 text-[14.5px] leading-relaxed text-muted">
                    {{ config('opes.brand.name') }} is a product of {{ config('opes.brand.vendor') }}.
                    You can reach us any time on the <a href="{{ route('marketing.contact') }}" class="font-semibold text-brand hover:underline">contact page</a>.
                </p>
            </div>
        </div>

        <div class="mt-10 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('register') }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl bg-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Create your business
            </a>
            <a href="{{ route('marketing.contact') }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40">
                Get in touch
            </a>
        </div>
    </section>
</main>

@include('marketing.partials.footer')

</body>
</html>
