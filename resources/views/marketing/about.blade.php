<x-layouts.marketing title="About"
                     description="Opes360 is built by Opesware Technologies for small and medium businesses in Cameroon and the wider OHADA space that need sales, accounting, payroll and records in one place — including when the connection fails.">

<x-marketing.page-header eyebrow="About" title="Built here, for how business is actually done here"
    :lead="'Opes360 is a product of '.config('opes.brand.vendor').', for small and medium businesses that need sales, invoicing, the books and their people in one place — including in the moments the connection does not cooperate.'" />

{{-- Beliefs, each shown with the design decision it produced. A principle
     with nothing built on it is decoration. --}}
<section class="mx-auto max-w-6xl px-5 py-14 sm:py-20">
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([
            ['qr-code', 'teal', 'A document should prove itself',
             'A printed invoice is only worth what the person holding it believes. So every document carries a QR that opens a verification page on your own domain — checkable months later, by someone with no account.'],
            ['offline', 'orange', 'A weak signal is not a lost sale',
             'Invoice numbers are leased to the device before they are needed, so a phone with no connection still issues correctly numbered documents. That is harder to build than a warning banner, and it is the right thing to build.'],
            ['banknotes', 'green', 'Priced in the money you hold',
             'Quoted in FCFA, paid by MTN or Orange Money, with no card and no foreign-currency step. A price you have to convert before you can judge it is not an honest price.'],
            ['cube', 'blue', 'One of everything, extended',
             'There is one invoice generator, one approval engine, one audit trail, one numbering ledger. New features extend what exists rather than duplicating it — which is why the service desk bills through an ordinary invoice and a fleet is an extension of the asset register, not a second one.'],
            ['shield', 'purple', 'Controls that are real',
             'Building a payment run and approving it are different permissions held by different people. An approval whose approver has left stalls visibly rather than passing quietly. And the audit trail cannot be switched off — not even by the owner, who is exactly the person a switch would tempt.'],
            ['cog', 'teal', 'Only the screens you use',
             'A hairdresser has no fixed asset register and a consultancy has no stock, so every module can be switched off per business. The screens disappear; the data stays, waiting for the day you switch it back on.'],
        ] as [$icon, $accent, $title, $body])
            @php
                $inkClass = ['teal' => 'text-accent-teal', 'orange' => 'text-accent-orange', 'green' => 'text-accent-green', 'blue' => 'text-accent-blue', 'purple' => 'text-accent-purple'][$accent];
                $tintClass = ['teal' => 'bg-tint-teal', 'orange' => 'bg-tint-orange', 'green' => 'bg-tint-green', 'blue' => 'bg-tint-blue', 'purple' => 'bg-tint-purple'][$accent];
            @endphp
            <div class="card p-6">
                <span class="flex size-11 items-center justify-center rounded-xl {{ $tintClass }}">
                    <x-icon :name="$icon" class="size-[20px] {{ $inkClass }}" stroke-width="1.9" />
                </span>
                <h2 class="mt-5 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $title }}</h2>
                <p class="mt-2.5 text-[14.5px] leading-relaxed text-muted">{{ $body }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- Who it is for — and who it is not for yet. Each sector claim is backed by
     a shipped feature, and the honesty about manufacturers is deliberate. --}}
