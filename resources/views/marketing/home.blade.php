@php
    use App\Support\Money;

    $price = fn (int $amount) => Money::format($amount, 'XAF', false);

    // Read from config rather than written here, so the terms advertised on the
    // home page are by construction the terms the ledger applies — the same
    // reason partners.blade.php does it.
    $partnerCardFee = (int) config('opes.partners.card_fee');
    $partnerPercent = rtrim(rtrim(number_format((float) config('opes.partners.commission_rate') * 100, 1), '0'), '.');

    $needs = [
        ['icon' => 'users', 'title' => 'Manage', 'body' => 'Customers, suppliers, partners and business relationships.'],
        ['icon' => 'sales', 'title' => 'Sell', 'body' => 'Products, services, quotations, orders, invoices and payments.'],
        ['icon' => 'cube', 'title' => 'Operate', 'body' => 'Inventory, procurement, documents, workflows and daily operations.'],
        ['icon' => 'user-plus', 'title' => 'Manage People', 'body' => 'HR, employees, attendance, leave management and performance.'],
        ['icon' => 'wallet', 'title' => 'Control Finances', 'body' => 'Accounting, income, expenses and financial visibility.'],
        ['icon' => 'chart-bar', 'title' => 'Grow', 'body' => 'Reports, analytics and insights to make better decisions.'],
    ];

    $before = [
        'Paperwork and manual records',
        'Disconnected tools and systems',
        'Scattered customer information',
        'Manual processes & approvals',
        'Repeated data entry',
        'Errors, delays and lost documents',
    ];

    $after = [
        'One connected digital platform',
        'All your data in one place',
        'Automated workflows & approvals',
        'Real-time insights & reports',
        'Paperless operations',
        'More time to grow your business',
    ];

    // Positioned by eye against the reference layout: a hexagon of six nodes
    // evenly spaced around the centre, matching angle order top → clockwise.
    $hub = [
        ['icon' => 'sales', 'label' => 'Sales', 'style' => 'top:-6px; left:50%; transform:translateX(-50%);'],
        ['icon' => 'cube', 'label' => 'Inventory', 'style' => 'top:22%; right:-14px;'],
        ['icon' => 'sales-cart', 'label' => 'Procurement', 'style' => 'bottom:22%; right:-14px;'],
        ['icon' => 'users', 'label' => 'HR', 'style' => 'bottom:-6px; left:50%; transform:translateX(-50%);'],
        ['icon' => 'wallet', 'label' => 'Operations', 'style' => 'bottom:22%; left:-14px;'],
        ['icon' => 'cog', 'label' => 'Business Hub', 'style' => 'top:22%; left:-14px;'],
    ];

    $tiers = [
        ['name' => 'Small Business', 'icon' => 'home', 'body' => 'Start with the tools you need today to organize, digitize and grow your business.'],
        ['name' => 'Growing Business', 'icon' => 'trending-up', 'body' => 'Connect departments, automate processes and gain complete visibility.'],
        ['name' => 'Enterprise', 'icon' => 'briefcase', 'body' => 'Manage complex operations, multiple branches and large teams with confidence.'],
    ];

    $industries = [
        ['icon' => 'sales', 'label' => 'Retail'],
        ['icon' => 'home', 'label' => 'Distribution'],
        ['icon' => 'printer', 'label' => 'Secretariats'],
        ['icon' => 'briefcase', 'label' => 'Construction'],
        ['icon' => 'heart', 'label' => 'Healthcare'],
        ['icon' => 'academic-cap', 'label' => 'Education'],
        ['icon' => 'building', 'label' => 'Hospitality'],
        ['icon' => 'truck', 'label' => 'Logistics'],
        ['icon' => 'shield', 'label' => 'Insurance'],
        ['icon' => 'home', 'label' => 'Real Estate'],
        ['icon' => 'spark', 'label' => 'Agriculture'],
        ['icon' => 'briefcase', 'label' => 'Professional Services'],
    ];

    // Grounded in the product rather than invented adoption figures: the
    // module count comes from config/modules.php, the retention floor from
    // the audit pruner, offline invoicing from the number-lease ledger.
    $stats = [
        ['icon' => 'cube', 'figure' => (string) count(config('modules')), 'label' => 'Modules, Switchable Per Business'],
        ['icon' => 'shield', 'figure' => '10 yrs', 'label' => 'Money Audit Trail Retained'],
        ['icon' => 'document', 'figure' => '100%', 'label' => 'Invoicing Works Offline'],
        ['icon' => 'smile', 'figure' => 'FCFA', 'label' => 'Priced Locally, Paid by Mobile Money'],
    ];

    $nav = [
        ['label' => 'Product', 'href' => '#everything', 'chevron' => true],
        ['label' => 'Solutions', 'href' => '#solutions', 'chevron' => true],
        ['label' => 'Modules', 'href' => route('marketing.features'), 'chevron' => true],
        ['label' => 'Industries', 'href' => '#industries', 'chevron' => true],
        ['label' => 'Pricing', 'href' => route('marketing.pricing'), 'chevron' => false],
        ['label' => 'Resources', 'href' => route('marketing.blog'), 'chevron' => true],
        ['label' => 'About', 'href' => route('marketing.about'), 'chevron' => false],
    ];
