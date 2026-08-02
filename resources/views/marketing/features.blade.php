@php
    /*
     * Grouped by the part of the day they belong to rather than listed flat.
     * Twelve identical cards told a visitor how many modules there are and
     * nothing about how they fit together, which is the actual question.
     */
    $groups = [
        [
            'eyebrow' => 'Selling',
            'title' => 'From the first quotation to the money in hand',
            'accent' => 'blue',
            'modules' => [
                ['document-plus', 'Sales & invoicing', 'Quotations, proforma, invoices and receipts — numbered in sequence, issued in a few taps, tracked from first draft to paid.'],
                ['users', 'Customers & CRM', 'A record and running balance for every customer, with the full document history behind it.'],
                ['receipt', 'Statement of account', 'A shareable, printable statement of every invoice, payment and balance — the answer to "what do I owe you".'],
                ['banknotes', 'Payments & receipts', 'Record a payment against any invoice and issue the receipt while the customer is still standing there.'],
                ['cube', 'Inventory', 'Stock that moves as sales go out the door, with every manual adjustment logged and attributable.'],
                ['cube', 'Multiple stock locations', 'A shop, a store room and a van, each counted separately, with transfers between them that never change the total.'],
                ['spark', 'Loyalty program', 'Customers earn points automatically and carry a printed card whose QR proves the balance.'],
            ],
        ],
        [
            'eyebrow' => 'Proving',
            'title' => 'Documents anyone holding them can check',
            'accent' => 'teal',
            'modules' => [
                ['qr-code', 'QR verification', 'Every invoice, receipt and contract carries a QR that opens a verification page on your own domain.'],
                ['document', 'Business documents', '26 templates — contracts, letters, payslips, certificates — generated on your letterhead and numbered like any other document.'],
                ['briefcase', 'Cards & letterheads', '98 business-card designs organised by trade, plus letterheads and a stamp, all generated from your business profile.'],
                ['check-circle', 'Public profile & reviews', 'A verified page customers can find, review, and save your contact details from.'],
            ],
        ],
        [
            'eyebrow' => 'Keeping the books',
            'title' => 'Compliant here, not merely tidy',
            'accent' => 'green',
            'modules' => [
                ['wallet', 'SYSCOHADA accounting', 'A real double-entry journal, ledgers and financial statements on the OHADA chart of accounts.'],
                ['banknotes', 'Purchases & expenses', 'Supplier bills and day-to-day spending, with the TVA you can reclaim kept separate from the cost.'],
                ['briefcase', 'Fixed assets', 'A van is not an expense. Its cost is spread over the years you use it, which is also how the DGI sees it.'],
                ['credit-card', 'Bank reconciliation', 'Import the statement, match it against the books, and find out which of the two balances is telling the truth.'],
                ['chart-bar', 'Reports', 'Sales, customer and product reports, exportable to CSV whenever your accountant asks.'],
                ['document-plus', 'DGI-compliant invoicing', 'TVA computed at the rate you are registered for, with the mandatory mentions printed on every document.'],
                ['calendar', 'Calendar', 'What shipped, what is due, and what still needs following up — day by day.'],
            ],
        ],
        [
            'eyebrow' => 'Paying people',
            'title' => 'Payroll that knows where it is',
            'accent' => 'purple',
            'modules' => [
                ['users', 'Team & HR', 'Staff records, contracts and allowances. Nobody has to be given a login to be paid.'],
                ['banknotes', 'Payroll', 'Monthly payslips with CNPS, IRPP and the centimes additionnels worked out — and a straight answer to what your staff actually cost.'],
                ['printer', 'Bulletins de paie', 'A proper French payslip, itemised line by line, with the employer’s charges shown beside what was withheld.'],
                ['clock', 'Leave', 'Requests, approvals and a running balance that accrues from the hire date.'],
            ],
        ],
        [
            'eyebrow' => 'Reaching people',
            'title' => 'The parts that happen outside the shop',
            'accent' => 'orange',
            'modules' => [
                ['clipboard', 'Opes Forms', 'Build a form, share the link or embed it on your own website, and watch responses arrive.'],
                ['ticket', 'Opes Events', 'Sell tickets with QR check-in at the door. The count is enforced, so an event cannot oversell.'],
                ['bell', 'Notifications', 'Email and in-app alerts for payments, low stock, reviews and renewals — no external service in the way.'],
                ['offline', 'Offline mode', 'Install it like an app. Numbers are leased to the device in advance, so it keeps issuing through an outage.'],
            ],
        ],
    ];
@endphp

<x-layouts.marketing title="Features"
                     description="Every module in Opes360: sales and invoicing, verified documents, SYSCOHADA accounting, forms, events and offline mode.">

<x-marketing.page-header eyebrow="Features" title="Every module, in one suite"
    lead="Opes360 covers the whole business day — from a quotation to a paid, verified receipt — without switching apps or paying for an add-on." />

@foreach ($groups as $index => $group)
    @php
        // Written out rather than interpolated: Tailwind scans source text, so
        // text-accent-{$x} would never be generated.
        $eyebrowClass = ['blue' => 'text-accent-blue', 'teal' => 'text-accent-teal', 'green' => 'text-accent-green', 'purple' => 'text-accent-purple', 'orange' => 'text-accent-orange'][$group['accent']];
        $tintClass = ['blue' => 'bg-tint-blue', 'teal' => 'bg-tint-teal', 'green' => 'bg-tint-green', 'purple' => 'bg-tint-purple', 'orange' => 'bg-tint-orange'][$group['accent']];
    @endphp

    <section class="py-14 sm:py-18 {{ $index % 2 === 1 ? 'border-y border-border bg-surface-2/60' : '' }}">
        <div class="mx-auto max-w-6xl px-5">
            <div class="max-w-2xl">
                <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] {{ $eyebrowClass }}">{{ $group['eyebrow'] }}</p>
                <h2 class="mt-3 text-[24px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[30px]">{{ $group['title'] }}</h2>
            </div>

            <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($group['modules'] as [$icon, $title, $body])
                    <div class="card p-5">
                        <span class="flex size-10 items-center justify-center rounded-lg {{ $tintClass }}">
                            <x-icon :name="$icon" class="size-[19px] {{ $eyebrowClass }}" stroke-width="1.9" />
                        </span>
                        <h3 class="mt-3.5 text-[15.5px] font-semibold text-ink">{{ $title }}</h3>
                        <p class="mt-1.5 text-[13.5px] leading-relaxed text-muted">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endforeach

<x-marketing.cta secondary="pricing" />

</x-layouts.marketing>
