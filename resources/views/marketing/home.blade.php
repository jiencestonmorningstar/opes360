<x-layouts.marketing :title="config('opes.brand.tagline')"
                     description="Sales, invoicing, customers, inventory and verified business documents in one suite — built to keep working when the connection doesn't.">
    {{-- Hero --}}
    @php
        // Four slides of three cards apiece — every module in the product
        // gets a slot here rather than the six-item sample below, which stays
        // as a slower-paced recap for anyone who scrolls past the slider.
        $heroSlides = [
            [
                ['icon' => 'document-plus', 'title' => 'Sales & Invoicing', 'body' => 'Quotations, invoices, proforma and receipts, numbered and issued in a few taps.'],
                ['icon' => 'users', 'title' => 'Customers & CRM', 'body' => 'Full history and balance for every customer, with statements ready to share.'],
                ['icon' => 'cube', 'title' => 'Inventory', 'body' => 'Products and stock levels that update themselves as sales go out the door.'],
            ],
            [
                ['icon' => 'document', 'title' => 'Business Documents', 'body' => '26 templates — contracts, letters, payslips — generated on your letterhead.'],
                ['icon' => 'qr-code', 'title' => 'QR Verification', 'body' => 'Every invoice, receipt and document carries a QR that proves it is genuine.'],
                ['icon' => 'briefcase', 'title' => 'Card & Letterhead', 'body' => 'Four branded designs each for your business card and letterhead, ready to print.'],
            ],
            [
                ['icon' => 'clipboard', 'title' => 'Opes Forms', 'body' => 'Build a form, share the link or embed it on your own website, watch responses arrive.'],
                ['icon' => 'ticket', 'title' => 'Opes Events', 'body' => 'Sell tickets with QR check-in at the door — no oversell, ever.'],
                ['icon' => 'spark', 'title' => 'Loyalty Program', 'body' => 'Customers earn points automatically and carry a printed loyalty card.'],
            ],
            [
                ['icon' => 'bell', 'title' => 'Notifications', 'body' => 'Email and in-app alerts for payments, sales and responses — no external service.'],
                ['icon' => 'check-circle', 'title' => 'Public Reviews', 'body' => 'Customers leave a rated review on your verified public profile.'],
                ['icon' => 'offline', 'title' => 'Offline-first', 'body' => 'Keep selling with no connection — everything syncs the moment it returns.'],
            ],
        ];
    @endphp

    <section class="mx-auto max-w-6xl px-5 pb-16 pt-16 sm:pb-24 sm:pt-24">
        <div class="grid items-center gap-10 lg:grid-cols-12 lg:gap-10">
            {{-- Text --}}
            <div class="text-center lg:col-span-5 lg:text-left">
                <h1 class="text-[34px] font-bold leading-[1.1] tracking-[-0.02em] text-ink sm:text-[46px] lg:text-[40px]">
                    Run your business on
                    <span class="text-brand">{{ config('opes.brand.name') }}</span>
                </h1>
                <p class="mx-auto mt-5 max-w-xl text-[16px] leading-relaxed text-muted sm:text-[17px] lg:mx-0">
                    Invoices, customers, inventory and verified business documents — in one suite that
                    keeps working when the connection doesn't.
                </p>

                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row lg:justify-start">
                    <a href="{{ route('demo.request') }}"
                       class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                        Try a demo
                    </a>
                    <a href="{{ route('marketing.pricing') }}"
                       class="tap focusable flex h-12 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
                        See pricing
                    </a>
                </div>
                <p class="mt-4 text-[13px] text-faint">No card required · Basic plan from {{ \App\Support\Money::format(3000, 'XAF', false) }}/month</p>
            </div>

            {{-- Slider: 4 slides of 3 feature cards, to the right of the text on
                 desktop. Pure Alpine — no carousel library — auto-advances and
                 pauses on hover/focus so it never fights someone reading a card. --}}
            <div class="lg:col-span-7"
                 x-data="{
                    slide: 0,
                    total: {{ count($heroSlides) }},
                    timer: null,
                    start() { this.timer = setInterval(() => this.next(), 5000) },
                    stop() { clearInterval(this.timer) },
                    next() { this.slide = (this.slide + 1) % this.total },
                    prev() { this.slide = (this.slide - 1 + this.total) % this.total },
                 }"
                 x-init="start()" @mouseenter="stop()" @mouseleave="start()" @focusin="stop()" @focusout="start()">

                <div class="relative overflow-hidden rounded-2xl">
                    <div class="flex transition-transform duration-500 ease-out"
                         :style="`transform: translateX(-${slide * 100}%)`">
                        @foreach ($heroSlides as $cards)
                            <div class="grid w-full shrink-0 grid-cols-1 gap-4 sm:grid-cols-3">
                                @foreach ($cards as $feature)
                                    <div class="card p-5">
                                        <span class="flex size-10 items-center justify-center rounded-lg bg-tint-blue">
                                            <x-icon :name="$feature['icon']" class="size-[19px] text-brand" stroke-width="1.9" />
                                        </span>
                                        <h3 class="mt-3.5 text-[15px] font-semibold text-ink">{{ $feature['title'] }}</h3>
                                        <p class="mt-1 text-[13px] leading-relaxed text-muted">{{ $feature['body'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Dots + arrows --}}
                <div class="mt-4 flex items-center justify-center gap-4 lg:justify-start">
                    <button type="button" @click="prev()" aria-label="Previous slide"
                            class="focusable flex size-8 items-center justify-center rounded-full border border-border text-muted hover:text-ink">
                        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
                    </button>
                    <div class="flex items-center gap-2">
                        @foreach ($heroSlides as $index => $cards)
                            <button type="button" @click="slide = {{ $index }}" aria-label="Go to slide {{ $index + 1 }}"
                                    class="focusable size-2 rounded-full transition-colors"
                                    :class="slide === {{ $index }} ? 'bg-fill-brand' : 'bg-border'"></button>
                        @endforeach
                    </div>
                    <button type="button" @click="next()" aria-label="Next slide"
                            class="focusable flex size-8 items-center justify-center rounded-full border border-border text-muted hover:text-ink">
                        <x-icon name="chevron-right" class="size-[16px]" stroke-width="2.2" />
                    </button>
                </div>
            </div>
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
                   class="tap focusable inline-flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
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
                   class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                    Try a demo
                </a>
                <a href="{{ route('marketing.contact') }}"
                   class="tap focusable flex h-12 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
                    Talk to us first
                </a>
            </div>
        </div>
    </section>
</x-layouts.marketing>