@endphp

<x-layouts.public :title="config('opes.brand.tagline')"
                   description="One platform for every business and every operation — customers, sales, inventory, purchases, HR, payroll and finance, all connected."
                   variant="page">

{{-- ═══════════════════════════════════════════════════════════ Header ══ --}}
<header class="sticky top-0 z-30 border-b border-border bg-canvas/95 backdrop-blur-sm" x-data="{ open: false }" @keydown.escape.window="open = false">
    <div class="mx-auto flex h-[76px] max-w-[1240px] items-center justify-between px-5">
        <a href="{{ route('dashboard') }}" class="focusable -m-1 shrink-0 rounded-lg p-1">
            <img src="{{ asset('images/brand/opes360-logo.png') }}" alt="{{ config('opes.brand.name') }}"
                 class="h-7 w-auto sm:h-8" width="1200" height="352">
        </a>

        <nav class="hidden items-center gap-7 text-[14.5px] font-semibold text-ink-2 lg:flex">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}" class="flex items-center gap-1 hover:text-brand">
                    {{ $item['label'] }}
                    @if ($item['chevron'])
                        <x-icon name="chevron-down" class="size-[13px]" stroke-width="2.2" />
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="hidden items-center gap-5 lg:flex">
            <a href="{{ route('login') }}" class="focusable text-[14.5px] font-semibold text-ink-2 hover:text-ink">Login</a>
            <a href="{{ route('register') }}"
               class="tap focusable flex h-11 items-center gap-1.5 rounded-full bg-fill-brand px-5 text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                Get Started Free
                <x-icon name="chevron-right" class="size-[14px]" stroke-width="2.5" />
            </a>
        </div>

        <div class="flex items-center gap-2 lg:hidden">
            <a href="{{ route('register') }}"
               class="tap focusable flex h-10 items-center gap-1 rounded-full bg-fill-brand px-3.5 text-[12.5px] font-semibold text-white transition-opacity hover:opacity-90 sm:px-4 sm:text-[13px]">
                Get Started Free
                <x-icon name="chevron-right" class="size-[12px]" stroke-width="2.5" />
            </a>
            <button type="button" @click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="menu" aria-label="Open menu"
                    class="tap focusable flex size-10 shrink-0 items-center justify-center rounded-xl text-ink">
                <x-icon name="menu" x-show="!open" class="size-[24px]" />
                <svg x-show="open" x-cloak viewBox="0 0 24 24" fill="none" class="size-[22px]" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>
    </div>

    <div x-cloak x-show="open" x-transition.origin.top @click.outside="open = false" class="border-t border-border bg-canvas lg:hidden">
        <nav class="mx-auto flex max-w-[1240px] flex-col gap-1 px-5 py-4 text-[15px] font-semibold text-ink-2">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}" class="rounded-lg px-2 py-2.5 hover:bg-surface-2 hover:text-ink">{{ $item['label'] }}</a>
            @endforeach
            <div class="mt-3 flex flex-col gap-2.5 border-t border-border pt-4">
                <a href="{{ route('login') }}" class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border text-[14.5px] font-semibold text-ink">Login</a>
                <a href="{{ route('register') }}" class="tap focusable flex h-11 items-center justify-center rounded-full bg-fill-brand text-[14.5px] font-semibold text-white">Get Started Free</a>
            </div>
        </nav>
    </div>
</header>

<main>

