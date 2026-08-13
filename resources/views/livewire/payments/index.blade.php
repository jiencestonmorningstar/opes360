@php
    use App\Enums\PaymentMethod;
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div>
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Payments</h1>
        <p class="mt-1 text-[14.5px] text-muted">Every payment received, with its receipt.</p>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
            {{ session('status') }}
        </div>
    @endif

    {{-- Period totals.

         Three across needs about 100px a tile, and a currency amount has no
         break opportunity in it: "FCFA1,250,000" does not fit, and rather than
         scroll, the browser quietly shrinks the entire page to make room.
         Stacked below 400px, three across above it. --}}
    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-3">
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
            @php $refundable = $this->refundableOn($payment); @endphp
            <div wire:key="{{ $payment->id }}" class="{{ $index > 0 ? 'border-t border-border' : '' }}">
                <div class="flex items-center gap-3.5 rounded-xl px-3 py-3">
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
                            @if ($refundable < (float) $payment->amount)
                                {{-- Said plainly on the row: a payment that has
                                     been partly given back is not the amount it
                                     appears to be. --}}
                                · <span class="font-semibold text-warning">
                                    {{ $refundable <= 0 ? 'refunded' : Money::format((float) $payment->amount - $refundable, $payment->currency).' refunded' }}
                                </span>
                            @endif
                        </span>
                    </span>

                    <span class="tnum shrink-0 text-[15px] font-bold text-positive">
                        +{{ Money::format($payment->amount, $payment->currency) }}
                    </span>

                    @can('payments.refund')
                        @if ($refundable > 0)
                            <button type="button" wire:click="startRefund('{{ $payment->id }}')"
                                    class="tap focusable flex shrink-0 items-center justify-center rounded-lg px-2 text-[13px] font-semibold text-muted hover:text-negative">
                                Refund
                            </button>
                        @endif
                    @endcan

                    @if ($payment->receipt)
                        <a href="{{ route('receipts.print', $payment->receipt) }}" target="_blank"
                           class="tap focusable flex shrink-0 items-center justify-center rounded-lg text-muted hover:text-brand"
                           aria-label="Print receipt {{ $payment->receipt->number }}">
                            <x-icon name="printer" class="size-[20px]" />
                        </a>
                    @endif
                </div>

                @if ($refundingId === $payment->id)
                    <div class="mx-3 mb-3 rounded-xl border border-border bg-surface-2 p-4">
                        <p class="text-[13.5px] font-semibold text-ink">Refund this payment</p>
                        <p class="mt-1 text-[12.5px] leading-relaxed text-muted">
                            The receipt stays valid — the money did change hands. The invoice becomes owed again,
                            and the books are corrected.
                        </p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="refund-amount-{{ $payment->id }}" class="block text-[12.5px] font-semibold text-ink-2">
                                    How much (up to {{ Money::format($refundable, $payment->currency) }})
                                </label>
                                <input id="refund-amount-{{ $payment->id }}" type="number" step="0.01" min="0"
                                       inputmode="decimal" wire:model="refundAmount"
                                       class="tnum control-compact mt-1 w-full border border-border bg-surface px-3 text-[14px] text-ink focus:border-brand focus:outline-none">
                                @error('refundAmount') <p class="mt-1 text-[12.5px] font-medium text-negative">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="refund-method-{{ $payment->id }}" class="block text-[12.5px] font-semibold text-ink-2">How it goes back</label>
                                <select id="refund-method-{{ $payment->id }}" wire:model="refundMethod"
                                        class="control-compact mt-1 w-full border border-border bg-surface px-3 text-[14px] text-ink focus:border-brand focus:outline-none">
                                    @foreach (\App\Enums\PaymentMethod::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label for="refund-reason-{{ $payment->id }}" class="block text-[12.5px] font-semibold text-ink-2">Why</label>
                            <input id="refund-reason-{{ $payment->id }}" type="text" wire:model="refundReason"
                                   placeholder="Goods returned"
                                   class="control-compact mt-1 w-full border border-border bg-surface px-3 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none">
                            @error('refundReason') <p class="mt-1 text-[12.5px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="refund" wire:loading.attr="disabled" wire:target="refund"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-negative px-5 text-[14px] font-semibold text-white">
                                <span wire:loading.remove wire:target="refund">Refund</span>
                                <span wire:loading wire:target="refund">Refunding…</span>
                            </button>
                            <button type="button" wire:click="cancelRefund"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14px] font-semibold text-ink">
                                Cancel
                            </button>
                        </div>
                    </div>
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
