<x-layouts.marketing title="Pricing">
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
                'Opes Forms & public reviews',
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
                'Opes Events ticketing',
                'Loyalty program & cards',
                'Priority support',
                'Custom branding',
                'Dedicated onboarding',
            ],
            'highlight' => false,
        ],
    ];

    // The benefits, spelled out module by module — the same list backs the
    // three tiers above, so the two can never quietly drift apart.
    $moduleAccess = [
        ['module' => 'Sales, invoicing & receipts', 'basic' => true, 'growth' => true, 'business' => true],
        ['module' => 'Customers & CRM', 'basic' => true, 'growth' => true, 'business' => true],
        ['module' => 'Inventory', 'basic' => true, 'growth' => true, 'business' => true],
        ['module' => 'Offline mode (PWA)', 'basic' => true, 'growth' => true, 'business' => true],
        ['module' => 'QR verification', 'basic' => true, 'growth' => true, 'business' => true],
        ['module' => 'Business documents & letters (26 templates)', 'basic' => false, 'growth' => true, 'business' => true],
        ['module' => 'Statement of account', 'basic' => false, 'growth' => true, 'business' => true],
        ['module' => 'Custom letterhead & business card designs', 'basic' => false, 'growth' => true, 'business' => true],
        ['module' => 'Opes Forms (builder + website embed)', 'basic' => false, 'growth' => true, 'business' => true],
        ['module' => 'Public reviews', 'basic' => false, 'growth' => true, 'business' => true],
        ['module' => 'Email & in-app notifications', 'basic' => false, 'growth' => true, 'business' => true],
        ['module' => 'Opes Events (ticketing & QR check-in)', 'basic' => false, 'growth' => false, 'business' => true],
        ['module' => 'Loyalty program & printed cards', 'basic' => false, 'growth' => false, 'business' => true],
        ['module' => 'Priority support', 'basic' => false, 'growth' => false, 'business' => true],
    ];
@endphp

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
                        <span class="absolute -top-3 left-6 rounded-full bg-fill-brand px-3 py-1 text-[11.5px] font-semibold text-white">Most popular</span>
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
                       class="tap focusable mt-7 flex h-12 w-full items-center justify-center rounded-xl text-[15px] font-semibold transition-opacity hover:opacity-90 {{ $tier['highlight'] ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink' }}">
                        Get started
                    </a>
                </div>
            @endforeach
        </div>

        <p class="mt-8 text-center text-[13px] text-faint">
            Prices shown in {{ \App\Support\Money::symbol('XAF') }} (Central African CFA franc). No card required to get started.
        </p>
    </section>

    {{-- The benefits: which plan unlocks which module, spelled out. --}}
    <section class="border-t border-border py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-5">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-[24px] font-bold tracking-[-0.02em] text-ink sm:text-[28px]">What each plan unlocks</h2>
                <p class="mt-3 text-[14.5px] text-muted">Every module the platform offers, and the plan it's included from.</p>
            </div>

            {{--
                Two presentations of one list.

                A three-column matrix needs about 520px to stay legible, which
                on a 390px phone meant two of the three plans sat off-screen
                behind a scroll nobody could see. Below `md` the same data is
                grouped by the plan that unlocks it, which answers the question
                someone on a phone is actually asking — "what do I get if I pay
                for this one?" — without any sideways movement at all.
            --}}
            @php
                $unlockedAt = [
                    'Basic' => array_values(array_filter($moduleAccess, fn ($r) => $r['basic'])),
                    'Growth' => array_values(array_filter($moduleAccess, fn ($r) => ! $r['basic'] && $r['growth'])),
                    'Business' => array_values(array_filter($moduleAccess, fn ($r) => ! $r['growth'] && $r['business'])),
                ];
            @endphp

            <div class="mt-8 space-y-4 md:hidden">
                @foreach ($unlockedAt as $plan => $rows)
                    <div class="card p-5">
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="text-[16px] font-bold tracking-[-0.01em] text-ink">
                                {{ $loop->first ? 'Included in ' : 'Added in ' }}{{ $plan }}
                            </h3>
                            <span class="shrink-0 text-[12.5px] font-semibold text-faint">{{ count($rows) }} modules</span>
                        </div>
                        <ul class="mt-4 space-y-2.5 text-[14px] text-ink-2">
                            @foreach ($rows as $row)
                                <li class="flex gap-2.5">
                                    <x-icon name="check-circle" class="mt-0.5 size-[16px] shrink-0 text-positive" stroke-width="2" />
                                    <span>{{ $row['module'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @unless ($loop->first)
                            <p class="mt-4 border-t border-border pt-3 text-[13px] text-muted">
                                Everything in {{ $loop->index === 1 ? 'Basic' : 'Growth' }}, plus the above.
                            </p>
                        @endunless
                    </div>
                @endforeach
            </div>

            <div class="mt-8 hidden overflow-x-auto md:block">
                <table class="w-full min-w-[520px] border-collapse text-[14px]">
                    <thead>
                        <tr class="border-b border-border">
                            <th class="px-3 py-3 text-left font-semibold text-ink-2">Module</th>
                            @foreach ($tiers as $tier)
                                <th class="px-3 py-3 text-center font-semibold {{ $tier['highlight'] ? 'text-brand' : 'text-ink-2' }}">{{ $tier['name'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moduleAccess as $row)
                            <tr class="border-b border-border last:border-0">
                                <td class="px-3 py-3 text-ink-2">{{ $row['module'] }}</td>
                                @foreach (['basic', 'growth', 'business'] as $key)
                                    <td class="px-3 py-3 text-center">
                                        @if ($row[$key])
                                            <x-icon name="check-circle" class="mx-auto size-[18px] text-positive" stroke-width="2" />
                                        @else
                                            <span class="text-faint">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="border-t border-border py-16 text-center sm:py-20">
        <h2 class="text-[24px] font-bold tracking-[-0.02em] text-ink sm:text-[28px]">Questions before you start?</h2>
        <p class="mx-auto mt-3 max-w-md text-[14.5px] text-muted">We're happy to talk you through which plan fits.</p>
        <div class="mt-7">
            <a href="{{ route('marketing.contact') }}"
               class="tap focusable inline-flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Contact us
            </a>
        </div>
    </section>
</x-layouts.marketing>