{{-- ═════════════════════════════════════════════════════════════ Hero ══ --}}
<section class="relative overflow-hidden">
    <div class="relative mx-auto max-w-[1240px] px-5 pb-16 pt-12 sm:pb-20 sm:pt-16">
        <div class="grid items-center gap-8 lg:grid-cols-12 lg:gap-6">
            <div class="order-2 lg:order-1 lg:col-span-5">
                <span class="inline-flex items-center rounded-full border border-brand/30 bg-tint-blue px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-brand">
                    All-in-One Business ERP
                </span>

                <h1 class="mt-5 text-[28px] font-extrabold uppercase leading-[1.12] tracking-[-0.015em] text-ink sm:text-[33px] lg:text-[40px]">
                    One platform.<br>
                    <span class="text-brand">Every business.</span><br>
                    Every operation.
                </h1>
                <div class="mt-3 h-[3px] w-12 rounded-full bg-brand"></div>

                <p class="mt-5 max-w-md text-[16px] leading-relaxed text-muted lg:text-[18.5px]">
                    {{ config('opes.brand.name') }} brings your entire business together in one powerful platform —
                    customers, sales, inventory, suppliers, HR, payroll, finance and more.
                    All your operations. Digitized. Connected. Paperless.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('register') }}"
                       class="tap focusable flex h-12 items-center justify-center gap-1.5 rounded-full bg-fill-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                        Get Started Free
                        <x-icon name="chevron-right" class="size-[14px]" stroke-width="2.5" />
                    </a>
                    <a href="{{ route('demo.request') }}"
                       class="tap focusable flex h-12 items-center justify-center gap-1.5 rounded-full border-2 border-brand px-6 text-[14.5px] font-semibold text-brand transition-colors hover:bg-tint-blue">
                        Book a Demo
                        <x-icon name="calendar" class="size-[14px]" />
                    </a>
                </div>

                <div class="mt-8 flex flex-wrap gap-x-6 gap-y-3">
                    @foreach ([
                        ['document', 'No Credit Card Required'],
                        ['spark', 'Quick & Easy Setup'],
                        ['shield', 'Secure, Reliable & Always Available'],
                    ] as [$icon, $label])
                        <span class="flex max-w-[130px] items-start gap-2 text-[11.5px] font-medium leading-tight text-faint">
                            <x-icon :name="$icon" class="mt-0.5 size-[16px] shrink-0 text-brand" stroke-width="1.8" />
                            {{ $label }}
                        </span>
                    @endforeach
                </div>
            </div>

            {{-- Mobile meets the globe alone: the dashboard carousel's text is
                 unreadable at phone width, so small screens get the network
                 motif and meet the full product screenshot once there is room
                 for it at `lg`. Spin is CSS, so a static PNG reads as a live
                 network rather than a photo. --}}
            <div class="relative order-1 -mx-5 flex aspect-[4/3] w-[calc(100%+2.5rem)] items-center justify-center overflow-hidden rounded-b-[2.5rem] bg-[#0b2c93] sm:mx-0 sm:aspect-[16/9] sm:w-full sm:rounded-[2rem] lg:hidden" aria-hidden="true">
                <img src="{{ asset('images/marketing/globe.png') }}" alt="" aria-hidden="true"
                     class="animate-spin-slow w-[70%] max-w-[320px]" width="1319" height="1193">
            </div>

            {{-- The dashboard screenshots read as clutter without a device frame
                 around them, so the desktop hero keeps the globe alone — the
                 same spinning graphic mobile gets, just with room to breathe. --}}
            <div class="order-1 hidden lg:order-2 lg:col-span-7 lg:flex lg:items-center lg:justify-center">
                <img src="{{ asset('images/marketing/globe.png') }}" alt="" aria-hidden="true"
                     class="animate-spin-slow w-full max-w-[560px]" width="1319" height="1193">
            </div>
        </div>
    </div>
</section>

