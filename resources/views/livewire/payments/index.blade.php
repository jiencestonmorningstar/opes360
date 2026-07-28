@php
    use App\Enums\PaymentMethod;
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div>
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Payments</h1>
        <p class="mt-1 text-[14.5px] text-muted">Every payment received, with its receipt.</p>
    </div>

    {{-- Period totals --}}
    <div class="mt-5 grid grid-cols-3 gap-3">
        @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $key => $label)
            <div class="card p-4">
                <p class="text-[12.5px] font-medium text-muted">{{ $label }}</p>
                <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink min-[560px]:text-[21px]">
                    {{ Money::format($totals[$key], $currency) }}
                </p>
            </div>
        @endforeach
    </div>

    <div class="relative mt-4">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Search by customer, receipt or reference…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="no-scrollbar -mx-5 mt-3 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0">
        <button type="button" wire:click="setMethod('all')"
                class="focusable h-9 shrink-0 rounded-full px-3.5 text-[13px] font-semibold transition-colors
                       {{ $method === 'all' ? 'bg-tint-blue text-brand' : 'text-muted hover:text-ink-2' }}">
            All
        </button>
        @foreach (PaymentMethod::cases() as $payMethod)
            <button type="button" wire:click="setMethod('{{ $payMethod->value }}')"
                    class="focusable h-9 shrink-0 rounded-full px-3.5 text-[13px] font-semibold transition-colors
                           {{ $method === $payMethod->value ? 'bg-tint-blue text-brand' : 'text-muted hover:text-ink-2' }}">
                {{ $payMethod->label() }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($payments as $index => $payment)
            <div wire:key="{{ $payment->id }}"
                 class="flex items-center gap-3.5 rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[46px] shrink-0 items-center justify-center rounded-xl bg-tint-green">
                    <x-icon name="banknotes" class="size-[22px] text-accent-green" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink">
                        {{ $payment->contact?->displayName() ?? 'Walk-in' }}
                    </span>
                    <span class="block truncate text-[13px] text-muted">
                        {{ $payment->method->label() }}
                        @if ($payment->receipt) · {{ $payment->receipt->number }} @endif
                        · {{ $payment->received_at->format('M j, g:ia') }}
                    </span>
                </span>

                <span class="tnum shrink-0 text-[15px] font-bold text-positive">
                    +{{ Money::format($payment->amount, $payment->currency) }}
                </span>

                @if ($payment->receipt)
                    <a href="{{ route('receipts.print', $payment->receipt) }}" target="_blank"
                       class="tap focusable flex shrink-0 items-center justify-center rounded-lg text-muted hover:text-brand"
                       aria-label="Print receipt {{ $payment->receipt->number }}">
                        <x-icon name="printer" class="size-[20px]" />
                    </a>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center px-6 py-14 text-center">
                <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-green">
                    <x-icon name="banknotes" class="size-7 text-accent-green" />
                </span>
                <p class="mt-4 text-[16px] font-semibold text-ink">No payments{{ $search !== '' ? ' match your search' : ' yet' }}</p>
                <p class="mt-1 max-w-xs text-[13.5px] text-muted">Record one from any unpaid invoice.</p>
            </div>
        @endforelse
    </div>

    @if ($payments->hasPages())
        <div class="mt-4">{{ $payments->links('pagination.opes') }}</div>
    @endif
</div>
