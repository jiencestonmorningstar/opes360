<x-layouts.marketing title="About"
                     description="Opes360 is built by Opesware Technologies for small and growing businesses in Cameroon that need sales, invoicing and records in one place — including when the connection fails.">

<x-marketing.page-header eyebrow="About" title="Built here, for how business is actually done here"
    :lead="'Opes360 is a product of '.config('opes.brand.vendor').', for small and growing businesses that need sales, invoicing and customer records in one place — including in the moments the connection does not cooperate.'" />

{{-- Three beliefs, each shown with the design decision it produced. A principle
     with nothing built on it is decoration. --}}
<section class="mx-auto max-w-6xl px-5 py-14 sm:py-20">
    <div class="grid gap-5 lg:grid-cols-3">
        @foreach ([
            ['qr-code', 'teal', 'A document should prove itself',
             'A printed invoice is only worth what the person holding it believes. So every document carries a QR that opens a verification page on your own domain — checkable months later, by someone with no account.'],
            ['offline', 'orange', 'A weak signal is not a lost sale',
             'Invoice numbers are leased to the device before they are needed, so a phone with no connection still issues correctly numbered documents. That is harder to build than a warning banner, and it is the right thing to build.'],
            ['banknotes', 'green', 'Priced in the money you hold',
             'Quoted in FCFA, paid by MTN or Orange Money, with no card and no foreign-currency step. A price you have to convert before you can judge it is not an honest price.'],
        ] as [$icon, $accent, $title, $body])
            @php
                $inkClass = ['teal' => 'text-accent-teal', 'orange' => 'text-accent-orange', 'green' => 'text-accent-green'][$accent];
                $tintClass = ['teal' => 'bg-tint-teal', 'orange' => 'bg-tint-orange', 'green' => 'bg-tint-green'][$accent];
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

<section class="border-y border-border bg-surface-2/60 py-14 sm:py-20">
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