{{-- ═════════════════════════════════════════════════════ Before/After ══ --}}
<section class="border-y border-border bg-[#f7f8fb] dark:bg-surface-2 py-16 sm:py-20">
    <div class="mx-auto max-w-[1240px] px-5">
        <h2 class="text-center text-[22px] font-extrabold uppercase tracking-[-0.01em] text-ink sm:text-[27px]">
            Your business, without the paperwork.
        </h2>
        <div class="mx-auto mt-3 h-[3px] w-14 rounded-full bg-brand"></div>

        <div class="mt-10 grid items-center gap-6 lg:grid-cols-[1fr_auto_1fr] lg:gap-8">
            <div class="card p-5 sm:p-6">
                <span class="inline-flex rounded-full border border-negative/30 px-3 py-1 text-[10.5px] font-bold uppercase tracking-wide text-negative">
                    Before {{ config('opes.brand.name') }}
                </span>
                <ul class="mt-4 space-y-3">
                    @foreach ($before as $item)
                        <li class="flex items-center gap-2.5 text-[16px] text-ink-2 lg:text-[18.5px]">
                            <span class="flex size-4 shrink-0 items-center justify-center rounded-full bg-tint-red text-negative">
                                <svg viewBox="0 0 24 24" fill="none" class="size-[9px]" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                            </span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="flex items-center justify-center gap-3 lg:flex-col">
                <x-icon name="chevron-right" class="size-[22px] shrink-0 text-negative lg:hidden" stroke-width="2.5" />
                <svg viewBox="0 0 24 24" class="hidden size-6 shrink-0 -rotate-90 text-negative lg:block" fill="currentColor"><path d="M13.172 12 8.222 7.05a1 1 0 0 1 1.414-1.414l5.657 5.657a1 1 0 0 1 0 1.414l-5.657 5.657a1 1 0 0 1-1.414-1.414L13.172 12Z"/></svg>

                <div class="flex size-24 shrink-0 flex-col items-center justify-center rounded-full border-[3px] border-brand bg-surface text-center shadow-raised">
                    <span class="text-[14px] font-extrabold leading-none tracking-[-0.02em] text-ink">Opes</span>
                    <span class="mt-1 rounded-full border border-brand px-1.5 text-[10px] font-bold leading-[1.3] text-brand">360</span>
                </div>

                <svg viewBox="0 0 24 24" class="hidden size-6 shrink-0 -rotate-90 text-positive lg:block" fill="currentColor"><path d="M13.172 12 8.222 7.05a1 1 0 0 1 1.414-1.414l5.657 5.657a1 1 0 0 1 0 1.414l-5.657 5.657a1 1 0 0 1-1.414-1.414L13.172 12Z"/></svg>
                <x-icon name="chevron-right" class="size-[22px] shrink-0 text-positive lg:hidden" stroke-width="2.5" />
            </div>

            <div class="card p-5 sm:p-6">
                <span class="inline-flex rounded-full border border-positive/30 px-3 py-1 text-[10.5px] font-bold uppercase tracking-wide text-positive">
                    With {{ config('opes.brand.name') }}
                </span>
                <ul class="mt-4 space-y-3">
                    @foreach ($after as $item)
                        <li class="flex items-center gap-2.5 text-[16px] text-ink-2 lg:text-[18.5px]">
                            <x-icon name="check-circle" solid class="size-4 shrink-0 text-positive" />
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════ Everything needs ══ --}}
<section id="everything" class="scroll-mt-20 py-16 sm:py-20">
    <div class="mx-auto max-w-[1240px] px-5">
        <h2 class="text-center text-[22px] font-extrabold uppercase tracking-[-0.01em] text-ink sm:text-[27px]">
            Everything your business needs.
        </h2>
        <div class="mx-auto mt-3 h-[3px] w-14 rounded-full bg-brand"></div>

        <div class="mt-8 grid grid-cols-1 gap-3 sm:mt-10 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
            @foreach ($needs as $need)
                <div class="card flex items-start gap-3 p-4 text-left lg:flex-col lg:items-center lg:p-5 lg:text-center">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-fill-brand sm:size-14 lg:size-14">
                        <x-icon :name="$need['icon']" class="size-[18px] text-white sm:size-[22px]" stroke-width="1.8" />
                    </span>
                    <div class="min-w-0 lg:contents">
                        <h3 class="text-[16px] font-bold leading-tight text-ink lg:mt-4 lg:text-[18.5px]">{{ $need['title'] }}</h3>
                        <p class="mt-1 text-[16px] leading-relaxed text-muted sm:mt-1.5 lg:text-[18.5px]">{{ $need['body'] }}</p>
                        <a href="{{ route('marketing.features') }}" class="focusable mt-2 inline-flex items-center gap-1 text-[16px] font-semibold text-brand hover:underline sm:mt-3 lg:text-[18.5px]">
                            Learn more <x-icon name="chevron-right" class="size-[11px]" stroke-width="2.5" />
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-10 text-center">
            <a href="{{ route('marketing.features') }}"
               class="tap focusable inline-flex h-11 items-center justify-center gap-1.5 rounded-full border-2 border-brand px-6 text-[14px] font-semibold text-brand transition-colors hover:bg-tint-blue">
                Explore All Modules
                <x-icon name="chevron-right" class="size-[13px]" stroke-width="2.5" />
            </a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════ Our values ══ --}}
