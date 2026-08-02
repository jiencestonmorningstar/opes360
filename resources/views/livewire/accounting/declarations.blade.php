@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('accounting') }}" wire:navigate
       class="focusable -ml-1.5 inline-flex min-h-[24px] items-center gap-1.5 rounded-lg px-1.5 py-1 text-[13.5px] font-semibold text-muted hover:text-ink-2">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Accounting
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[23px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[26px]">Declarations</h1>
            <p class="mt-1 text-[14px] text-muted">What the month owes the state, worked out from your books.</p>
        </div>

        {{-- Month stepper. 44px targets: this is the control most used on a
             phone on the way to the tax office. --}}
        <div class="flex shrink-0 items-center gap-1 rounded-xl border border-border bg-surface p-1">
            <button type="button" wire:click="previousMonth" aria-label="Previous month"
                    class="focusable flex size-11 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-ink">
                <x-icon name="chevron-left" class="size-[17px]" stroke-width="2.2" />
            </button>
            <span class="min-w-[124px] px-1 text-center text-[14.5px] font-semibold text-ink">{{ $label }}</span>
            <button type="button" wire:click="nextMonth" aria-label="Next month" @disabled($atCurrentMonth)
                    class="focusable flex size-11 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-30 disabled:hover:bg-transparent">
                <x-icon name="chevron-right" class="size-[17px]" stroke-width="2.2" />
            </button>
        </div>
    </div>

    {{-- Said once, at the top, before any figure: these are numbers to copy
         onto the real forms, not the forms themselves. --}}
    <div class="mt-5 rounded-xl bg-tint-blue px-4 py-3.5">
        <p class="text-[13.5px] font-semibold text-brand">A worksheet, not a filing</p>
        <p class="mt-1 text-[13px] leading-relaxed text-muted">
            These are the figures to copy onto your DGI and CNPS declarations, taken from your own journal entries.
            They are not the official forms and filing them is still something you or your accountant do.
            {{ $label }} is due by <span class="font-semibold text-ink-2">{{ $dueOn->format('j F Y') }}</span>.
        </p>
    </div>

    @if (! $chartIsReady)
        <div class="card mt-4 p-6 text-center">
            <p class="text-[15px] font-semibold text-ink">No books to declare from yet</p>
            <p class="mx-auto mt-1.5 max-w-md text-[13.5px] leading-relaxed text-muted">
                These figures come from your journal entries. Set up the chart of accounts and the entries start
                arriving on their own as you invoice and spend.
            </p>
            <a href="{{ route('accounting') }}" wire:navigate
               class="tap focusable mt-5 inline-flex h-12 items-center justify-center rounded-xl bg-fill-brand px-5 text-[15px] font-semibold text-white hover:opacity-90">
                Open Accounting
            </a>
        </div>
    @else

        {{-- ── TVA ──────────────────────────────────────────────────── --}}
        <h2 class="mt-7 text-[16px] font-bold tracking-[-0.02em] text-ink">TVA</h2>

        @if (! $vatRegistered)
            <p class="mt-1.5 text-[13px] leading-relaxed text-muted">
                This business is not marked as registered for TVA in its settings. The figures below are still what
                your books hold — worth checking if they are not zero.
            </p>
        @endif

        <div class="mt-3 grid grid-cols-1 gap-3 min-[400px]:grid-cols-3">
            <div class="card p-4">
                <p class="text-[12.5px] font-medium text-muted">TVA facturée</p>
                <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($vat['collected']) }}</p>
                <p class="mt-1 text-[12px] text-faint">Account 443, collected on sales.</p>
            </div>
            <div class="card p-4">
                <p class="text-[12.5px] font-medium text-muted">TVA récupérable</p>
                <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($vat['deductible']) }}</p>
                <p class="mt-1 text-[12px] text-faint">Account 445, paid on purchases.</p>
            </div>
            <div class="card p-4 ring-1 ring-brand/25">
                <p class="text-[12.5px] font-medium text-muted">
                    {{ $vat['credit'] > 0.005 ? 'Crédit à reporter' : 'TVA due' }}
                </p>
                <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] {{ $vat['credit'] > 0.005 ? 'text-positive' : 'text-brand' }}">
                    {{ $money($vat['credit'] > 0.005 ? $vat['credit'] : $vat['due']) }}
                </p>
                <p class="mt-1 text-[12px] text-faint">
                    {{ $vat['credit'] > 0.005
                        ? 'More reclaimable than collected — carried to next month.'
                        : 'Facturée less récupérable.' }}
                </p>
            </div>
        </div>

        <div class="card mt-3 p-4">
            <div class="flex items-center justify-between gap-3 py-1.5">
                <span class="text-[14px] text-ink-2">Chiffre d’affaires (HT)</span>
                <span class="tnum text-[14.5px] font-semibold text-ink">{{ $money($vat['turnover']) }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 border-t border-border py-1.5">
                <span class="text-[14px] text-muted">of which taxed at {{ rtrim(rtrim(number_format($vat['rate'], 2), '0'), '.') }}%</span>
                <span class="tnum text-[14px] text-ink-2">{{ $money($vat['taxed_turnover']) }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 border-t border-border py-1.5">
                <span class="text-[14px] text-muted">of which carried no TVA</span>
                <span class="tnum text-[14px] text-ink-2">{{ $money($vat['exempt_turnover']) }}</span>
            </div>

            @if ($vat['exempt_turnover'] > 0.005 && $vatRegistered)
                {{-- Either genuinely exempt, or an invoice somebody forgot to
                     put a rate on. Both are worth a look; only one is a
                     problem, and the business is the one who knows which. --}}
                <p class="mt-2.5 border-t border-border pt-2.5 text-[12.5px] leading-relaxed text-muted">
                    Sales with no TVA on them are either genuinely exempt or an invoice that missed its rate. Worth a
                    look before you file.
                </p>
            @endif
        </div>

        @can('accounting.export')
            <button type="button" wire:click="exportVat"
                    class="tap focusable mt-3 flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                Download the TVA worksheet
            </button>
        @endcan

        {{-- ── Salaires ─────────────────────────────────────────────── --}}
        <h2 class="mt-7 text-[16px] font-bold tracking-[-0.02em] text-ink">On wages</h2>

        @if ($payroll['headcount'] === 0)
            <div class="card mt-3 px-4 py-8 text-center">
                <p class="text-[15px] font-semibold text-ink">No payroll approved for {{ $label }}</p>
                <p class="mx-auto mt-1.5 max-w-md text-[13.5px] leading-relaxed text-muted">
                    Only approved and paid runs are declared — a draft changes every time somebody edits a contract,
                    and declaring one would mean declaring a month that has not happened.
                </p>
            </div>
        @else
            <p class="mt-1.5 text-[13px] text-muted">
                {{ $payroll['headcount'] }} {{ Str::plural('person', $payroll['headcount']) }} on
                {{ $money($payroll['gross']) }} gross.
            </p>

            <div class="mt-3 grid grid-cols-1 gap-3 min-[400px]:grid-cols-2">
                <div class="card p-4">
                    <p class="text-[12.5px] font-medium text-muted">Due to the CNPS</p>
                    <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($payroll['cnps_total']) }}</p>

                    <dl class="mt-3 space-y-1.5 border-t border-border pt-3">
                        @foreach ([
                            'Pension — salarié' => $payroll['cnps_employee'],
                            'Pension — patronale' => $payroll['cnps_employer_pension'],
                            'Prestations familiales' => $payroll['cnps_employer_family'],
                            'Accidents du travail' => $payroll['cnps_employer_risk'],
                        ] as $caption => $amount)
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-[13px] text-muted">{{ $caption }}</dt>
                                <dd class="tnum text-[13px] font-medium text-ink-2">{{ $money($amount) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="card p-4">
                    <p class="text-[12.5px] font-medium text-muted">Due to the DGI</p>
                    <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($payroll['dgi_total']) }}</p>

                    <dl class="mt-3 space-y-1.5 border-t border-border pt-3">
                        @foreach ([
                            'IRPP' => $payroll['irpp'],
                            'CAC' => $payroll['cac'],
                            'CFC — salarié' => $payroll['cfc_employee'],
                            'CFC — patronal' => $payroll['cfc_employer'],
                            'TDL' => $payroll['tdl'],
                            'RAV' => $payroll['rav'],
                            'FNE' => $payroll['fne'],
                        ] as $caption => $amount)
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-[13px] text-muted">{{ $caption }}</dt>
                                <dd class="tnum text-[13px] font-medium text-ink-2">{{ $money($amount) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>

            @can('accounting.export')
                <button type="button" wire:click="exportPayroll"
                        class="tap focusable mt-3 flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    Download the wages worksheet
                </button>
            @endcan
        @endif

        {{-- ── The entries behind the TVA ───────────────────────────── --}}
        @if ($vat['lines']->isNotEmpty())
            <h2 class="mt-7 text-[16px] font-bold tracking-[-0.02em] text-ink">Every TVA entry this month</h2>
            <p class="mt-1.5 text-[13px] text-muted">
                So a figure that looks wrong can be traced to the document that made it.
            </p>

            <div class="card mt-3 p-2">
                @foreach ($vat['lines'] as $index => $line)
                    <div wire:key="vat-{{ $index }}"
                         class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ $index > 0 ? 'border-t border-border' : '' }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[14px] font-semibold text-ink">
                                {{ $line['reference'] ?: $line['narration'] ?: 'Écriture' }}
                            </p>
                            <p class="tnum truncate text-[12.5px] text-muted">
                                {{ $line['date']?->format('j M') }} · {{ $line['journal'] }} · {{ $line['account'] }}
                            </p>
                        </div>
                        <p class="tnum shrink-0 text-[14px] font-semibold {{ $line['kind'] === 'deductible' ? 'text-positive' : 'text-ink' }}">
                            {{ $line['kind'] === 'deductible' ? '−' : '' }}{{ $money(abs($line['amount'])) }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
