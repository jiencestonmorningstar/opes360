{{--
    The app mid-outage: the offline banner it really shows, over documents that
    were issued anyway and are queued to sync with the numbers they printed.
--}}
<div class="card overflow-hidden" aria-hidden="true">
    <div class="flex items-center gap-2.5 bg-tint-orange px-4 py-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-fill-orange">
            <x-icon name="offline" class="size-[15px] text-white" stroke-width="2" />
        </span>
        <div class="min-w-0">
            <p class="text-[12.5px] font-semibold text-ink">No connection</p>
            <p class="text-[11px] text-muted">Still working — 3 documents waiting to sync</p>
        </div>
    </div>

    <div class="divide-y divide-border">
        @foreach ([
            ['INV-2026-0185', 'Boulangerie Nkolbisson', 42500],
            ['INV-2026-0186', 'Pharmacie Bonapriso', 118000],
            ['REC-2026-0092', 'Garage Akwa', 25000],
        ] as [$number, $customer, $amount])
            <div class="flex items-center gap-3 px-4 py-3">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="clock" class="size-[13px] text-accent-slate" stroke-width="2" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="tnum text-[12.5px] font-semibold text-ink">{{ $number }}</p>
                    <p class="truncate text-[11px] text-faint">{{ $customer }}</p>
                </div>
                <span class="tnum shrink-0 text-[12.5px] font-semibold text-ink">{{ \App\Support\Money::format($amount, 'XAF', false) }}</span>
            </div>
        @endforeach
    </div>

    <p class="flex items-center justify-center gap-1.5 border-t border-border bg-surface-2 py-2.5 text-[11.5px] font-medium text-muted">
        <x-icon name="sync" class="size-[13px]" stroke-width="2" />
        Numbers already leased — they will not change on sync
    </p>
</div>