<section class="py-16 sm:py-20">
    <div class="mx-auto max-w-[1240px] px-5">
        <div class="rounded-3xl bg-[#0b2c93] px-6 py-10 sm:px-10 sm:py-12">
            <p class="flex items-center justify-center gap-3 text-center text-[13px] font-bold uppercase tracking-[0.1em] text-white">
                <span class="h-px w-6 bg-white/40"></span> Our Values <span class="h-px w-6 bg-white/40"></span>
            </p>

            <div class="mt-8 grid gap-x-6 gap-y-7 sm:grid-cols-2">
                @foreach ([
                    ['qr-code', 'Proof', 'Every document carries a QR that proves it is genuine — checkable months later, by anyone.'],
                    ['offline', 'Reliable', 'A weak signal is not a lost sale: invoices still issue correctly numbered when the connection drops.'],
                    ['banknotes', 'Local', 'Priced in FCFA, paid by mobile money — no card and no foreign-currency conversion to judge.'],
                    ['spark', 'Fast', 'No credit card, no long setup — register a business and issue the first invoice the same day.'],
                ] as [$icon, $title, $body])
                    <div class="flex items-start gap-3.5">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-white/70">
                            <x-icon :name="$icon" class="size-[19px] text-white" stroke-width="1.7" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[14.5px] font-bold text-white">{{ $title }}</p>
                            <p class="mt-1 text-[16px] leading-relaxed text-white/75 lg:text-[18.5px]">{{ $body }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════ One system / built for you ══ --}}
