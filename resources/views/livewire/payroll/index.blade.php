@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="min-w-0">
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Payroll</h1>
        <p class="mt-1 text-[14.5px] text-muted">A month at a time — CNPS, IRPP and everything else worked out for you.</p>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-2">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">On the payroll</p>
            <p class="tnum mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $headcount }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Last month paid</p>
            <p class="mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $lastPaid?->periodLabel() ?? '—' }}</p>
        </div>
    </div>

    @can('payroll.run')
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Run a month</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                This builds a draft from everyone's current contract and allowances. Nothing is committed and nothing
                reaches the books until you approve it — rebuild it as many times as you like first.
            </p>

            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="sm:max-w-[220px] sm:flex-1">
                    <label class="mb-1.5 block text-[13px] font-semibold text-ink-2" for="run-period">Month</label>
                    <select id="run-period" wire:model="period" class="{{ $inputClass }}">
                        @foreach ($months as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('period') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="start"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Build the draft
                </button>
            </div>

            @if ($headcount === 0)
                <p class="mt-4 rounded-xl bg-tint-amber px-4 py-3 text-[13px] font-medium text-warning">
                    Nobody is on the team yet, so a run would be empty.
                    <a href="{{ route('team') }}" wire:navigate class="underline">Add your staff first.</a>
                </p>
            @endif
        </div>
    @endcan

    <div class="card mt-5 p-2" wire:loading.class="opacity-60">
        @forelse ($runs as $index => $run)
            <a href="{{ route('payroll.show', $run) }}" wire:key="{{ $run->id }}" wire:navigate
               class="focusable flex items-center gap-3.5 rounded-xl px-3 py-3.5 transition-colors hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full
                             {{ $run->isPaid() ? 'bg-tint-green' : ($run->isApproved() ? 'bg-tint-blue' : ($run->isVoid() ? 'bg-tint-slate' : 'bg-tint-amber')) }}">
                    <x-icon name="banknotes" class="size-[18px]
                             {{ $run->isPaid() ? 'text-positive' : ($run->isApproved() ? 'text-brand' : ($run->isVoid() ? 'text-accent-slate' : 'text-warning')) }}"
                            stroke-width="1.9" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-semibold text-ink">{{ $run->periodLabel() }}</p>
                    <p class="truncate text-[13px] text-muted">
                        {{ $run->headcount }} {{ Str::plural('person', $run->headcount) }}
                        · net {{ $money($run->net) }}
                    </p>
                </div>

                <div class="shrink-0 text-right">
                    <p class="tnum text-[15px] font-bold text-ink">{{ $money($run->totalCost()) }}</p>
                    <p class="text-[11.5px] font-semibold
                              {{ $run->isPaid() ? 'text-positive' : ($run->isApproved() ? 'text-brand' : ($run->isVoid() ? 'text-faint' : 'text-warning')) }}">
                        {{ \App\Models\PayrollRun::STATUSES[$run->status] ?? $run->status }}
                    </p>
                </div>

                <x-icon name="chevron-right" class="size-[18px] shrink-0 text-faint" />
            </a>
        @empty
            <div class="px-4 py-12 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="banknotes" class="size-[24px] text-accent-slate" stroke-width="1.7" />
                </span>
                <p class="mt-4 text-[15.5px] font-semibold text-ink">No payroll run yet</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    Build a month above and you get a payslip per person with the CNPS, the IRPP and the centimes
                    additionnels already worked out — and a straight answer to what your staff actually cost.
                </p>
            </div>
        @endforelse
    </div>

    @if ($runs->hasPages())
        <div class="mt-5">{{ $runs->links() }}</div>
    @endif
</div>
