@php
    use App\Support\Money;

    $price = fn (int $amount) => Money::format($amount, 'XAF', false);

    /*
     * The whole product, once. The hero used to carry a four-slide carousel of
     * the same modules the grid below listed again, so the first two screens of
     * the page said the same six things twice. One list, in one place.
     */
    $modules = [
        ['icon' => 'document-plus', 'label' => 'Sales & invoicing'],
        ['icon' => 'users', 'label' => 'Customers & CRM'],
        ['icon' => 'cube', 'label' => 'Inventory'],
        ['icon' => 'document', 'label' => 'Business documents'],
        ['icon' => 'qr-code', 'label' => 'QR verification'],
        ['icon' => 'briefcase', 'label' => 'Cards & letterheads'],
        ['icon' => 'banknotes', 'label' => 'Payments & receipts'],
        ['icon' => 'wallet', 'label' => 'SYSCOHADA accounting'],
        ['icon' => 'chart-bar', 'label' => 'Reports'],
        ['icon' => 'clipboard', 'label' => 'Opes Forms'],
        ['icon' => 'ticket', 'label' => 'Opes Events'],
        ['icon' => 'spark', 'label' => 'Loyalty program'],
        ['icon' => 'calendar', 'label' => 'Calendar'],
        ['icon' => 'bell', 'label' => 'Notifications'],
        ['icon' => 'check-circle', 'label' => 'Public reviews'],
        ['icon' => 'offline', 'label' => 'Offline mode'],
    ];

    /*
     * Three claims, each one something the product does that a receipt book
     * cannot. Alternating rows rather than a fourth grid of cards, so the page
     * has some rhythm instead of one shape repeated down its whole length.
     */
    $pillars = [
        [
            'eyebrow' => 'Sell',
            'title' => 'From quotation to paid receipt without leaving the app',
            'body' => 'Issue a quotation, turn it into an invoice when it is accepted, record the payment and print the receipt. Documents are numbered in sequence, and every gap in that sequence carries a dated explanation.',
            'points' => ['Quotations, proforma, invoices, receipts', 'TVA computed to DGI rules', 'Customer balance and statement, always current'],
            'mock' => 'sell',
            'accent' => 'blue',
        ],
        [
            'eyebrow' => 'Prove',
            'title' => 'Every document carries a QR that proves it is genuine',
            'body' => 'Anyone holding a printed invoice, receipt or contract can scan it and see it verified against your business — no account, no app, and no phone call to your office to confirm it.',
            'points' => ['A verification page on your own domain', 'Works from a printed page, months later', 'A public profile customers can review'],
            'mock' => 'prove',
            'accent' => 'teal',
        ],
        [
            'eyebrow' => 'Keep trading',
            'title' => 'It does not stop when the connection does',
            'body' => 'Invoice numbers are leased to the device in advance, so a phone with no signal still issues correctly numbered documents. When the network comes back, everything syncs with the numbers it already printed.',
            'points' => ['Install it like an app, on any phone', 'Sell right through an outage', 'No duplicate or missing numbers on sync'],
            'mock' => 'offline',
            'accent' => 'orange',
        ],
    ];
@endphp

<x-layouts.marketing :title="config('opes.brand.tagline')"
                     description="Sales, invoicing, customers, inventory and verified business documents in one suite — built to keep working when the connection doesn't.">


