{{--
    The document pipeline, as the app actually shows it: one job moving from
    quotation to invoice to receipt, each step keeping its own number.

    Drawn from the design tokens rather than shipped as a screenshot, so it is
    correct in both themes and cannot go stale behind a UI change.
--}}
<div class="card p-4 sm:p-5" aria-hidden="true">
    <div class="flex items-center justify-between gap-3">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Ets. Mbarga &amp; Fils</p>
        <span class="text-[11.5px] font-medium text-faint">This week</span>
    </div>

    <div class="mt-3.5 space-y-2">
        @foreach ([
            ['Quotation', 'QT-2026-0042', 'Accepted', 'positive', 'document'],
            ['Invoice', 'INV-2026-0184', 'Paid in full', 'positive', 'document-plus'],
            ['Receipt', 'REC-2026-0091', 'Issued', 'brand', 'receipt'],
        ] as $step)
            @php [$label, $number, $state, $tone, $icon] = $step; @endphp
            <div class="flex items-center gap-3 rounded-xl border border-border bg-surface-2 px-3 py-2.5">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-tint-blue">
                    <x-icon :name="$icon" class="size-[15px] text-accent-blue" stroke-width="1.9" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[12.5px] font-semibold text-ink">{{ $label }}</p>
                    <p class="tnum truncate text-[11px] text-faint">{{ $number }}</p>
                </div>
                <span class="shrink-0 text-[11.5px] font-semibold {{ $tone === 'positive' ? 'text-positive' : 'text-brand' }}">{{ $state }}</span>
            </div>
        @endforeach
    </div>

    <div class="mt-3.5 flex items-baseline justify-between gap-4 border-t border-border pt-3">
        <span class="text-[12px] font-semibold uppercase tracking-wide text-faint">Collected</span>
        <span class="tnum text-[15px] font-bold text-positive">{{ \App\Support\Money::format(294050, 'XAF', false) }}</span>
    </div>
</div>
