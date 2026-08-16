@php
    use App\Support\Money;
    use App\Support\PaymentSchedule;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $bands = [
        PaymentSchedule::BAND_OVERDUE => ['Overdue', 'bg-tint-red', 'text-negative'],
        PaymentSchedule::BAND_DUE_SOON => ['Due soon', 'bg-tint-orange', 'text-warning'],
        PaymentSchedule::BAND_LATER => ['Later', 'bg-tint-slate', 'text-accent-slate'],
    ];

    $decisions = [
        PaymentSchedule::DECISION_FUND => ['Fund', 'bg-tint-green', 'text-positive'],
        PaymentSchedule::DECISION_PART => ['Part', 'bg-tint-amber', 'text-warning'],
        PaymentSchedule::DECISION_DEFER => ['Defer', 'bg-surface-2', 'text-muted'],
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Payment schedule</h1>
            <p class="mt-1 text-[14.5px] text-muted">Which bills this week's cash actually reaches.</p>
        </div>

        @can('payables.manage')
            @if (! $building && count($plan['items']) > 0)
                <button type="button" wire:click="startBuilding"
                        class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                    <span class="sr-only min-[420px]:not-sr-only">Build a run</span>
                </button>
            @endif
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    {{-- Cash and reserve first, because every mark below is a consequence of
         these two numbers. The reserve is not an advanced option: it is the
         only thing keeping a heavy payables week out of payroll. --}}
    <div class="card mt-5 p-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="{{ $labelClass }}" for="p-cash">Cash you can spend</label>
                <input id="p-cash" type="number" step="1" inputmode="decimal" wire:model.live.debounce.400ms="cash"
                       class="{{ $inputClass }} tnum" placeholder="{{ $money($plan['cash_on_hand']) }}">
                <p class="mt-1.5 text-[12.5px] leading-relaxed text-faint">
                    Leave blank to use the forecast, which reckons {{ $money($plan['cash_on_hand']) }} in hand by
                    {{ \Illuminate\Support\Carbon::parse($plan['pay_on'])->format('j M') }}.
                </p>
                @error('cash') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="{{ $labelClass }}" for="p-reserve">Reserve — do not touch</label>
                <input id="p-reserve" type="number" step="1" inputmode="decimal" wire:model.live.debounce.400ms="reserve"
                       class="{{ $inputClass }} tnum">
                <p class="mt-1.5 text-[12.5px] leading-relaxed text-faint">
                    Payroll, tax, the float in the till. The plan may not spend it, whatever a supplier is owed.
                </p>
                @error('reserve') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="{{ $labelClass }}" for="p-payon">Paying on</label>
                <input id="p-payon" type="date" wire:model.live="payOn" class="{{ $inputClass }}">
                <p class="mt-1.5 text-[12.5px] leading-relaxed text-faint">
                    Bills due within {{ $horizonDays }} days of this date count as due soon.
                </p>
                @error('payOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
            </div>
        </div>

        @if ($plan['reserve'] <= 0)
            <p class="mt-4 rounded-xl bg-tint-amber px-4 py-3 text-[13px] leading-relaxed text-warning">
                No reserve set. Every franc in the bank is on the table, including whatever the wages come out of.
            </p>
        @endif
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Free to spend</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($plan['cash_available']) }}</p>
            <p class="mt-0.5 text-[11.5px] text-faint">{{ $money($plan['cash_on_hand']) }} less {{ $money($plan['reserve']) }} held back</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Goes out</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($plan['total_scheduled']) }}</p>
            <p class="mt-0.5 text-[11.5px] text-faint">{{ $plan['suppliers_paid'] }} {{ Str::plural('supplier', $plan['suppliers_paid']) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Owed in total</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($plan['total_outstanding']) }}</p>
        </div>
        {{-- What is still owed after the run. The number a plan exists to
             shrink, and the one nobody looks at if it is not shown. --}}
        <div class="card p-4 {{ $plan['shortfall'] > 0 ? 'ring-1 ring-negative/30' : '' }}">
            <p class="text-[12.5px] font-medium text-muted">Still owing after</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] {{ $plan['shortfall'] > 0 ? 'text-negative' : 'text-positive' }}">
                {{ $money($plan['shortfall']) }}
            </p>
        </div>
    </div>

    @if ($building)
        <div class="card mt-5 border border-brand p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Build a draft run</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Takes the {{ $funded->count() }} {{ Str::plural('bill', $funded->count()) }} the cash reaches and
                freezes them at {{ $money($plan['total_scheduled']) }}. Nothing is paid: the run still has to be
                approved, and then released, by somebody who may do those things.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="{{ $labelClass }}" for="r-ref">Reference</label>
                    <input id="r-ref" type="text" wire:model="runReference" class="{{ $inputClass }}" placeholder="Semaine 12">
                    @error('runReference') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="r-method">Paid by</label>
                    <select id="r-method" wire:model="runMethod" class="{{ $inputClass }}">
                        @foreach (\App\Models\Expense::METHODS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="r-notes">Note</label>
                    <input id="r-notes" type="text" wire:model="runNotes" class="{{ $inputClass }}">
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="buildRun"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Build draft run
                </button>
                <button type="button" wire:click="$set('building', false)"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <div class="card mt-5 p-2" wire:loading.class="opacity-60">
        @forelse ($funded as $index => $item)
            @php
                [$bandLabel, $bandTint, $bandInk] = $bands[$item['band']];
                [$decisionLabel, $decisionTint, $decisionInk] = $decisions[$item['decision']];
            @endphp
            <div wire:key="s-{{ $item['expense_id'] }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <div class="flex items-center gap-3.5">
                    <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ $bandTint }}">
                        <x-icon name="clock" class="size-[16px] {{ $bandInk }}" stroke-width="2" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[14.5px] font-semibold text-ink">{{ $item['supplier'] }}</p>
                        <p class="tnum truncate text-[12.5px] text-muted">
                            {{ $item['reference'] }} · {{ $bandLabel }}
                            @if ($item['days_overdue'] > 0)
                                · {{ $item['days_overdue'] }} {{ Str::plural('day', $item['days_overdue']) }} late
                            @else
                                · due {{ $item['due_date']->format('j M') }}
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="tnum text-[15px] font-bold text-ink">{{ $money($item['scheduled']) }}</p>
                        @if ($item['deferred'] > 0)
                            <p class="tnum text-[11.5px] text-warning">{{ $money($item['deferred']) }} left owing</p>
                        @else
                            <p class="tnum text-[11.5px] text-faint">of {{ $money($item['outstanding']) }}</p>
                        @endif
                    </div>

                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-bold {{ $decisionTint }} {{ $decisionInk }}">
                        {{ $decisionLabel }}
                    </span>
                </div>
            </div>
        @empty
            <div class="px-4 py-12 text-center">
                <p class="text-[15.5px] font-semibold text-ink">
                    {{ $plan['total_outstanding'] > 0 ? 'The cash reaches none of it' : 'Nothing owing' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    {{ $plan['total_outstanding'] > 0
                        ? 'Every bill is deferred. Either the reserve is holding back everything there is, or there is genuinely nothing to pay with.'
                        : 'No unpaid supplier bills. Nothing to schedule.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($deferred->isNotEmpty())
        <button type="button" wire:click="$toggle('showDeferred')"
                aria-expanded="{{ $showDeferred ? 'true' : 'false' }}"
                class="focusable mt-4 flex w-full items-center justify-between rounded-xl border border-border bg-surface px-4 py-3 text-left hover:bg-surface-2">
            <span class="text-[14px] font-semibold text-ink">
                {{ $deferred->count() }} {{ Str::plural('bill', $deferred->count()) }} the cash does not reach
            </span>
            <span class="flex items-center gap-2">
                <span class="tnum text-[14px] font-bold text-muted">{{ $money($deferred->sum('outstanding')) }}</span>
                <x-icon name="chevron-down" class="size-[17px] text-faint transition-transform {{ $showDeferred ? 'rotate-180' : '' }}" />
            </span>
        </button>

        {{-- Shown rather than dropped. A supplier left out of this week's run
             is a phone call next week, and nobody should be surprised by it. --}}
        @if ($showDeferred)
            <div class="card mt-3 p-2">
                @foreach ($deferred as $index => $item)
                    <div wire:key="d-{{ $item['expense_id'] }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ $index > 0 ? 'border-t border-border' : '' }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[14px] font-medium text-ink">{{ $item['supplier'] }}</p>
                            <p class="tnum truncate text-[12px] text-muted">
                                {{ $item['reference'] }} · {{ $bands[$item['band']][0] }}
                            </p>
                        </div>
                        <p class="tnum shrink-0 text-[14px] font-semibold text-muted">{{ $money($item['outstanding']) }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
