@php
    use App\Support\Accent;

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

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Orders</h1>
            <p class="mt-1 text-[14.5px] text-muted">What customers are owed, what can ship today, and what is short.</p>
        </div>

        @can('orders.manage')
            <button type="button" wire:click="startDrafting"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="sr-only min-[420px]:not-sr-only">Order</span>
            </button>
        @endcan
    </div>

    {{-- The fulfilment board: the despatch desk's three numbers. --}}
    @if ($board->isNotEmpty())
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div class="card p-4">
                <p class="text-[12.5px] font-semibold uppercase tracking-wide text-muted">Promised</p>
                <p class="tnum mt-1 text-[22px] font-bold text-ink">{{ $qty($board->sum('promised')) }}</p>
                <p class="text-[12.5px] text-muted">units still owed to customers</p>
            </div>
            <div class="card p-4">
                <p class="text-[12.5px] font-semibold uppercase tracking-wide text-muted">Shippable today</p>
                <p class="tnum mt-1 text-[22px] font-bold text-positive">{{ $qty($board->sum('shippable')) }}</p>
                <p class="text-[12.5px] text-muted">units picked and held</p>
            </div>
            <div class="card p-4">
                <p class="text-[12.5px] font-semibold uppercase tracking-wide text-muted">Short</p>
                <p class="tnum mt-1 text-[22px] font-bold {{ $board->sum('short') > 0 ? 'text-negative' : 'text-ink' }}">{{ $qty($board->sum('short')) }}</p>
                <p class="text-[12.5px] text-muted">units backordered</p>
            </div>
        </div>
    @endif

    {{-- Filter --}}
    <div class="mt-5 flex gap-2" role="group" aria-label="Filter orders">
        @foreach (['open' => 'Open', 'all' => 'All'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- New order --}}
    @if ($drafting)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">New order</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                A draft promises nothing. Confirming it is what reserves the stock — and names anything that cannot be held.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="ord-contact">Customer</label>
                    <select id="ord-contact" wire:model="contactId" class="{{ $inputClass }}">
                        <option value="">Choose a customer…</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </select>
                    @error('contactId') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="ord-promised">Promised for <span class="font-normal text-faint">(optional)</span></label>
                    <input id="ord-promised" type="date" wire:model="promisedDate" class="{{ $inputClass }}">
                </div>
            </div>

            <div class="mt-5 space-y-3">
                @foreach ($lines as $index => $line)
                    <div wire:key="ol-{{ $index }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label class="{{ $labelClass }}" for="ol-item-{{ $index }}">Product</label>
                            <select id="ol-item-{{ $index }}" wire:model="lines.{{ $index }}.item_id" class="{{ $inputClass }}">
                                <option value="">Choose…</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}@if ($product->sku) — {{ $product->sku }}@endif</option>
                                @endforeach
                            </select>
                            @error('lines.'.$index.'.item_id') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:w-[120px]">
                            <label class="{{ $labelClass }}" for="ol-qty-{{ $index }}">Quantity</label>
                            <input id="ol-qty-{{ $index }}" type="number" step="any" min="0" inputmode="decimal"
                                   wire:model="lines.{{ $index }}.quantity" class="{{ $inputClass }} tnum">
                            @error('lines.'.$index.'.quantity') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:w-[150px]">
                            <label class="{{ $labelClass }}" for="ol-price-{{ $index }}">Unit price <span class="font-normal text-faint">(optional)</span></label>
                            <input id="ol-price-{{ $index }}" type="number" step="any" min="0" inputmode="decimal"
                                   wire:model="lines.{{ $index }}.unit_price" class="{{ $inputClass }} tnum" placeholder="Catalogue">
                        </div>
                        @if (count($lines) > 1)
                            <button type="button" wire:click="removeLine({{ $index }})" aria-label="Remove this line"
                                    class="tap focusable flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-border text-muted hover:bg-tint-red hover:text-negative">
                                ×
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            @error('lines') <p class="mt-3 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror

            <button type="button" wire:click="addLine"
                    class="focusable mt-3 rounded-lg px-3 py-2 text-[13.5px] font-semibold text-brand hover:bg-tint-blue">
                + Another line
            </button>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Draft order
                </button>
                <button type="button" wire:click="$set('drafting', false)"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Order list --}}
    @if ($orders->isEmpty())
        <div class="card mt-5 px-4 py-12 text-center">
            <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                <x-icon name="truck" class="size-[24px] text-accent-slate" stroke-width="1.7" />
            </span>
            <p class="mt-4 text-[15.5px] font-semibold text-ink">No orders yet</p>
            <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                Draft an order for a customer, confirm it to reserve the stock, deliver it with a printed
                delivery note, then invoice exactly what went out.
            </p>
        </div>
    @else
        <div class="card mt-5 p-2">
            @foreach ($orders as $order)
                @php $accent = Accent::forKey($order->contact_id); @endphp
                <a href="{{ route('orders.show', $order) }}" wire:key="so-{{ $order->id }}"
                   class="focusable flex items-center gap-3.5 rounded-xl px-3 py-3 hover:bg-surface-2 {{ $loop->index > 0 ? 'border-t border-border' : '' }}">
                    <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }}">
                        <x-icon name="truck" class="size-[16px] {{ Accent::text($accent) }}" stroke-width="1.9" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[14.5px] font-semibold text-ink">{{ $order->contact?->name }}</p>
                        <p class="truncate text-[12.5px] text-muted">
                            {{ $order->number }} · {{ $money($order->total()) }} {{ $currency }}
                            @if ($order->backorderedTotal() > 0)
                                · <span class="font-semibold text-negative">{{ $qty($order->backorderedTotal()) }} backordered</span>
                            @endif
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-bold {{ $statusTint[$order->status] ?? 'bg-tint-slate text-ink-2' }}">
                        {{ $order->status }}
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
