@php
    use App\Models\ProductionOrder;
    use App\Support\Accent;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $statusTint = [
        'planned' => 'bg-tint-slate text-ink-2',
        'in-progress' => 'bg-tint-blue text-brand',
        'completed' => 'bg-tint-green text-positive',
        'cancelled' => 'bg-tint-red text-negative',
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Manufacturing</h1>
            <p class="mt-1 text-[14.5px] text-muted">What your products are made of, and orders to make more.</p>
        </div>

        @can('manufacturing.manage')
            <button type="button" wire:click="startBom"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="sr-only min-[420px]:not-sr-only">Recipe</span>
            </button>
        @endcan
    </div>

    {{-- Tabs --}}
    <div class="mt-5 flex gap-2" role="group" aria-label="Choose a view">
        @foreach (['orders' => 'Orders', 'boms' => 'Recipes'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $tab === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @error('orders')
        <div class="mt-4 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ $message }}</div>
    @enderror

    {{-- New recipe --}}
    @if ($addingBom)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">New recipe</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                What one run makes, and the components it uses up. Both sides are ordinary products from your catalogue.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="{{ $labelClass }}" for="bom-item">Makes</label>
                    <select id="bom-item" wire:model="bomItemId" class="{{ $inputClass }}">
                        <option value="">Choose a product…</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}@if ($product->sku) — {{ $product->sku }}@endif</option>
                        @endforeach
                    </select>
                    @error('bomItemId') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="bom-output">Units per run</label>
                    <input id="bom-output" type="number" step="any" min="0" inputmode="decimal" wire:model="bomOutput" class="{{ $inputClass }} tnum">
                    @error('bomOutput') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="bom-name">Name <span class="font-normal text-faint">(optional)</span></label>
                    <input id="bom-name" type="text" wire:model="bomName" class="{{ $inputClass }}" placeholder="Table basse — standard">
                </div>
            </div>

            <div class="mt-5 space-y-3">
                @foreach ($bomLines as $index => $line)
                    <div wire:key="bl-{{ $index }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label class="{{ $labelClass }}" for="bl-item-{{ $index }}">Component</label>
                            <select id="bl-item-{{ $index }}" wire:model="bomLines.{{ $index }}.item_id" class="{{ $inputClass }}">
                                <option value="">Choose…</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}@if ($product->sku) — {{ $product->sku }}@endif</option>
                                @endforeach
                            </select>
                            @error('bomLines.'.$index.'.item_id') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:w-[130px]">
                            <label class="{{ $labelClass }}" for="bl-qty-{{ $index }}">Quantity</label>
                            <input id="bl-qty-{{ $index }}" type="number" step="any" min="0" inputmode="decimal"
                                   wire:model="bomLines.{{ $index }}.quantity" class="{{ $inputClass }} tnum">
                            @error('bomLines.'.$index.'.quantity') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:w-[120px]">
                            <label class="{{ $labelClass }}" for="bl-scrap-{{ $index }}">Scrap %</label>
                            <input id="bl-scrap-{{ $index }}" type="number" step="any" min="0" max="100" inputmode="decimal"
                                   wire:model="bomLines.{{ $index }}.scrap" class="{{ $inputClass }} tnum" placeholder="0">
                        </div>
                        @if (count($bomLines) > 1)
                            <button type="button" wire:click="removeBomLine({{ $index }})" aria-label="Remove this component"
                                    class="tap focusable flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-border text-muted hover:bg-tint-red hover:text-negative">
                                ×
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            @error('bomLines') <p class="mt-3 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror

            <button type="button" wire:click="addBomLine"
                    class="focusable mt-3 rounded-lg px-3 py-2 text-[13.5px] font-semibold text-brand hover:bg-tint-blue">
                + Another component
            </button>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="saveBom"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Save recipe
                </button>
                <button type="button" wire:click="$set('addingBom', false)"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- New order --}}
    @if ($orderBomId)
        @php $orderBom = $boms->firstWhere('id', $orderBomId); @endphp
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Make {{ $orderBom?->label() }}</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Nothing moves yet — components are only consumed when the order is completed.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="ord-qty">How many</label>
                    <input id="ord-qty" type="number" step="any" min="0" inputmode="decimal" wire:model="orderQuantity" class="{{ $inputClass }} tnum">
                    @error('orderQuantity') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="ord-note">Note <span class="font-normal text-faint">(optional)</span></label>
                    <input id="ord-note" type="text" wire:model="orderNote" class="{{ $inputClass }}" placeholder="Commande client Mbarga">
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="createOrder"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Raise order
                </button>
                <button type="button" wire:click="$set('orderBomId', null)"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Orders --}}
    @if ($tab === 'orders')
        @if ($orders->isEmpty())
            <div class="card mt-5 px-4 py-12 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="cube" class="size-[24px] text-accent-slate" stroke-width="1.7" />
                </span>
                <p class="mt-4 text-[15.5px] font-semibold text-ink">No orders yet</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    Write a recipe first, then raise an order to make some. Completing the order consumes the
                    components and puts the finished goods on the shelf, all in one step.
                </p>
            </div>
        @else
            <div class="card mt-5 p-2">
                @foreach ($orders as $order)
                    @php $accent = Accent::forKey($order->item_id); @endphp
                    <div wire:key="o-{{ $order->id }}" class="rounded-xl px-3 py-3 {{ $loop->index > 0 ? 'border-t border-border' : '' }}">
                        <div class="flex items-center gap-3.5">
                            <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }}">
                                <x-icon name="cube" class="size-[16px] {{ Accent::text($accent) }}" stroke-width="1.9" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[14.5px] font-semibold text-ink">
                                    {{ $qty($order->quantity) }} × {{ $order->item?->name }}
                                </p>
                                <p class="truncate text-[12.5px] text-muted">
                                    {{ $order->reference }}
                                    @if ($order->isCompleted() && $order->unit_cost !== null)
                                        · {{ $money($order->unit_cost) }} {{ $currency }} / unit
                                    @endif
                                    @if ($order->note) · {{ $order->note }} @endif
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-bold {{ $statusTint[$order->status] ?? 'bg-tint-slate text-ink-2' }}">
                                {{ str_replace('-', ' ', $order->status) }}
                            </span>
                        </div>

                        @if ($order->isOpen())
                            <div class="mt-2.5 flex flex-wrap items-center gap-2 pl-[52px]">
                                @can('manufacturing.manage')
                                    @if ($order->status === ProductionOrder::STATUS_PLANNED)
                                        <button type="button" wire:click="start('{{ $order->id }}')"
                                                class="focusable rounded-lg bg-surface-2 px-3 py-2 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                                            Start
                                        </button>
                                    @endif
                                @endcan
                                @can('manufacturing.complete')
                                    <button type="button" wire:click="complete('{{ $order->id }}')"
                                            class="focusable rounded-lg bg-tint-green px-3 py-2 text-[12.5px] font-semibold text-positive hover:opacity-80">
                                        Complete
                                    </button>
                                @endcan
                                @can('manufacturing.manage')
                                    <button type="button" wire:click="cancel('{{ $order->id }}')"
                                            class="focusable rounded-lg px-3 py-2 text-[12.5px] font-semibold text-muted hover:bg-tint-red hover:text-negative">
                                        Cancel
                                    </button>
                                @endcan
                            </div>

                            @if ($lotFor === $order->id)
                                <div class="mt-3 flex flex-col gap-3 pl-[52px] sm:flex-row sm:items-end">
                                    <div class="flex-1 sm:max-w-[260px]">
                                        <label class="{{ $labelClass }}" for="lot-{{ $order->id }}">Lot number for the finished goods</label>
                                        <input id="lot-{{ $order->id }}" type="text" wire:model="lotCode" class="{{ $inputClass }}" placeholder="LOT-2026-014">
                                    </div>
                                    <button type="button" wire:click="complete('{{ $order->id }}')"
                                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14px] font-semibold text-white hover:opacity-90">
                                        Complete
                                    </button>
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Recipes --}}
    @if ($tab === 'boms')
        @if ($boms->isEmpty())
            <div class="card mt-5 px-4 py-12 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="clipboard" class="size-[24px] text-accent-slate" stroke-width="1.7" />
                </span>
                <p class="mt-4 text-[15.5px] font-semibold text-ink">No recipes yet</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    A recipe says what a finished product is made of — 4 planks and 16 screws make a table.
                    Write one and orders can be raised against it.
                </p>
            </div>
        @else
            <div class="mt-5 space-y-4">
                @foreach ($boms as $bom)
                    <div wire:key="b-{{ $bom->id }}" class="card p-5 {{ $bom->is_active ? '' : 'opacity-60' }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">{{ $bom->label() }}</h2>
                                <p class="mt-0.5 text-[12.5px] text-muted">
                                    One run makes {{ $qty($bom->output_quantity) }}
                                    @if ($bom->is_active)
                                        · can make <span class="font-semibold text-ink-2">{{ $qty($makeable[$bom->id] ?? 0) }}</span> now
                                    @else
                                        · switched off
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @can('manufacturing.manage')
                                    @if ($bom->is_active)
                                        <button type="button" wire:click="startOrder('{{ $bom->id }}')"
                                                class="focusable rounded-lg bg-fill-brand px-3.5 py-2 text-[12.5px] font-semibold text-white hover:opacity-90">
                                            Make some
                                        </button>
                                    @endif
                                    <button type="button" wire:click="toggleBom('{{ $bom->id }}')"
                                            class="focusable rounded-lg bg-surface-2 px-3 py-2 text-[12.5px] font-semibold text-ink-2 hover:bg-surface">
                                        {{ $bom->is_active ? 'Switch off' : 'Switch on' }}
                                    </button>
                                @endcan
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            @foreach ($bom->lines as $line)
                                <p wire:key="b-{{ $bom->id }}-{{ $line->id }}" class="flex items-baseline justify-between gap-3 text-[13.5px]">
                                    <span class="truncate text-ink-2">{{ $line->item?->name }}</span>
                                    <span class="tnum shrink-0 font-semibold text-ink">
                                        {{ $qty($line->quantity) }}@if ((float) $line->scrap_percent > 0)<span class="font-normal text-faint"> +{{ $qty($line->scrap_percent) }}% scrap</span>@endif
                                    </span>
                                </p>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