{{-- ────────────────────────────────────────────────────────── Hero ── --}}
<section class="relative overflow-hidden border-b border-border">
    {{-- One wash behind the fold rather than a background colour on every
         section: the page reads as a single surface with the hero lifted off
         it, instead of five stacked bands of alternating grey. --}}
    <div aria-hidden="true"
         class="pointer-events-none absolute inset-x-0 -top-40 h-[420px] bg-gradient-to-b from-brand/[0.07] to-transparent"></div>

    <div class="relative mx-auto max-w-6xl px-5 pb-14 pt-14 sm:pb-20 sm:pt-20">
        <div class="grid items-center gap-12 lg:grid-cols-12 lg:gap-8">
            <div class="lg:col-span-6">
                <span class="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1.5 text-[12.5px] font-semibold text-ink-2">
                    <span class="size-1.5 rounded-full bg-fill-positive"></span>
                    Built in Cameroon, for businesses here
                </span>

                <h1 class="mt-5 text-[34px] font-bold leading-[1.08] tracking-[-0.025em] text-ink sm:text-[46px] lg:text-[44px]">
                    Run the whole business<br class="hidden sm:block">
                    from <span class="text-brand">one place</span>.
                </h1>

                <p class="mt-5 max-w-lg text-[16px] leading-relaxed text-muted sm:text-[17px]">
                    Invoices, customers, stock, accounting and verified business documents —
                    in one suite that keeps working when the connection doesn't.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('demo.request') }}"
                       class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                        Try a demo
                    </a>
                    <a href="{{ route('marketing.pricing') }}"
                       class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40">
                        See pricing
                    </a>
                </div>

                <p class="mt-5 text-[13.5px] text-muted">
                    No card required · Basic plan from <span class="font-semibold text-ink-2">{{ $price(3000) }}/month</span>
                </p>
            </div>

            {{-- The product drawn rather than described. Pure CSS on the same
                 tokens as the app: a screenshot would be one more asset to keep
                 current, and would be wrong in whichever theme it wasn't taken in. --}}
            <div class="lg:col-span-6">
                <div class="relative mx-auto w-full max-w-[420px] sm:mb-8" aria-hidden="true">
                    <div class="card overflow-hidden shadow-raised">
                        <div class="flex items-center gap-2.5 border-b border-border bg-surface-2 px-4 py-3">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-fill-brand text-[11px] font-bold text-white">OT</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[12.5px] font-semibold text-ink">Invoice INV-2026-0184</p>
                                <p class="text-[11px] text-faint">Issued today · Douala</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-tint-green px-2 py-1 text-[10.5px] font-semibold text-positive">
                                <x-icon name="check-circle" class="size-[11px]" stroke-width="2.4" /> Verified
                            </span>
                        </div>

                        <div class="space-y-3 px-4 py-4">
                            @foreach ([['Ciment 50kg', '12 ×', 78000], ['Fer à béton 12mm', '40 ×', 154000], ['Livraison Bonabéri', '1 ×', 15000]] as [$item, $qty, $amount])
                                <div class="flex items-center gap-3">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-tint-blue">
                                        <x-icon name="cube" class="size-[15px] text-accent-blue" stroke-width="1.9" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[12.5px] font-medium text-ink">{{ $item }}</p>
                                        <p class="text-[11px] text-faint">{{ $qty }}</p>
                                    </div>
                                    <span class="tnum shrink-0 text-[12.5px] font-semibold text-ink">{{ $price($amount) }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex items-end justify-between gap-4 border-t border-border px-4 py-4">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-faint">Total TTC</p>
                                <p class="tnum mt-0.5 text-[21px] font-bold tracking-[-0.02em] text-ink">{{ $price(294050) }}</p>
                                <p class="text-[11px] text-faint">TVA 19,25% incluse</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-center gap-1.5">
                                <span class="flex size-[52px] items-center justify-center rounded-lg border border-border bg-surface-2">
                                    <x-icon name="qr-code" class="size-[30px] text-ink" stroke-width="1.6" />
                                </span>
                                <span class="text-[9.5px] font-semibold uppercase tracking-wide text-faint">Scan to verify</span>
                            </div>
                        </div>
                    </div>

                    {{-- The offline claim, shown the way the app shows it. Stacked
                         under the card on a phone, floated off it from `sm` where
                         there is room for it not to collide. --}}
                    <div class="mt-3 sm:absolute sm:-bottom-6 sm:-left-5 sm:mt-0 sm:w-[236px]">
                        <div class="card flex items-center gap-2.5 px-3.5 py-3 shadow-raised">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-tint-orange">
                                <x-icon name="offline" class="size-[15px] text-accent-orange" stroke-width="1.9" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[12px] font-semibold text-ink">Offline — 3 to sync</p>
                                <p class="text-[11px] text-faint">Issued without a signal</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ──────────────────────────────────────────────────── Proof strip ── --}}
<section class="border-b border-border bg-surface-2/60">
    <div class="mx-auto grid max-w-6xl grid-cols-2 px-5 py-8 sm:py-10 lg:grid-cols-4">
        @foreach ([
            ['26', 'document templates', 'Contracts, letters, payslips and certificates on your letterhead.'],
            ['98', 'card designs', 'Business cards by sector, generated from your profile.'],
            ['100%', 'offline capable', 'Every screen keeps working with no connection.'],
            ['SYSCOHADA', 'accounting', 'Double-entry journal, ledgers and statements.'],
        ] as [$figure, $label, $detail])
            <div class="px-1 py-3 lg:px-5">
                <p class="text-[24px] font-bold leading-none tracking-[-0.03em] text-ink sm:text-[30px]">{{ $figure }}</p>
                <p class="mt-1.5 text-[13px] font-semibold text-ink-2">{{ $label }}</p>
                <p class="mt-1 hidden text-[12.5px] leading-relaxed text-muted sm:block">{{ $detail }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- ───────────────────────────────────────────────────────── Pillars ── --}}