<section id="solutions" class="scroll-mt-20 border-y border-border bg-[#f7f8fb] dark:bg-surface-2 py-16 sm:py-20">
    <div class="mx-auto max-w-[1240px] px-5">
        <div class="grid items-start gap-14 lg:grid-cols-2 lg:gap-10">
            <div>
                <h2 class="text-[19px] font-extrabold uppercase leading-tight tracking-[-0.01em] text-ink sm:text-[22px]">
                    One system.<br>Your entire business.
                </h2>

                <div class="relative mx-auto mt-16 aspect-square w-full max-w-[260px] sm:mt-12 sm:max-w-[300px]" aria-hidden="true">
                    <div class="absolute inset-0" style="background-image: repeating-conic-gradient(from 0deg, transparent 0deg 29deg, var(--color-border) 29deg 30deg); border-radius: 9999px; mask: radial-gradient(closest-side, transparent 62%, #000 63%);"></div>

                    <div class="absolute left-1/2 top-1/2 flex size-24 -translate-x-1/2 -translate-y-1/2 flex-col items-center justify-center rounded-full bg-fill-brand text-center shadow-raised sm:size-28">
                        <span class="text-[13px] font-extrabold leading-none text-white sm:text-[15px]">Opes</span>
                        <span class="mt-1 text-[11px] font-bold leading-none text-white/90 sm:text-[13px]">360</span>
                    </div>

                    @foreach ($hub as $node)
                        <div class="absolute flex flex-col items-center gap-1.5" style="{{ $node['style'] }}">
                            <span class="flex size-9 items-center justify-center rounded-full bg-fill-brand shadow-card sm:size-11">
                                <x-icon :name="$node['icon'] === 'sales-cart' ? 'banknotes' : $node['icon']" class="size-[14px] text-white sm:size-[17px]" stroke-width="1.9" />
                            </span>
                            <span class="whitespace-nowrap text-[9px] font-semibold text-brand sm:text-[10.5px]">{{ $node['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <h2 class="text-[19px] font-extrabold uppercase leading-tight tracking-[-0.01em] text-ink sm:text-[22px]">
                    Built for where you are.<br>Ready for where you're going.
                </h2>

                <div class="mt-6 space-y-3">
                    @foreach ($tiers as $tier)
                        <div class="card flex items-start gap-3.5 p-4">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-tint-blue">
                                <x-icon :name="$tier['icon']" class="size-[19px] text-accent-blue" stroke-width="1.8" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[14px] font-bold text-brand">{{ $tier['name'] }}</p>
                                <p class="mt-0.5 text-[16px] leading-relaxed text-muted lg:text-[18.5px]">{{ $tier['body'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>

                <a href="{{ route('marketing.pricing') }}"
                   class="tap focusable mt-6 inline-flex h-11 items-center justify-center gap-1.5 rounded-full border-2 border-brand px-6 text-[14px] font-semibold text-brand transition-colors hover:bg-tint-blue">
                    Find the Right Solution
                    <x-icon name="chevron-right" class="size-[13px]" stroke-width="2.5" />
                </a>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════ Business Hub ══ --}}
<section class="py-16 sm:py-20">
    <div class="mx-auto max-w-[1240px] px-5">
        <div class="grid items-center gap-10 lg:grid-cols-12 lg:gap-8">
            <div class="lg:col-span-4">
                <h2 class="text-[20px] font-extrabold uppercase leading-tight tracking-[-0.01em] text-ink sm:text-[24px]">
                    Your business deserves<br>a digital home.
                </h2>
                <p class="mt-4 text-[16px] leading-relaxed text-muted lg:text-[18.5px]">
                    With {{ config('opes.brand.name') }} Business Hub, you get a professional digital presence to
                    showcase your business, products, services and connect with customers.
                </p>
                <a href="{{ route('demo.request') }}"
                   class="tap focusable mt-6 inline-flex h-11 items-center justify-center gap-1.5 rounded-full bg-fill-brand px-6 text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                    Explore Business Hub
                    <x-icon name="chevron-right" class="size-[13px]" stroke-width="2.5" />
                </a>
            </div>

            <div class="lg:col-span-8">
                <div class="overflow-hidden rounded-2xl border border-border bg-surface p-2 shadow-card sm:p-3">
                    <img src="{{ asset('images/marketing/business-hub-preview.png') }}"
                         alt="A business profile page built with {{ config('opes.brand.name') }} Business Hub, shown on desktop and mobile"
                         class="w-full rounded-xl" width="564" height="161">
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════ Partner programme ══ --}}
<section class="border-t border-border bg-[#f7f8fb] dark:bg-surface-2 py-16 sm:py-20">
    <div class="mx-auto max-w-[1240px] px-5">
        <h2 class="text-center text-[22px] font-extrabold uppercase tracking-[-0.01em] text-ink sm:text-[27px]">
            For secretariats and print shops.
        </h2>
        <div class="mx-auto mt-3 h-[3px] w-14 rounded-full bg-brand"></div>

        <div class="mt-10 grid items-center gap-8 lg:grid-cols-12 lg:gap-10">
            <div class="lg:col-span-6">
                <p class="text-[16px] leading-relaxed text-muted lg:text-[18.5px]">
                    You already make business cards, letterheads and contact cards for the businesses
                    around you. Make them on {{ config('opes.brand.name') }} instead, at
                    <span class="font-semibold text-ink-2">{{ $price($partnerCardFee) }} a card</span> — and when a
                    client you brought in signs up, you keep earning from it.
                </p>

                <ul class="mt-6 space-y-3">
                    @foreach ([
                        ['spark', $partnerPercent.'% of every subscription from a business you enrol, for as long as they stay.'],
                        ['briefcase', 'Issue cards and letterheads for clients who have no account of their own.'],
                        ['chart-bar', 'One page for clients, cards issued, commission earned and what you are owed.'],
                    ] as [$icon, $point])
                        <li class="flex items-start gap-3 text-[16px] leading-relaxed text-ink-2 lg:text-[18.5px]">
                            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-fill-brand">
                                <x-icon :name="$icon" class="size-[14px] text-white" stroke-width="2" />
                            </span>
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('marketing.partners') }}"
                   class="tap focusable mt-8 inline-flex h-11 items-center justify-center gap-1.5 rounded-full bg-fill-brand px-6 text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                    How the Programme Works
                    <x-icon name="chevron-right" class="size-[13px]" stroke-width="2.5" />
                </a>
            </div>

            {{-- A sample of the partner's own client list: the arithmetic reads
                 faster as three rows of a real book than as another paragraph.
                 Decorative, so it is hidden from assistive tech. --}}
            <div class="lg:col-span-6 lg:justify-self-end" aria-hidden="true">
                <div class="mx-auto w-full max-w-[380px] space-y-3">
                    @foreach ([
                        ['Boulangerie Nkolbisson', 'Signed up · Growth', '+'.$price(900).'/mo'],
                        ['Garage Akwa', '4 cards issued', $price(4 * $partnerCardFee)],
                        ['Coiffure Bépanda', 'Signed up · Basic', '+'.$price(300).'/mo'],
                    ] as [$client, $state, $amount])
                        <div class="card flex items-center gap-3 p-3.5">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-fill-brand text-[11.5px] font-bold text-white">
                                {{ collect(explode(' ', $client))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[13.5px] font-bold text-ink">{{ $client }}</p>
                                <p class="truncate text-[11.5px] text-faint">{{ $state }}</p>
                            </div>
                            <span class="tnum shrink-0 text-[12.5px] font-semibold text-positive">{{ $amount }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════ Industries ══ --}}
<section id="industries" class="scroll-mt-20 border-t border-border py-16 sm:py-20">
    <div class="mx-auto max-w-[1240px] px-5">
        <h2 class="text-center text-[20px] font-extrabold uppercase tracking-[-0.01em] text-ink sm:text-[24px]">
            One platform. Many industries.
        </h2>

        <div class="mt-10 grid grid-cols-3 gap-y-8 sm:grid-cols-5 lg:grid-cols-10">
            @foreach ($industries as $industry)
                <div class="flex flex-col items-center gap-2 text-center">
                    <x-icon :name="$industry['icon']" class="size-[26px] text-brand" stroke-width="1.6" />
                    <span class="text-[11.5px] font-semibold text-ink-2">{{ $industry['label'] }}</span>
                </div>
            @endforeach
        </div>

        <div class="mt-10 text-center">
            <a href="{{ route('marketing.features') }}"
               class="tap focusable inline-flex h-11 items-center justify-center gap-1.5 rounded-full border-2 border-brand px-6 text-[14px] font-semibold text-brand transition-colors hover:bg-tint-blue">
                Explore All Industries
                <x-icon name="chevron-right" class="size-[13px]" stroke-width="2.5" />
            </a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════ Stats band ══ --}}
