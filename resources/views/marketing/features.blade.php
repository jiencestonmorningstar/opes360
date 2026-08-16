@php
    /*
     * Grouped by the part of running a business each module serves — the money,
     * the selling, the people, the things, the obligations, the working
     * together — rather than by how the code is organised. Every sentence here
     * is grounded in a module in config/modules.php or a guide in
     * resources/guides; nothing is aspirational.
     */
    $groups = [
        [
            'eyebrow' => 'Selling',
            'title' => 'From the first enquiry to the money in hand',
            'accent' => 'blue',
            'modules' => [
                ['document-plus', 'Sales & invoicing', 'Quotations, proformas, invoices and receipts — numbered in sequence, issued in a few taps, and tracked from first draft to paid.'],
                ['trending-up', 'Sales pipeline', 'Leads and deals in progress, from first enquiry to the invoice — with a plain answer to which deals nobody has touched lately.'],
                ['users', 'Customers & suppliers', 'A record and running balance for everyone you trade with, and a shareable statement that answers "what do I owe you".'],
                ['cube', 'Products & stock', 'What you sell, what it costs, and how much is left — with lot and expiry tracking for the businesses that need it, and none of it for the ones that do not.'],
                ['cube', 'Multiple stock locations', 'A shop, a store room and a van, each counted separately, with transfers between them that never change the total.'],
                ['spark', 'Loyalty & VIP membership', 'Points and printed cards for repeat customers, and paid membership tiers that discount an invoice for the term the member bought.'],
                ['ticket', 'Events & ticketing', 'Sell tickets and scan them at the door. The count is enforced, so an event cannot oversell.'],
            ],
        ],
        [
            'eyebrow' => 'The money',
            'title' => 'Books the DGI recognises, not merely tidy ones',
            'accent' => 'green',
            'modules' => [
                ['wallet', 'SYSCOHADA accounting', 'A real double-entry journal, ledgers and financial statements on the OHADA chart of accounts, with fiscal periods you can close and reopen deliberately.'],
                ['banknotes', 'Purchases & expenses', 'Supplier bills and day-to-day spending, with the TVA you can reclaim kept separate from the cost.'],
                ['credit-card', 'Bank reconciliation', 'Import the statement, match it against the books, and find out which of the two balances is telling the truth.'],
                ['calendar', 'Payment scheduling', 'Decide which bills to pay this week. Building the run and approving it are different permissions, so one person cannot invent a supplier and pay them.'],
                ['clipboard', 'Requisitions & sourcing', 'Ask before buying: requisitions, quotation requests, and side-by-side supplier comparison — with an approval threshold set per currency, so "over the limit needs the director" is a rule, not a habit.'],
                ['chart-bar', 'Reports', 'Sales, customers and stock summarised over a period, exportable to CSV whenever your accountant asks.'],
            ],
        ],
        [
            'eyebrow' => 'The people',
            'title' => 'From advert to employee to payslip',
            'accent' => 'purple',
            'modules' => [
                ['users', 'Team & HR', 'Staff records, employment contracts, allowances, attendance and leave. Nobody has to be given a login to be paid.'],
                ['banknotes', 'Payroll', 'Monthly payslips with CNPS, IRPP and the employer\'s own charges worked out — a proper French bulletin de paie, itemised line by line.'],
                ['clock', 'Leave & reviews', 'Requests, approvals and a running leave balance — and performance reviews that freeze once acknowledged, so what was signed stays what was signed.'],
                ['user-plus', 'Recruitment', 'Vacancies, applications, interviews and offers, with a public advert page candidates apply through. Hiring creates a real employee record — nothing retyped.'],
            ],
        ],
        [
            'eyebrow' => 'The things',
            'title' => 'What the business owns, and where it is',
            'accent' => 'orange',
            'modules' => [
                ['briefcase', 'Fixed assets', 'A van is not an expense. Its cost is spread over the years you use it, which is also how the DGI sees it.'],
                ['home', 'Locations & custodians', 'Every asset has a place and a person responsible, and every transfer is written into a history that cannot be quietly rewritten.'],
                ['cog', 'Maintenance', 'Servicing that repeats — with the next occurrence counted from the day the work was done, not the day it was due.'],
                ['truck', 'Fleet', 'Vehicle papers, trips, fuel logs, and servicing scheduled by distance as well as by date, with alerts before the paperwork expires.'],
            ],
        ],
        [
            'eyebrow' => 'The obligations',
            'title' => 'Deadlines met, and the proof kept',
            'accent' => 'teal',
            'modules' => [
                ['document', 'Contracts', 'Agreements with customers and suppliers, and the notice date before one renews itself — watched, so the last day you can serve notice does not pass unremarked.'],
                ['shield', 'Compliance & risk', 'Statutory deadlines, the evidence they were met, and a register of what could go wrong.'],
                ['document', 'Documents', 'Contracts, letters and certificates generated from templates on your letterhead — versioned, foldered, and kept under retention rules you set.'],
                ['qr-code', 'QR verification', 'Every invoice, receipt and certificate carries a QR that opens a verification page on your own domain — checkable months later, by someone with no account.'],
                ['shield', 'Audit trail', 'Who changed what, when, and from what to what. Anything touching money is kept for ten years, and the trail cannot be switched off — not even by the owner.'],
            ],
        ],
        [
            'eyebrow' => 'Working together',
            'title' => 'Approvals, alerts and the world outside the shop',
            'accent' => 'blue',
            'modules' => [
                ['check-circle', 'Approval workflows', 'One approval system for the whole product. Being asked is the permission: if the workflow names you, you can act — and if nobody can fill a step, it stalls visibly rather than passing quietly.'],
                ['briefcase', 'Projects', 'Chargeable and internal work: tasks, milestones, time and cost against a budget.'],
                ['cog', 'Service desk', 'Customer tickets, the visits that resolve them, and what you have promised about response times. A walk-in can scan your printed QR and join the queue from their own phone — no account, no app.'],
                ['clipboard', 'Forms', 'Build a form, share the link or embed it on your own website, and watch responses arrive.'],
                ['bell', 'Notifications', 'Email and in-app alerts with quiet hours, digests and per-user rules — and a delivery log, so "I was never told" is answerable.'],
                ['offline', 'Offline mode', 'Install it like an app. Invoice numbers are leased to the device in advance, so it keeps issuing correctly numbered documents through an outage.'],
                ['cog', 'Module switches', 'A hairdresser does not need a fixed asset register. Every module can be switched off per business — the screens go quiet, and the data waits untouched for the day you switch it back on.'],
            ],
        ],
    ];
