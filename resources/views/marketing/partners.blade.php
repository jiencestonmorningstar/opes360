@php
    use App\Support\Money;

    $price = fn (int|float $amount) => Money::format((int) round($amount), 'XAF', false);

    // Read from config rather than written here, so the rate advertised on this
    // page is by construction the rate the ledger applies.
    $cardFee = (int) config('opes.partners.card_fee');
    $rate = (float) config('opes.partners.commission_rate');
    $minimum = (int) config('opes.partners.payout_minimum');
    $percent = rtrim(rtrim(number_format($rate * 100, 1), '0'), '.');

    $steps = [
        [
            'icon' => 'briefcase',
            'title' => 'Open a secretariat account',
            'body' => 'It is a normal '.config('opes.brand.name').' business account with a client book attached. You run your own shop on it — invoices, receipts, stock — exactly as any other business does.',
        ],
        [
            'icon' => 'user-plus',
            'title' => 'Add the clients you already serve',
            'body' => 'A client is just a business you print for. They need no account and no phone, and you can hold as many as you like.',
        ],
        [
            'icon' => 'printer',
            'title' => 'Issue their cards and letterheads',
            'body' => 'Pick from the card designs, fill in their details and print. Each card issued is charged to your account at '.Money::format($cardFee, 'XAF', false).' — you set your own price to the client.',
        ],
        [
            'icon' => 'spark',
            'title' => 'Earn when a client signs up',
            'body' => 'Send a client your invitation link. If they take a plan, '.$percent.'% of every payment they make is credited to you — not once, but every month they stay.',
        ],
    ];
@endphp

<x-layouts.marketing title="Partner programme"
                     description="For secretariats and print shops: issue business cards and letterheads for your clients, and earn a recurring share of every business you bring onto Opes360.">


{{-- ────────────────────────────────────────────────────────── Hero ── --}}
<section class="relative overflow-hidden border-b border-border">
    <div aria-hidden="true"
         class="pointer-events-none absolute inset-x-0 -top-40 h-[420px] bg-gradient-to-b from-accent-purple/[0.09] to-transparent"></div>

    <div class="relative mx-auto max-w-3xl px-5 pb-14 pt-14 text-center sm:pb-20 sm:pt-20">
        <span class="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1.5 text-[12.5px] font-semibold text-ink-2">
            <span class="size-1.5 rounded-full bg-fill-purple"></span>
            Secretariats &amp; print shops
        </span>

        <h1 class="mt-5 text-[32px] font-bold leading-[1.1] tracking-[-0.025em] text-ink sm:text-[44px]">
            Keep printing.<br>
            Start <span class="text-accent-purple">earning from it twice</span>.
        </h1>

        <p class="mx-auto mt-5 max-w-xl text-[16px] leading-relaxed text-muted sm:text-[17px]">
            You already make business cards and letterheads for the shops on your street.
            Make them here instead: {{ $price($cardFee) }} a card, your own price to the client — and
            {{ $percent }}% of every subscription from the businesses you bring in.
        </p>

        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('register') }}?type=secretariat"
               class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-purple px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Open a secretariat account
            </a>
            <a href="{{ route('marketing.contact') }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-accent-purple/40">
                Ask a question first
            </a>
        </div>
    </div>
</section>