<section class="mx-auto max-w-6xl px-5 py-16 sm:py-24">
    <div class="max-w-2xl">
        <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-brand">What it does</p>
        <h2 class="mt-3 text-[27px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[34px]">
            Three things a receipt book cannot do
        </h2>
    </div>

    <div class="mt-12 space-y-14 sm:mt-14 sm:space-y-20">
        @foreach ($pillars as $index => $pillar)
            @php
                // Written out in full rather than interpolated: Tailwind scans
                // source text, so `text-accent-{$x}` would never be generated.
                $eyebrowClass = ['blue' => 'text-accent-blue', 'teal' => 'text-accent-teal', 'orange' => 'text-accent-orange'][$pillar['accent']];
            @endphp
            <div class="grid items-center gap-8 lg:grid-cols-12 lg:gap-12">
                <div class="lg:col-span-6 {{ $index % 2 === 1 ? 'lg:order-2' : '' }}">
                    <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] {{ $eyebrowClass }}">{{ $pillar['eyebrow'] }}</p>
                    <h3 class="mt-2.5 text-[21px] font-bold leading-snug tracking-[-0.02em] text-ink sm:text-[25px]">{{ $pillar['title'] }}</h3>
                    <p class="mt-3.5 text-[15px] leading-relaxed text-muted">{{ $pillar['body'] }}</p>

                    <ul class="mt-5 space-y-2.5">
                        @foreach ($pillar['points'] as $point)
                            <li class="flex gap-2.5 text-[14.5px] text-ink-2">
                                <x-icon name="check-circle" class="mt-0.5 size-[17px] shrink-0 text-positive" stroke-width="2" />
                                <span>{{ $point }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="lg:col-span-6 {{ $index % 2 === 1 ? 'lg:order-1' : '' }}">
                    @include('marketing.partials.mock-'.$pillar['mock'])
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- ───────────────────────────────────────────────────── Module grid ── --}}
<section class="border-y border-border bg-surface-2/60 py-16 sm:py-20">
    <div class="mx-auto max-w-6xl px-5">
        <div class="max-w-2xl">
            <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-brand">Everything included</p>
            <h2 class="mt-3 text-[27px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[32px]">
                Sixteen modules, one login
            </h2>
            <p class="mt-3 text-[15px] leading-relaxed text-muted">
                Nothing to buy as an add-on, and nothing that only works on a laptop.
            </p>
        </div>

        <div class="mt-9 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($modules as $module)
                <div class="card flex items-center gap-3 p-3.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tint-blue">
                        <x-icon :name="$module['icon']" class="size-[17px] text-accent-blue" stroke-width="1.9" />
                    </span>
                    <span class="min-w-0 text-[13.5px] font-semibold leading-tight text-ink">{{ $module['label'] }}</span>
                </div>
            @endforeach
        </div>

        <div class="mt-8">
            <a href="{{ route('marketing.features') }}" class="focusable text-[14.5px] font-semibold text-brand hover:underline">
                What each module does →
            </a>
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────── Secretariat partners ── --}}
<section class="mx-auto max-w-6xl px-5 py-16 sm:py-20">
    <div class="card overflow-hidden">
        <div class="grid lg:grid-cols-12">
            <div class="p-7 sm:p-10 lg:col-span-7">
                <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-accent-purple">Partner programme</p>
                <h2 class="mt-3 text-[24px] font-bold leading-tight tracking-[-0.02em] text-ink sm:text-[30px]">
                    For secretariats and print shops
                </h2>
                <p class="mt-4 text-[15px] leading-relaxed text-muted">
                    You already make business cards, letterheads and contact cards for the businesses
                    around you. Make them on {{ config('opes.brand.name') }} instead, at
                    <span class="font-semibold text-ink-2">{{ $price(500) }} a card</span> — and when a client you
                    brought in signs up, you keep earning from it.
                </p>

                <ul class="mt-6 space-y-3">
                    @foreach ([
                        ['spark', '10% of every subscription from a business you enrol, for as long as they stay.'],
                        ['briefcase', 'Issue cards and letterheads for clients who have no account of their own.'],
                        ['chart-bar', 'One page for clients, cards issued, commission earned and what you are owed.'],
                    ] as [$icon, $point])
                        <li class="flex gap-3 text-[14.5px] text-ink-2">
                            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-tint-purple">
                                <x-icon :name="$icon" class="size-[14px] text-accent-purple" stroke-width="2" />
                            </span>
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('marketing.partners') }}"
                   class="tap focusable mt-7 inline-flex h-12 items-center justify-center rounded-xl bg-fill-purple px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    How the programme works
                </a>
            </div>

            <div class="flex items-center justify-center border-t border-border bg-tint-purple p-8 lg:col-span-5 lg:border-l lg:border-t-0">
                <div class="w-full max-w-[260px] space-y-3" aria-hidden="true">
                    @foreach ([
                        ['Boulangerie Nkolbisson', 'Signed up · Growth', '+'.$price(900).'/mo'],
                        ['Garage Akwa', '4 cards issued', $price(2000)],
                        ['Coiffure Bépanda', 'Signed up · Basic', '+'.$price(300).'/mo'],
                    ] as [$client, $state, $amount])
                        <div class="card flex items-center gap-3 px-3.5 py-3">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-tint-slate text-[11px] font-bold text-accent-slate">
                                {{ collect(explode(' ', $client))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[12.5px] font-semibold text-ink">{{ $client }}</p>
                                <p class="truncate text-[11px] text-faint">{{ $state }}</p>
                            </div>
                            <span class="tnum shrink-0 text-[12px] font-semibold text-positive">{{ $amount }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────── Pricing ── --}}
<section class="border-t border-border bg-surface-2/60 py-16 sm:py-20">
    <div class="mx-auto max-w-6xl px-5">
        <div class="max-w-2xl">
            <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-brand">Pricing</p>
            <h2 class="mt-3 text-[27px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[32px]">
                Three plans, no surprises
            </h2>
            <p class="mt-3 text-[15px] leading-relaxed text-muted">
                Annual billing is twelve months for the price of ten, on every plan.
            </p>
        </div>

        <div class="mt-9 grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['Basic', 3000, 'One owner-operator getting started.', false],
                ['Growth', 9000, 'A small team that has outgrown one person.', true],
                ['Business', 21000, 'An established business running at scale.', false],
            ] as [$name, $monthly, $blurb, $popular])
                <div class="card relative flex flex-col p-5 {{ $popular ? 'border-brand ring-1 ring-brand' : '' }}">
                    @if ($popular)
                        <span class="absolute -top-3 left-5 rounded-full bg-fill-brand px-2.5 py-1 text-[11px] font-semibold text-white">Most popular</span>
                    @endif
                    <h3 class="text-[16px] font-bold text-ink">{{ $name }}</h3>
                    <p class="mt-1 flex-1 text-[13px] leading-relaxed text-muted">{{ $blurb }}</p>
                    <p class="mt-4">
                        <span class="tnum text-[26px] font-bold tracking-[-0.025em] text-ink">{{ $price($monthly) }}</span>
                        <span class="text-[13.5px] text-muted">/month</span>
                    </p>
                </div>
            @endforeach
        </div>

        <div class="mt-8">
            <a href="{{ route('marketing.pricing') }}"
               class="tap focusable inline-flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Compare the plans
            </a>
        </div>
    </div>
</section>

{{-- ──────────────────────────────────────────────────────── Final CTA ── --}}
<section class="border-t border-border">
    <div class="mx-auto max-w-3xl px-5 py-16 text-center sm:py-24">
        <h2 class="text-[27px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[34px]">
            Set it up this afternoon
        </h2>
        <p class="mx-auto mt-4 max-w-lg text-[15.5px] leading-relaxed text-muted">
            Register the business, add a customer and issue an invoice — about ten minutes,
            and there is no card to enter.
        </p>
        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('demo.request') }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Try a demo
            </a>
            <a href="{{ route('marketing.contact') }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40">
                Talk to us first
            </a>
        </div>
    </div>
</section>

</x-layouts.marketing>
