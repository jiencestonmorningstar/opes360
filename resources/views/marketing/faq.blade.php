@php
    /*
     * Every answer here is grounded in a shipped feature — config/modules.php,
     * resources/guides or docs/GAP-ANALYSIS.md. Where the honest answer is
     * "not yet", the page says so: in this market, honesty sells.
     */
    $sections = [
        [
            'title' => 'Fit',
            'items' => [
                ['Which businesses is Opes360 for?',
                 'Small and medium businesses in Cameroon and the wider OHADA / francophone-African space: shops and traders, secretariats and print bureaus, service firms and workshops, clinics and pharmacies, schools, professional practices, NGOs and project-driven organisations, and businesses with technicians in the field. The accounting is SYSCOHADA, payroll knows CNPS and IRPP, invoices carry Cameroonian TVA, and the price is in FCFA paid by mobile money.'],
                ['Who is it not for?',
                 'Not yet for heavy manufacturers — there are no bills of materials or production planning — and not for large groups wanting deep multi-entity consolidation. We would rather say that here than after you have moved your data in.'],
                ['Do I need an accountant to use it?',
                 'No. You issue invoices, record payments and log expenses in plain business language, and the double-entry bookkeeping happens underneath. When your accountant does ask, the SYSCOHADA journals, ledgers, financial statements and CSV exports are there waiting for them.'],
                ['I run a small shop. Will I be drowned in screens I never use?',
                 'No. Every module beyond the account itself can be switched off per business under Settings. A hairdresser does not need a fixed asset register, so she switches it off and it disappears from her navigation.'],
            ],
        ],
        [
            'title' => 'Everyday use',
            'items' => [
                ['Does it work offline?',
                 'Yes, by design. Install it like an app on a phone or computer. Invoice numbers are leased to your device in advance, so when the connection drops you keep issuing correctly numbered invoices and receipts, and everything syncs when the signal returns.'],
                ['Does it work on a phone?',
                 'Yes — it is built phone-first, for use between customers. There is no separate app to download from a store: it installs from the browser and works like one.'],
                ['What happens when I turn a module off?',
                 'The screens disappear and its abilities are denied everywhere at once — navigation, quick actions and direct links all go quiet together. The data is kept, untouched. Switch the module back on and everything is exactly where you left it.'],
                ['Can my customers reach me without logging in?',
                 'In several ways, all without an account: a walk-in can scan your printed QR to join the service queue from their own phone; anyone can fill a form you share or embed on your website; candidates apply through a public job advert page; a signer can sign a document through a secure link; and anyone holding one of your documents can verify it by its QR.'],
                ['Can it send SMS or WhatsApp messages?',
                 'Honestly: not yet. Notifications today are email and in-app, with quiet hours, digests and per-user rules. SMS and WhatsApp need a paid gateway we have chosen not to bolt on half-heartedly; the plumbing is ready for the day one is added.'],
            ],
        ],
        [
            'title' => 'Control & trust',
            'items' => [
                ['Who can approve things?',
                 'Whoever the workflow names — a role, a department\'s manager, a named person, the owner. Being asked is the permission: there is no separate "can approve" setting, and even an administrator cannot approve something the workflow did not send them. If a step names a role nobody holds, the approval stalls visibly rather than passing quietly.'],
                ['Can one person create a bill and also pay it?',
                 'Not without you choosing that. Building a payment run and approving it are separate permissions, and the Governance screen reports combinations that should not sit together — like one person able to both enter a supplier bill and pay it.'],
                ['How long is the audit trail kept?',
                 'You set the retention in months, but there are floors it cannot undercut: anything touching money, every export and every permission change is kept at least ten years, in line with OHADA-tradition bookkeeping expectations. The trail cannot be switched off — not even by the owner.'],
                ['Is my data separated from other businesses?',
                 'Yes. Every record belongs to your business and every query is scoped to it. Other businesses on the platform cannot see your customers, your prices or your books, and staff only see what their role and your module switches allow.'],
                ['Can anyone check that a document I issued is genuine?',
                 'Yes — every invoice, receipt and certificate carries a QR that opens a verification page. Whoever is holding the paper can check it months later, with no account.'],
            ],
        ],
        [
            'title' => 'Getting started',
            'items' => [
                ['What does it cost?',
                 'Plans are priced in FCFA and paid monthly or annually by MTN or Orange Money — see the pricing page for the current tiers. There is no card to enter and no foreign-currency step.'],
                ['How long does setup take?',
                 'Register the business, add a customer and issue an invoice — about ten minutes. Every business starts with sensible defaults, including five ready-made approval paths, and prunes from there.'],
                ['I run a secretariat. Is there something for me?',
                 'Yes — the partner programme. Issue business cards and letterheads for your clients on Opes360, and earn a commission on every business you enrol for as long as they stay. See the partners page.'],
            ],
        ],
    ];
@endphp

<x-layouts.marketing title="FAQ"
                     description="Straight answers about Opes360: who it is for, offline use, module switches, approvals, the audit trail, pricing — and what it does not do yet.">

<x-marketing.page-header eyebrow="FAQ" title="Straight answers"
    lead="What businesses ask before they sign up — answered plainly, including the questions whose honest answer is &quot;not yet&quot;." />

<section class="mx-auto max-w-3xl px-5 py-14 sm:py-18">
    @foreach ($sections as $section)
        <div @class(['mt-12' => ! $loop->first])>
            <h2 class="text-[13px] font-semibold uppercase tracking-[0.08em] text-brand">{{ $section['title'] }}</h2>

            <div class="mt-4 space-y-3">
                @foreach ($section['items'] as [$question, $answer])
                    <details class="card group p-0">
                        <summary class="focusable flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 [&::-webkit-details-marker]:hidden">
                            <h3 class="text-[15px] font-semibold text-ink">{{ $question }}</h3>
                            <x-icon name="chevron-down" class="size-[16px] shrink-0 text-faint transition-transform group-open:rotate-180" stroke-width="2.2" />
                        </summary>
                        <p class="border-t border-border px-5 py-4 text-[14px] leading-relaxed text-muted">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="mt-12 rounded-2xl border border-border bg-surface-2/60 p-6 text-center">
        <p class="text-[15px] font-semibold text-ink">Asked something we have not answered?</p>
        <p class="mt-1.5 text-[14px] text-muted">
            Write to us through the <a href="{{ route('marketing.contact') }}" class="font-semibold text-brand hover:underline">contact page</a> —
            we answer it ourselves — or see the
            <a href="{{ route('marketing.pricing') }}" class="font-semibold text-brand hover:underline">pricing page</a> for the current plans.
        </p>
    </div>
</section>

<x-marketing.cta secondary="pricing" />

</x-layouts.marketing>