{{-- ───────────────────────────────────────────────── The two earnings ── --}}
<section class="mx-auto max-w-6xl px-5 py-16 sm:py-20">
    <div class="grid gap-5 lg:grid-cols-2">
        <div class="card p-7 sm:p-8">
            <span class="flex size-11 items-center justify-center rounded-xl bg-tint-purple">
                <x-icon name="printer" class="size-[21px] text-accent-purple" stroke-width="1.9" />
            </span>
            <h2 class="mt-5 text-[20px] font-bold tracking-[-0.02em] text-ink">The printing, as usual</h2>
            <p class="mt-2.5 text-[14.5px] leading-relaxed text-muted">
                Every card you generate costs you {{ $price($cardFee) }}, charged to your account and
                settled monthly. What you charge the client is entirely yours.
            </p>
            <div class="mt-6 rounded-xl border border-border bg-surface-2 p-5">
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">A hundred cards in a month</p>
                <dl class="mt-3 space-y-2 text-[14px]">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-ink-2">You charge, say {{ $price(2000) }} each</dt>
                        <dd class="tnum font-semibold text-ink">{{ $price(200000) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-ink-2">Platform fee, {{ $price($cardFee) }} each</dt>
                        <dd class="tnum font-semibold text-negative">−{{ $price($cardFee * 100) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-t border-border pt-2">
                        <dt class="font-semibold text-ink">You keep</dt>
                        <dd class="tnum text-[17px] font-bold text-positive">{{ $price(200000 - $cardFee * 100) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="card p-7 sm:p-8">
            <span class="flex size-11 items-center justify-center rounded-xl bg-tint-green">
                <x-icon name="trending-up" class="size-[21px] text-accent-green" stroke-width="1.9" />
            </span>
            <h2 class="mt-5 text-[20px] font-bold tracking-[-0.02em] text-ink">The part that keeps paying</h2>
            <p class="mt-2.5 text-[14.5px] leading-relaxed text-muted">
                A client who liked their card often wants the rest of it. If they sign up through you,
                {{ $percent }}% of everything they pay is credited to you — every month, not once.
            </p>
            <div class="mt-6 rounded-xl border border-border bg-surface-2 p-5">
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Twenty clients on the Growth plan</p>
                <dl class="mt-3 space-y-2 text-[14px]">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-ink-2">They pay {{ $price(9000) }}/month each</dt>
                        <dd class="tnum font-semibold text-ink">{{ $price(180000) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-ink-2">Your share, {{ $percent }}%</dt>
                        <dd class="tnum font-semibold text-positive">{{ $price(180000 * $rate) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-t border-border pt-2">
                        <dt class="font-semibold text-ink">Over a year</dt>
                        <dd class="tnum text-[17px] font-bold text-positive">{{ $price(180000 * $rate * 12) }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>

    <p class="mt-5 text-[13px] leading-relaxed text-faint">
        Figures are illustrations of how the arithmetic works, not a forecast. What you charge for a card
        is your decision, and what a client pays depends on the plan they choose.
    </p>
</section>

{{-- ──────────────────────────────────────────────────── How it works ── --}}
<section class="border-y border-border bg-surface-2/60 py-16 sm:py-20">
    <div class="mx-auto max-w-6xl px-5">
        <div class="max-w-2xl">
            <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-accent-purple">How it works</p>
            <h2 class="mt-3 text-[27px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[32px]">
                Four steps, then it runs itself
            </h2>
        </div>

        <ol class="mt-9 grid gap-4 sm:grid-cols-2">
            @foreach ($steps as $index => $step)
                <li class="card flex gap-4 p-5 sm:p-6">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-tint-purple">
                        <x-icon :name="$step['icon']" class="size-[19px] text-accent-purple" stroke-width="1.9" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Step {{ $index + 1 }}</p>
                        <h3 class="mt-1 text-[16px] font-bold tracking-[-0.01em] text-ink">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-[14px] leading-relaxed text-muted">{{ $step['body'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ───────────────────────────────────────────────────────── Details ── --}}
<section class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
    <h2 class="text-[24px] font-bold tracking-[-0.02em] text-ink sm:text-[28px]">The details, plainly</h2>

    <dl class="mt-8 divide-y divide-border">
        @foreach ([
            'What does a card cost me?' => $price($cardFee).' per card generated, whichever design you choose. Letterheads for a client are charged the same way.',
            'When am I charged?' => 'Cards are recorded as you issue them and settled at the end of each month. Nothing is taken up front.',
            'How is the '.$percent.'% worked out?' => $percent.'% of each subscription payment a business you enrolled actually makes — after it succeeds, never on an invoice that went unpaid.',
            'How long does the commission last?' => 'For as long as that business keeps paying. It is a share of the subscription, not a one-off finder\'s fee.',
            'When do I get paid?' => 'Commission is credited to your balance as each payment settles. You can request a payout once the balance is above '.$price($minimum).'.',
            'Can I still run my own business on it?' => 'Yes — a secretariat account is a full business account. The client book and earnings page are added to it, nothing is taken away.',
            'What if a client already has an account?' => 'Then there is no commission on them, because you did not bring them in. You can still issue cards for them at the usual fee.',
        ] as $question => $answer)
            <div class="py-5">
                <dt class="text-[15.5px] font-semibold text-ink">{{ $question }}</dt>
                <dd class="mt-2 text-[14.5px] leading-relaxed text-muted">{{ $answer }}</dd>
            </div>
        @endforeach
    </dl>
</section>

{{-- ───────────────────────────────────────────────────────── Closing ── --}}
<section class="border-t border-border">
    <div class="mx-auto max-w-3xl px-5 py-16 text-center sm:py-20">
        <h2 class="text-[26px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[32px]">
            The shops are already your customers
        </h2>
        <p class="mx-auto mt-4 max-w-lg text-[15.5px] leading-relaxed text-muted">
            You know which of them need an invoice book they cannot lose. Bring them in, and keep a
            share of it.
        </p>
        <div class="mt-8">
            <a href="{{ route('register') }}?type=secretariat"
               class="tap focusable inline-flex h-12 items-center justify-center rounded-xl bg-fill-purple px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Open a secretariat account
            </a>
        </div>
    </div>
</section>

</x-layouts.marketing>