@endphp

<x-layouts.marketing title="Features"
                     description="Every module in Opes360: sales and invoicing, SYSCOHADA accounting, payroll, contracts, compliance, approvals, service desk and offline mode — each one switchable per business.">

<x-marketing.page-header eyebrow="Features" title="Every module, in one suite"
    lead="Opes360 covers the whole business day — selling, the books, the people, the things you own and the deadlines you owe — and every module beyond the account itself can be switched off, so you only see the screens your business actually uses." />

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

{{-- Saying who it is not for is a claim of honesty the rest of the page can
     borrow from. Grounded in docs/GAP-ANALYSIS.md: manufacturing and supply
     chain are explicitly not built. --}}
<section class="border-t border-border py-14 sm:py-18">
    <div class="mx-auto max-w-6xl px-5">
        <div class="max-w-2xl">
            <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-brand">Honestly</p>
            <h2 class="mt-3 text-[24px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[30px]">What it does not do yet</h2>
            <p class="mt-4 text-[15px] leading-relaxed text-muted">
                Opes360 is built for small and medium businesses in Cameroon and the wider OHADA space —
                shops and traders, secretariats, service firms and workshops, clinics and pharmacies,
                schools, professional practices, NGOs and businesses with technicians in the field.
                It is not yet the right tool for a manufacturer who needs bills of materials and
                production planning, or for a large group wanting deep multi-entity consolidation.
                If that is you, we would rather say so here than after you have moved your data in.
            </p>
        </div>
    </div>
</section>

<x-marketing.cta secondary="pricing" />

</x-layouts.marketing>
