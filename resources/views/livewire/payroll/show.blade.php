@php
    use App\Models\PayrollRun;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('payroll') }}" wire:navigate
       class="focusable -ml-1.5 inline-flex min-h-[24px] items-center gap-1.5 rounded-lg px-1.5 py-1 text-[13.5px] font-semibold text-muted hover:text-ink-2">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Payroll
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[23px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[26px]">{{ $run->periodLabel() }}</h1>
            <p class="mt-1 text-[14px] text-muted">
                {{ $run->headcount }} {{ Str::plural('person', $run->headcount) }}
                @if ($run->approved_at) · approved {{ $run->approved_at->format('j M Y') }} @endif
            </p>
        </div>

        <span class="shrink-0 rounded-full px-3 py-1.5 text-[12.5px] font-semibold
                     {{ $run->isPaid() ? 'bg-tint-green text-positive' : ($run->isApproved() ? 'bg-tint-blue text-brand' : ($run->isVoid() ? 'bg-tint-slate text-accent-slate' : 'bg-tint-amber text-warning')) }}">
            {{ PayrollRun::STATUSES[$run->status] ?? $run->status }}
        </span>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    {{-- The four figures the month comes down to. `total cost` is the one a
         business owner rarely has and always wants. --}}
    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Gross</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($run->gross) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Net to staff</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($run->net) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Employer charges</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($run->employer_charges) }}</p>
        </div>
        <div class="card p-4 ring-1 ring-brand/25">
            <p class="text-[12.5px] font-medium text-muted">Total cost</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-brand">{{ $money($run->totalCost()) }}</p>
        </div>
    </div>

    {{-- What is owed to whom, and when. Both fall due after the month closes,
         so they are worth seeing before the money is spent. --}}
    <div class="mt-3 grid grid-cols-1 gap-3 min-[400px]:grid-cols-2">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Due to CNPS</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($cnpsDue) }}</p>
            <p class="mt-1 text-[12px] text-faint">Both sides of the pension, plus family allowances and risk.</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Due to the DGI</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($taxDue) }}</p>
            <p class="mt-1 text-[12px] text-faint">IRPP, CAC, CFC, TDL, RAV and FNE.</p>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap gap-3">
        @if ($run->isDraft())
            @can('payroll.run')
                <button type="button" wire:click="rebuild"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    Rebuild from contracts
                </button>
            @endcan
            @can('payroll.approve')
                <button type="button" wire:click="approve"
                        wire:confirm="Approve {{ $run->periodLabel() }}? The figures are frozen from here and posted to the books."
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Approve
                </button>
            @endcan
        @elseif ($run->isApproved())
            @can('payroll.pay')
                <button type="button" wire:click="startPaying"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Record the payment
                </button>
            @endcan
        @endif

        @if ($run->headcount > 0)
            {{-- The CNPS and DGI returns are filled in from this, and nobody is
                 going to retype twenty payslips. --}}
            <a href="{{ route('payroll.register', $run) }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                Download the register
            </a>
        @endif

        @if (! $run->isPaid() && ! $run->isVoid())
            @can('payroll.void')
                <button type="button" wire:click="void"
                        wire:confirm="Void this run? Any journal entry is reversed, not deleted."
                        class="tap focusable flex h-12 items-center justify-center rounded-xl px-4 text-[14.5px] font-semibold text-negative hover:bg-tint-red">
                    Void
                </button>
            @endcan
        @endif
    </div>

    @if ($paying)
        <div class="card mt-4 border-brand p-5">
            <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Record the payment</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                This clears {{ $money($run->net) }} out of what you owe your staff and off your cash or bank.
                What is owed to the CNPS and the DGI stays owed — those are separate payments on their own dates.
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="{{ $labelClass }}" for="pay-on">Paid on</label>
                    <input id="pay-on" type="date" wire:model="paidOn" class="{{ $inputClass }}">
                    @error('paidOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="pay-method">Paid from</label>
                    <select id="pay-method" wire:model="payMethod" class="{{ $inputClass }}">
                        <option value="bank">Bank transfer</option>
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="pay-ref">Reference</label>
                    <input id="pay-ref" type="text" wire:model="payReference" class="{{ $inputClass }}">
                </div>
            </div>

            <div class="mt-5 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="markPaid"
                        class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                    Confirm
                </button>
                <button type="button" wire:click="$set('paying', false)"
                        class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <h2 class="mt-7 text-[16px] font-bold tracking-[-0.02em] text-ink">Payslips</h2>

    <div class="card mt-3 p-2">
        @forelse ($payslips as $index => $slip)
            <div wire:key="{{ $slip->id }}" class="rounded-xl px-3 py-3.5 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[15px] font-semibold text-ink">{{ $slip->employeeName() }}</p>
                        {{-- The withheld figure is the first thing to go when
                             the row is narrow: it is the least useful of the
                             three, and truncating "205,000 gross…" tells
                             nobody anything. --}}
                        <p class="tnum truncate text-[13px] text-muted">
                            {{ $money($slip->gross) }} gross<span class="hidden min-[480px]:inline"> · {{ $money($slip->total_deductions) }} withheld</span>
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="tnum text-[15px] font-bold text-ink">{{ $money($slip->net_pay) }}</p>
                        <p class="tnum text-[11.5px] font-medium text-faint">costs {{ $money($slip->total_cost) }}</p>
                    </div>
                    <a href="{{ route('payslips.print', $slip) }}" target="_blank"
                       class="focusable flex size-9 shrink-0 items-center justify-center rounded-lg text-faint hover:bg-surface-2 hover:text-ink-2"
                       aria-label="Print {{ $slip->employeeName() }}'s payslip">
                        <x-icon name="printer" class="size-[17px]" />
                    </a>
                </div>
            </div>
        @empty
            <div class="px-4 py-10 text-center">
                <p class="text-[15px] font-semibold text-ink">Nobody on this run</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    Everyone on the team needs a contract covering {{ $run->periodLabel() }} before they can be paid
                    for it.
                </p>
            </div>
        @endforelse
    </div>

    @if ($run->rates)
        <p class="mt-5 text-[12.5px] leading-relaxed text-faint">
            Computed with the rates in force when this run was approved, kept with it. Later changes to the rates do
            not touch these figures.
        </p>
    @endif
</div>
