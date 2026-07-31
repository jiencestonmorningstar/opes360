<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Pricing · {{ config('opes.brand.name') }}</title>

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

@php
    // Every annual price is ten times the monthly price — twelve months for the
    // price of ten, a flat ~17% discount that is the same across every tier.
    $tiers = [
        [
            'name' => 'Basic',
            'monthly' => 3000,
            'blurb' => 'For a single owner-operator getting started.',
            'features' => [
                'Up to 1 user',
                'Sales, invoicing & receipts',
                'Customers & inventory',
                'Offline mode',
                'QR verification',
            ],
            'highlight' => false,
        ],
        [
            'name' => 'Growth',
            'monthly' => 9000,
            'blurb' => 'For a small team that has outgrown one person.',
            'features' => [
                'Up to 5 team members',
                'Everything in Basic',
                'Business documents & letters',
                'Statement of account',
                'Custom letterhead & business card',
            ],
            'highlight' => true,
        ],
        [
            'name' => 'Business',
            'monthly' => 21000,
            'blurb' => 'For an established business running at scale.',
            'features' => [
                'Unlimited team members',
                'Everything in Growth',
                'Priority support',
                'Custom branding',
                'Dedicated onboarding',
            ],
            'highlight' => false,
        ],
    ];
@endphp

<main>
    <section class="mx-auto max-w-3xl px-5 pb-4 pt-16 text-center sm:pt-20">
        <h1 class="text-[30px] font-bold tracking-[-0.02em] text-ink sm:text-[36px]">Simple, honest pricing</h1>
        <p class="mt-4 text-[15.5px] leading-relaxed text-muted">
            Three plans, billed monthly or annually. Annual billing is twelve months for the price of ten
            on every plan — about 17% off.
        </p>
    </section>

    <section class="mx-auto max-w-6xl px-5 py-12 sm:py-16">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            @foreach ($tiers as $tier)
                <div class="card relative flex flex-col p-6 {{ $tier['highlight'] ? 'border-brand ring-1 ring-brand' : '' }}">
                    @if ($tier['highlight'])
                        <span class="absolute -top-3 left-6 rounded-full bg-brand px-3 py-1 text-[11.5px] font-semibold text-white">Most popular</span>
                    @endif

                    <h2 class="text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $tier['name'] }}</h2>
                    <p class="mt-1.5 text-[13.5px] text-muted">{{ $tier['blurb'] }}</p>

                    <div class="mt-5">
                        <span class="text-[32px] font-bold tracking-[-0.02em] text-ink">{{ \App\Support\Money::format($tier['monthly'], 'XAF', false) }}</span>
                        <span class="text-[14px] text-muted">/month</span>
                    </div>
                    <p class="mt-1 text-[13px] text-faint">
                        or {{ \App\Support\Money::format($tier['monthly'] * 10, 'XAF', false) }}/year
                    </p>

                    <ul class="mt-6 flex-1 space-y-2.5 text-[14px] text-ink-2">
                        @foreach ($tier['features'] as $feature)
                            <li class="flex gap-2.5">
                                <x-icon name="check-circle" class="mt-0.5 size-[16px] shrink-0 text-brand" stroke-width="2" />
                                {{ $feature }}
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('demo.request') }}"
                       class="tap focusable mt-7 flex h-12 w-full items-center justify-center rounded-xl text-[15px] font-semibold transition-opacity hover:opacity-90 {{ $tier['highlight'] ? 'bg-brand text-white' : 'border border-border bg-surface text-ink' }}">
                        Get started
                    </a>
                </div>
            @endforeach
        </div>

        <p class="mt-8 text-center text-[13px] text-faint">
            Prices shown in {{ \App\Support\Money::symbol('XAF') }} (Central African CFA franc). No card required to get started.
        </p>
    </section>

    <section class="border-t border-border py-16 text-center sm:py-20">
        <h2 class="text-[24px] font-bold tracking-[-0.02em] text-ink sm:text-[28px]">Questions before you start?</h2>
        <p class="mx-auto mt-3 max-w-md text-[14.5px] text-muted">We're happy to talk you through which plan fits.</p>
        <div class="mt-7">
            <a href="{{ route('marketing.contact') }}"
               class="tap focusable inline-flex h-12 items-center justify-center rounded-xl bg-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Contact us
            </a>
        </div>
    </section>
</main>

@include('marketing.partials.footer')

</body>
</html>
