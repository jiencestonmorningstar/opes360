@php
    use App\Models\SalesOrder;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $statusTint = [
        'draft' => 'bg-tint-slate text-ink-2',
        'confirmed' => 'bg-tint-blue text-brand',
        'picking' => 'bg-tint-amber text-warning',
        'delivered' => 'bg-tint-green text-positive',
        'invoiced' => 'bg-tint-green text-positive',
        'cancelled' => 'bg-tint-red text-negative',
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('orders') }}" class="focusable inline-flex items-center gap-1.5 text-[13.5px] font-semibold text-muted hover:text-ink">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" /> Orders
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $order->number }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $order->contact?->name }}
                @if ($order->promised_date) · promised {{ $order->promised_date->format('M j, Y') }} @endif
                · {{ $money($order->total()) }} {{ $currency }}
            </p>
        </div>
        <span class="shrink-0 rounded-full px-3 py-1.5 text-[12px] font-bold {{ $statusTint[$order->status] ?? 'bg-tint-slate text-ink-2' }}">
            {{ $order->status }}
        </span>
    </div>

    @error('order')
        <div class="mt-4 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ $message }}</div>
    @enderror

    {{-- Lines: the whole story of the promise, quantity by quantity. --}}
    <div class="card mt-5 p-5">
        <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Lines</h2>
        <div class="mt-3 space-y-2">
            @foreach ($order->lines as $line)
                <div wire:key="l-{{ $line->id }}" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 {{ $loop->index > 0 ? 'border-t border-border pt-2' : '' }}">
                    <div class="min-w-0">
                        <p class="truncate text-[14.5px] font-semibold text-ink">{{ $line->description }}</p>
                        <p class="text-[12.5px] text-muted">
                            {{ $qty($line->quantity_ordered) }} ordered · {{ $money($line->unit_price) }} {{ $currency }} each
                        </p>
                    </div>
                    <p class="tnum text-[12.5px]">
                        <span class="text-muted">{{ $qty($line->quantity_reserved) }} reserved</span>
                        · <span class="text-positive">{{ $qty($line->quantity_delivered) }} delivered</span>
                        @if ((float) $line->quantity_backordered > 0)
                            · <span class="font-bold text-negative">{{ $qty($line->quantity_backordered) }} backordered</span>
                        @endif
                        @if ((float) $line->quantity_invoiced > 0)
                            · <span class="text-muted">{{ $qty($line->quantity_invoiced) }} invoiced</span>
                        @endif
                    </p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Actions --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">
        @if ($order->isDraft())
            @can('orders.confirm')
                <button type="button" wire:click="confirm"
                        class="tap focusable flex h-11 items-center rounded-xl bg-fill-brand px-5 text-[14px] font-semibold text-white hover:opacity-90">
                    Confirm — reserve the stock
                </button>
            @endcan
        @endif

        @if ($order->isOpen())
            @can('orders.deliver')
                @if (! $delivering)
                    <button type="button" wire:click="startDelivering"
                            class="tap focusable flex h-11 items-center rounded-xl bg-tint-green px-5 text-[14px] font-semibold text-positive hover:opacity-80">
                        Deliver
                    </button>
                @endif
            @endcan
            @if ($order->backorderedTotal() > 0)
                @can('orders.confirm')
                    <button type="button" wire:click="reserveBackorders"
                            class="focusable rounded-xl bg-surface-2 px-4 py-2.5 text-[13px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                        Try to reserve the backorder
                    </button>
                @endcan
            @endif
        @endif

        @if ($order->lines->sum(fn ($l) => $l->uninvoicedQuantity()) > 0)
            @can('orders.manage')
                <button type="button" wire:click="invoice"
                        class="tap focusable flex h-11 items-center rounded-xl border border-border bg-surface px-5 text-[14px] font-semibold text-ink hover:bg-surface-2">
                    Invoice the delivered lines
                </button>
            @endcan
        @endif

        @if ($order->isDraft() || $order->isOpen())
            @can('orders.manage')
                <button type="button" wire:click="cancel"
                        class="focusable rounded-xl px-4 py-2.5 text-[13px] font-semibold text-muted hover:bg-tint-red hover:text-negative">
                    Cancel order
                </button>
            @endcan
        @endif
    </div>

    {{-- Delivering: what goes out now, line by line. --}}
    @if ($delivering)
        <div class="card mt-5 p-5">
            <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Deliver</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Partial is fine — what stays reserved keeps its hold. Issuing writes the stock movements and the
                delivery note together.
            </p>

            <div class="mt-4 space-y-3">
                @foreach ($order->lines as $line)
                    @if ((float) $line->quantity_reserved > 0)
                        <div wire:key="p-{{ $line->id }}" class="flex flex-col gap-2 sm:flex-row sm:items-end sm:gap-4">
                            <div class="flex-1">
                                <p class="text-[14px] font-semibold text-ink">{{ $line->description }}</p>
                                <p class="text-[12.5px] text-muted">{{ $qty($line->quantity_reserved) }} reserved</p>
                            </div>
                            <div class="sm:w-[140px]">
                                <label class="{{ $labelClass }}" for="pick-{{ $line->id }}">Send now</label>
                                <input id="pick-{{ $line->id }}" type="number" step="any" min="0" inputmode="decimal"
                                       wire:model="picks.{{ $line->id }}" class="{{ $inputClass }} tnum">
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            @error('picks') <p class="mt-3 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror

            <div class="mt-5 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="deliver"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Issue delivery note
                </button>
                <button type="button" wire:click="$set('delivering', false)"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Delivery notes --}}
    @if ($order->deliveryNotes->isNotEmpty())
        <div class="card mt-5 p-5">
            <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Delivery notes</h2>
            <div class="mt-3 space-y-2">
                @foreach ($order->deliveryNotes as $note)
                    <div wire:key="dn-{{ $note->id }}" class="flex items-center justify-between gap-3 {{ $loop->index > 0 ? 'border-t border-border pt-2' : '' }}">
                        <div class="min-w-0">
                            <p class="text-[14px] font-semibold text-ink">{{ $note->number }}</p>
                            <p class="text-[12.5px] text-muted">
                                {{ $note->delivered_on?->format('M j, Y') }} ·
                                {{ $note->lines->count() }} {{ str('line')->plural($note->lines->count()) }}
                            </p>
                        </div>
                        <a href="{{ route('orders.delivery-note', $note) }}" target="_blank"
                           class="focusable shrink-0 rounded-lg bg-surface-2 px-3 py-2 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                            Print
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Invoices --}}
    @if ($order->invoices->isNotEmpty())
        <div class="card mt-5 p-5">
            <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Invoices</h2>
            <div class="mt-3 space-y-2">
                @foreach ($order->invoices as $invoice)
                    <div wire:key="inv-{{ $invoice->id }}" class="flex items-center justify-between gap-3 {{ $loop->index > 0 ? 'border-t border-border pt-2' : '' }}">
                        <p class="text-[14px] font-semibold text-ink">{{ $invoice->number ?? 'Draft invoice' }}</p>
                        <p class="tnum text-[13.5px] font-semibold text-ink">{{ $money($invoice->total) }} {{ $currency }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($order->notes)
        <div class="card mt-5 p-5">
            <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Notes</h2>
            <p class="mt-2 whitespace-pre-line text-[13.5px] leading-relaxed text-ink-2">{{ $order->notes }}</p>
        </div>
    @endif
</div>