<section class="bg-[#0b2c93] py-9">
    <div class="mx-auto max-w-[1240px] px-5">
        <p class="text-center text-[13px] font-bold uppercase tracking-[0.06em] text-white">Built to Help Businesses Work Better.</p>

        <div class="mt-7 grid grid-cols-2 gap-y-7 sm:grid-cols-4">
            @foreach ($stats as $stat)
                <div class="flex items-center justify-center gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-white/70">
                        <x-icon :name="$stat['icon']" class="size-[19px] text-white" stroke-width="1.7" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[22px] font-extrabold leading-none text-white">{{ $stat['figure'] }}</p>
                        <p class="mt-1 truncate text-[11px] font-medium text-white/75">{{ $stat['label'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════ Final CTA ══ --}}
<section class="bg-[#254bab] py-10 sm:py-12">
    <div class="mx-auto max-w-[1240px] px-5">
        <div class="flex flex-col items-center gap-6 text-center sm:flex-row sm:items-center sm:text-left">
            <span class="flex size-14 shrink-0 items-center justify-center rounded-full border-2 border-white/70">
                <x-icon name="spark" class="size-[24px] text-white" stroke-width="1.6" />
            </span>

            <div class="min-w-0 flex-1">
                <h2 class="text-[17px] font-extrabold uppercase tracking-[-0.01em] text-white sm:text-[19px]">
                    Ready to Run Your Business Differently?
                </h2>
                <p class="mt-1 text-[16px] leading-relaxed text-white/80 lg:text-[18.5px]">
                    Bring your entire business together with {{ config('opes.brand.name') }} and focus on what truly matters — growth.
                </p>
            </div>

            <div class="flex shrink-0 flex-col gap-3 sm:flex-row">
                <a href="{{ route('register') }}"
                   class="tap focusable flex h-11 items-center justify-center gap-1.5 rounded-full bg-white px-5 text-[13.5px] font-semibold text-brand transition-opacity hover:opacity-90">
                    Get Started Free
                    <x-icon name="chevron-right" class="size-[13px]" stroke-width="2.5" />
                </a>
                <a href="{{ route('demo.request') }}"
                   class="tap focusable flex h-11 items-center justify-center gap-1.5 rounded-full border-2 border-white px-5 text-[13.5px] font-semibold text-white transition-colors hover:bg-white/10">
                    Book a Demo
                    <x-icon name="calendar" class="size-[13px]" />
                </a>
            </div>
        </div>
    </div>
</section>

{{-- ═════════════════════════════════════════════════════════ Quick contact ══ --}}
<section class="px-5 pb-16">
    <div class="mx-auto max-w-[1240px]">
        <div class="card flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-center sm:gap-x-8 sm:gap-y-3 sm:p-5">
            <a href="tel:{{ preg_replace('/\s+/', '', (string) config('opes.contact.phone')) }}" class="focusable flex items-center gap-2.5 text-[13.5px] font-semibold text-ink-2 hover:text-brand">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-fill-brand"><x-icon name="bell" class="size-[14px] text-white" /></span>
                {{ config('opes.contact.phone') }}
            </a>
            <span class="hidden h-6 w-px bg-border sm:block"></span>
            <a href="mailto:{{ config('opes.contact.support_email') }}" class="focusable flex items-center gap-2.5 text-[13.5px] font-semibold text-ink-2 hover:text-brand">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-fill-brand"><x-icon name="document" class="size-[14px] text-white" /></span>
                {{ config('opes.contact.support_email') }}
            </a>
            <span class="hidden h-6 w-px bg-border sm:block"></span>
            <a href="{{ url('/') }}" class="focusable flex items-center gap-2.5 text-[13.5px] font-semibold text-ink-2 hover:text-brand">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-fill-brand"><x-icon name="cube" class="size-[14px] text-white" /></span>
                {{ request()->getHost() }}
            </a>
        </div>
    </div>
</section>

</main>

{{-- ═══════════════════════════════════════════════════════════════ Footer ══ --}}
<footer class="border-t border-border bg-canvas">
    <div class="mx-auto max-w-[1240px] px-5 py-14">
        <div class="grid grid-cols-2 gap-8 sm:grid-cols-3 lg:grid-cols-6">
            <div class="col-span-2 sm:col-span-3 lg:col-span-1">
                <img src="{{ asset('images/brand/opes360-logo.png') }}" alt="{{ config('opes.brand.name') }}"
                     class="h-6 w-auto" width="1200" height="352">
                <p class="mt-3 max-w-[200px] text-[12.5px] leading-relaxed text-muted">
                    The all-in-one ERP platform that helps businesses of all sizes operate, automate and grow.
                </p>
                <div class="mt-4 flex gap-2">
                    @foreach (['facebook', 'linkedin', 'twitter', 'instagram'] as $network)
                        <span class="flex size-7 items-center justify-center rounded-full bg-tint-blue text-accent-blue">
                            <x-icon name="{{ $network === 'facebook' ? 'sales' : ($network === 'linkedin' ? 'briefcase' : ($network === 'twitter' ? 'spark' : 'cube')) }}" class="size-[13px]" stroke-width="1.8" />
                        </span>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="text-[12px] font-bold uppercase tracking-wide text-brand">Platform</p>
                <nav class="mt-3 flex flex-col text-[13px] font-medium text-ink-2">
                    <a href="{{ route('dashboard') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Overview</a>
                    <a href="{{ route('marketing.features') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Modules</a>
                    <a href="#top" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Business Hub</a>
                    <a href="{{ route('marketing.features') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Integrations</a>
                    <a href="{{ route('marketing.pricing') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Pricing</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-bold uppercase tracking-wide text-brand">Solutions</p>
                <nav class="mt-3 flex flex-col text-[13px] font-medium text-ink-2">
                    <a href="#solutions" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Small Business</a>
                    <a href="#solutions" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Growing Business</a>
                    <a href="#solutions" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Enterprise</a>
                    <a href="#industries" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Industries</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-bold uppercase tracking-wide text-brand">Company</p>
                <nav class="mt-3 flex flex-col text-[13px] font-medium text-ink-2">
                    <a href="{{ route('marketing.about') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">About Us</a>
                    <a href="{{ route('marketing.contact') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Careers</a>
                    <a href="{{ route('marketing.partners') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Partners</a>
                    <a href="{{ route('marketing.blog') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">News & Press</a>
                    <a href="{{ route('marketing.contact') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Contact Us</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-bold uppercase tracking-wide text-brand">Resources</p>
                <nav class="mt-3 flex flex-col text-[13px] font-medium text-ink-2">
                    <a href="{{ route('help') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Documentation</a>
                    <a href="{{ route('help') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Help Center</a>
                    <a href="{{ route('marketing.blog') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Blog</a>
                    <a href="{{ route('marketing.blog') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Videos</a>
                    <a href="{{ route('help') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">FAQs</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-bold uppercase tracking-wide text-brand">Contact Us</p>
                <nav class="mt-3 flex flex-col gap-2.5 text-[13px] font-medium text-ink-2">
                    <a href="tel:{{ preg_replace('/\s+/', '', (string) config('opes.contact.phone')) }}" class="focusable -mx-1 flex items-center gap-2 rounded px-1 py-1 hover:text-brand">
                        <x-icon name="bell" class="size-[13px] shrink-0 text-brand" /> {{ config('opes.contact.phone') }}
                    </a>
                    <a href="mailto:{{ config('opes.contact.recipient') }}" class="focusable -mx-1 flex items-center gap-2 rounded px-1 py-1 hover:text-brand">
                        <x-icon name="document" class="size-[13px] shrink-0 text-brand" /> {{ config('opes.contact.recipient') }}
                    </a>
                    <span class="flex items-start gap-2 px-1 py-1">
                        <x-icon name="home" class="size-[13px] shrink-0 text-brand" />
                        {{ config('opes.contact.address') }}
                    </span>
                </nav>
            </div>
        </div>

        <div class="mt-12 flex flex-col items-center justify-between gap-3 border-t border-border pt-6 sm:flex-row">
            <p class="text-[12.5px] text-muted">&copy; {{ now()->year }} {{ config('opes.brand.name') }}. All rights reserved.</p>
            <nav class="flex items-center gap-4 text-[12.5px] font-medium text-ink-2">
                <a href="{{ route('marketing.privacy') }}" class="focusable hover:text-brand">Privacy Policy</a>
                <a href="{{ route('marketing.terms') }}" class="focusable hover:text-brand">Terms of Service</a>
            </nav>
        </div>
    </div>
</footer>

</x-layouts.public>
