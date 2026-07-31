<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ config('opes.brand.name') }} — {{ config('opes.brand.tagline') }}</title>
    <meta name="description" content="{{ config('opes.brand.name') }} is a business identity and operations suite: sales, invoicing, customers, documents and QR verification in one place.">
    <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">

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
    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-5 pb-16 pt-16 sm:pb-24 sm:pt-24">
        <div class="mx-auto max-w-3xl text-center">
            <h1 class="text-[34px] font-bold leading-[1.1] tracking-[-0.02em] text-ink sm:text-[46px]">
                Run your business on
                <span class="text-brand">{{ config('opes.brand.name') }}</span>
            </h1>
            <p class="mx-auto mt-5 max-w-xl text-[16px] leading-relaxed text-muted sm:text-[17px]">
                Invoices, customers, inventory and verified business documents — in one suite that
                keeps working when the connection doesn't.
            </p>

            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('demo.request') }}"
                   class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                    Try a demo
                </a>
                <a href="{{ route('marketing.pricing') }}"
                   class="tap focusable flex h-12 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
                    See pricing
                </a>
            </div>
            <p class="mt-4 text-[13px] text-faint">No card required · Basic plan from {{ \App\Support\Money::format(3000, 'XAF', false) }}/month</p>
        </div>
    </section>

    {{-- Feature highlights --}}
    <section class="border-t border-border bg-surface-2/40 py-16 sm:py-20">
        <div class="mx-auto max-w-6xl px-5">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-[26px] font-bold tracking-[-0.02em] text-ink sm:text-[30px]">Everything the business needs, nothing it doesn't</h2>
                <p class="mt-3 text-[15px] text-muted">A focused set of modules that cover the whole day, from a quotation to a paid receipt.</p>
            </div>

            <div class="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'document-plus', 'title' => 'Sales & Invoicing', 'body' => 'Quotations, invoices and receipts, numbered and issued in a few taps.'],
                    ['icon' => 'users', 'title' => 'Customers & CRM', 'body' => 'A full history and balance for every customer, with statements ready to share.'],
                    ['icon' => 'cube', 'title' => 'Inventory', 'body' => 'Products and stock levels that update themselves as sales go out the door.'],
                    ['icon' => 'document', 'title' => 'Business Documents', 'body' => 'Contracts, letters and certificates generated from ready-made templates.'],
                    ['icon' => 'qr-code', 'title' => 'QR Verification', 'body' => 'Every invoice and document carries a QR that proves it is genuine, offline or on.'],
                    ['icon' => 'briefcase', 'title' => 'Business Card & Letterhead', 'body' => 'Branded stationery generated from your business profile, ready to print.'],
                ] as $feature)
                    <div class="card p-5">
                        <span class="flex size-10 items-center justify-center rounded-lg bg-tint-blue">
                            <x-icon :name="$feature['icon']" class="size-[19px] text-brand" stroke-width="1.9" />
                        </span>
                        <h3 class="mt-3.5 text-[15.5px] font-semibold text-ink">{{ $feature['title'] }}</h3>
                        <p class="mt-1 text-[13.5px] leading-relaxed text-muted">{{ $feature['body'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-8 text-center">
                <a href="{{ route('marketing.features') }}" class="text-[14.5px] font-semibold text-brand hover:underline">See every module →</a>
            </div>
        </div>
    </section>

    {{-- Pricing teaser --}}
    <section class="py-16 sm:py-20">
        <div class="mx-auto max-w-6xl px-5">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-[26px] font-bold tracking-[-0.02em] text-ink sm:text-[30px]">Simple, honest pricing</h2>
                <p class="mt-3 text-[15px] text-muted">Start on the Basic plan for {{ \App\Support\Money::format(3000, 'XAF', false) }} a month. Grow into a bigger plan whenever the business does.</p>
            </div>

            <div class="mt-8 text-center">
                <a href="{{ route('marketing.pricing') }}"
                   class="tap focusable inline-flex h-12 items-center justify-center rounded-xl bg-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    See full pricing
                </a>
            </div>
        </div>
    </section>

    {{-- Social proof placeholder --}}
    <section class="border-t border-border bg-surface-2/40 py-16 sm:py-20">
        <div class="mx-auto max-w-3xl px-5 text-center">
            <span class="flex size-11 items-center justify-center rounded-full bg-tint-purple mx-auto">
                <x-icon name="spark" class="size-[20px] text-accent-purple" stroke-width="1.9" />
            </span>
            <p class="mt-5 text-[18px] font-medium leading-relaxed text-ink sm:text-[20px]">
                &ldquo;{{ config('opes.brand.name') }} is built for businesses that live on WhatsApp and airtime, not
                a permanent internet connection — every screen still works when the signal doesn't.&rdquo;
            </p>
            <p class="mt-4 text-[13.5px] font-semibold text-muted">— {{ config('opes.brand.vendor') }}</p>
        </div>
    </section>

    {{-- Final CTA --}}
    <section class="py-16 sm:py-20">
        <div class="mx-auto max-w-2xl px-5 text-center">
            <h2 class="text-[26px] font-bold tracking-[-0.02em] text-ink sm:text-[30px]">Ready to get started?</h2>
            <p class="mt-3 text-[15px] text-muted">Set up your business in a few minutes — no card required.</p>
            <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('demo.request') }}"
                   class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                    Try a demo
                </a>
                <a href="{{ route('marketing.contact') }}"
                   class="tap focusable flex h-12 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
                    Talk to us first
                </a>
            </div>
        </div>
    </section>
</main>

@include('marketing.partials.footer')

</body>
</html>