<section class="border-y border-border bg-surface-2/60 py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-5">
        <div class="max-w-2xl">
            <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-brand">Who it is for</p>
            <h2 class="mt-3 text-[24px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[30px]">
                Small and medium businesses in Cameroon and the OHADA space
            </h2>
            <p class="mt-4 text-[15px] leading-relaxed text-muted">
                The accounting is SYSCOHADA. The payroll knows CNPS and IRPP. The invoices carry
                Cameroonian TVA. The price is in FCFA and paid by mobile money. None of that is a
                translation layer over foreign software — it is what the product is made of.
            </p>
        </div>

        <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['sales', 'Shops & traders', 'Invoices and receipts issued between customers, stock counted across a shop and a store room, and points on a printed loyalty card.'],
                ['printer', 'Secretariats & print bureaus', 'The partner programme is built for you: issue cards and letterheads for your clients, and earn commission on every business you enrol.'],
                ['cog', 'Service firms & workshops', 'Tickets, the visits that resolve them, promised response times — and a printed QR a walk-in can scan to join the queue from their own phone.'],
                ['heart', 'Clinics & pharmacies', 'Lot and expiry tracking with first-expired-first-out picking, so what leaves the shelf is what should leave the shelf.'],
                ['academic-cap', 'Schools & professional practices', 'Chargeable work tracked against a budget, documents generated on your letterhead, and a staff room paid through real payroll.'],
                ['briefcase', 'NGOs & project-driven organisations', 'Projects with milestones, time and cost, requisitions that ask before money is spent, and an audit trail funders can rely on.'],
                ['truck', 'Businesses with people in the field', 'A fleet with trips, fuel logs and distance-based servicing, tied to the service desk that sends the technicians out.'],
            ] as [$icon, $title, $body])
                <div class="card p-5">
                    <span class="flex size-10 items-center justify-center rounded-lg bg-tint-blue">
                        <x-icon :name="$icon" class="size-[19px] text-accent-blue" stroke-width="1.9" />
                    </span>
                    <h3 class="mt-3.5 text-[15.5px] font-semibold text-ink">{{ $title }}</h3>
                    <p class="mt-1.5 text-[13.5px] leading-relaxed text-muted">{{ $body }}</p>
                </div>
            @endforeach

            <div class="card border-dashed p-5">
                <h3 class="text-[15.5px] font-semibold text-ink">Who it is not for — yet</h3>
                <p class="mt-1.5 text-[13.5px] leading-relaxed text-muted">
                    Heavy manufacturers needing bills of materials and production planning, and large
                    groups wanting deep multi-entity consolidation. We would rather tell you now than
                    after you have moved your data in.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-5">
        <div class="grid gap-10 lg:grid-cols-12 lg:gap-12">
            <div class="lg:col-span-6">
                <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] text-brand">Why we built it</p>
                <h2 class="mt-3 text-[24px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[30px]">
                    Three systems, none of which agreed with the others
                </h2>
                <div class="mt-5 space-y-4 text-[15px] leading-relaxed text-muted">
                    <p>
                        Most of the business owners we sat with were running invoicing out of a notebook,
                        stock out of memory, and customer records out of a phone's contact list. Each one
                        worked. None of them agreed with the others, and the reconciling happened at
                        night, by hand.
                    </p>
                    <p>
                        The software that claimed to fix this assumed a laptop, a card, and a connection
                        that stayed up. We wanted the one that assumes a phone, mobile money, and a
                        connection that comes and goes — and that still produces a document the tax
                        authority recognises.
                    </p>
                </div>
            </div>

            <div class="lg:col-span-6">
                <div class="card divide-y divide-border p-0">
                    @foreach ([
                        ['Built by', config('opes.brand.vendor'), config('opes.brand.vendor_url')],
                        ['Where', config('opes.contact.address'), null],
                        ['Support', config('opes.contact.support_email'), 'mailto:'.config('opes.contact.support_email')],
                        ['Phone', config('opes.contact.phone'), 'tel:'.preg_replace('/\s+/', '', (string) config('opes.contact.phone'))],
                    ] as [$label, $value, $href])
                        <div class="flex items-baseline justify-between gap-4 px-5 py-4">
                            <span class="shrink-0 text-[12.5px] font-semibold uppercase tracking-wide text-faint">{{ $label }}</span>
                            <span class="min-w-0 text-right text-[14.5px] font-semibold text-ink">
                                @if ($href)
                                    <a href="{{ $href }}" class="text-brand hover:underline">{{ $value }}</a>
                                @else
                                    {{ $value }}
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-[13.5px] leading-relaxed text-faint">
                    We answer the contact form ourselves. There is no ticket queue between you and the
                    people who wrote this.
                </p>
            </div>
        </div>
    </div>
</section>

<x-marketing.cta title="See whether it fits your business" />

</x-layouts.marketing>
