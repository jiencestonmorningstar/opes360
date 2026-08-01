{{--
    What a customer sees after scanning the QR on a printed document: the
    verification page, reduced to its verdict and the facts behind it.
--}}
<div class="card overflow-hidden" aria-hidden="true">
    <div class="flex flex-col items-center bg-tint-teal px-5 py-6 text-center">
        <span class="flex size-12 items-center justify-center rounded-full bg-fill-teal">
            <x-icon name="check-circle" class="size-[24px] text-white" stroke-width="2.2" />
        </span>
        <p class="mt-3 text-[17px] font-bold tracking-[-0.02em] text-ink">Verified document</p>
        <p class="mt-1 text-[12.5px] text-muted">Issued by a registered business on {{ config('opes.brand.name') }}</p>
    </div>

    <dl class="divide-y divide-border px-5">
        @foreach ([
            'Business' => 'Ets. Mbarga & Fils',
            'Document' => 'Invoice INV-2026-0184',
            'Issued' => '14 March 2026',
            'Amount' => \App\Support\Money::format(294050, 'XAF', false),
        ] as $label => $value)
            <div class="flex items-baseline justify-between gap-4 py-2.5">
                <dt class="text-[12.5px] text-muted">{{ $label }}</dt>
                <dd class="truncate text-[12.5px] font-semibold text-ink">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <p class="border-t border-border px-5 py-3 text-center text-[11.5px] text-faint">
        No account needed to check this
    </p>
</div>
