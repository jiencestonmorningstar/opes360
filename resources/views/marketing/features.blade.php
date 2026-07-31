<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Features · {{ config('opes.brand.name') }}</title>

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
        <h1 class="text-[30px] font-bold tracking-[-0.02em] text-ink sm:text-[36px]">Every module, in one suite</h1>
        <p class="mt-4 text-[15.5px] leading-relaxed text-muted">
            {{ config('opes.brand.name') }} covers the whole business day — from a quotation to a paid,
            verified receipt — without switching apps.
        </p>
    </section>

    <section class="mx-auto max-w-6xl px-5 py-12 sm:py-16">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['icon' => 'document-plus', 'title' => 'Sales & Invoicing', 'body' => 'Quotations, invoices and receipts — numbered, issued and tracked from first draft to paid.'],
                ['icon' => 'document', 'title' => 'Proforma & Sales Documents', 'body' => 'Proformas and other sales documents that convert straight into an invoice once accepted.'],
                ['icon' => 'cube', 'title' => 'Inventory', 'body' => 'Products and stock levels that move automatically as sales go out, with adjustments logged.'],
                ['icon' => 'users', 'title' => 'Customers & CRM', 'body' => 'A record and balance for every customer, plus the full document history behind it.'],
                ['icon' => 'receipt', 'title' => 'Statement of Account', 'body' => 'A shareable, printable statement of every invoice, receipt and balance for a customer.'],
                ['icon' => 'briefcase', 'title' => 'Business Documents & Letters', 'body' => 'Contracts, letters and certificates generated from a library of ready-made templates.'],
                ['icon' => 'qr-code', 'title' => 'QR Verification', 'body' => 'Every document and receipt carries a QR that proves it is genuine, scannable offline or on.'],
                ['icon' => 'wallet', 'title' => 'Payments & Receipts', 'body' => 'Record payments against any invoice and issue receipts the moment money lands.'],
                ['icon' => 'chart-bar', 'title' => 'Reports', 'body' => 'Sales, customer and product reports, exportable to CSV whenever they are needed.'],
                ['icon' => 'briefcase', 'title' => 'Business Card & Letterhead', 'body' => 'Branded stationery — letterhead, business card and stamp — generated from your profile.'],
                ['icon' => 'offline', 'title' => 'Offline-first', 'body' => 'Keep working with no connection; changes sync the moment the device is back online.'],
                ['icon' => 'calendar', 'title' => 'Calendar', 'body' => 'A day-by-day view of what has shipped, what is due, and what still needs following up.'],
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
    </section>

    <section class="border-t border-border py-16 text-center sm:py-20">
        <h2 class="text-[24px] font-bold tracking-[-0.02em] text-ink sm:text-[28px]">Ready to get started?</h2>
        <p class="mx-auto mt-3 max-w-md text-[14.5px] text-muted">Set up your business in a few minutes — no card required.</p>
        <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a href="{{ route('register') }}"
               class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                Create your business
            </a>
            <a href="{{ route('marketing.pricing') }}"
               class="tap focusable flex h-12 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
                See pricing
            </a>
        </div>
    </section>
</main>

@include('marketing.partials.footer')

</body>
</html>
