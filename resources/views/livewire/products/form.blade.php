@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('products', ['type' => $type]) }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
            {{ $item ? 'Edit '.ucfirst($type) : 'New '.ucfirst($type) }}
        </h1>
    </div>

    <div class="mx-auto mt-5 max-w-[640px] space-y-4">

        @unless ($item)
            <div class="grid grid-cols-2 gap-2">
                @foreach (['product' => 'Product', 'service' => 'Service'] as $key => $label)
                    <button type="button" wire:click="$set('type', '{{ $key }}')"
                            class="focusable h-11 rounded-xl text-[14px] font-semibold transition-colors
                                   {{ $type === $key ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        @endunless

        <x-ui.panel title="Basics">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="min-[560px]:col-span-2">
                    <span class="{{ $labelClass }}">Name</span>
                    <input type="text" wire:model="form.name" class="{{ $inputClass }}">
                    @error('form.name') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label>
                    <span class="{{ $labelClass }}">SKU</span>
                    <input type="text" wire:model="form.sku" class="{{ $inputClass }}">
                </label>
                <label>
                    <span class="{{ $labelClass }}">Barcode</span>
                    <input type="text" wire:model="form.barcode" class="{{ $inputClass }}">
                </label>
                <label class="min-[560px]:col-span-2">
                    <span class="{{ $labelClass }}">Description</span>
                    <textarea wire:model="form.description" rows="2"
                              class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                </label>
            </div>
        </x-ui.panel>

        <x-ui.panel title="Pricing">
            <div class="grid grid-cols-3 gap-3">
                <label>
                    <span class="{{ $labelClass }}">Price</span>
                    <input type="number" step="any" min="0" inputmode="decimal" wire:model="form.price" class="tnum {{ $inputClass }}">
                    @error('form.price') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label>
                    <span class="{{ $labelClass }}">Cost</span>
                    <input type="number" step="any" min="0" inputmode="decimal" wire:model="form.cost" class="tnum {{ $inputClass }}">
                </label>
                <label>
                    <span class="{{ $labelClass }}">Unit</span>
                    <input type="text" wire:model="form.unit" placeholder="unit, kg, hour…" class="{{ $inputClass }}">
                </label>
            </div>
        </x-ui.panel>

        @if ($type === 'product')
            <x-ui.panel title="Inventory">
                <label class="flex items-center justify-between gap-4">
                    <span>
                        <span class="block text-[14.5px] font-semibold text-ink">Track stock</span>
                        <span class="block text-[12.5px] text-muted">Counts every sale and warns when you run low.</span>
                    </span>
                    <input type="checkbox" wire:model.live="form.track_stock"
                           class="size-6 rounded border-border-strong text-brand focus:ring-brand/30">
                </label>

                @if ($form['track_stock'])
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        @unless ($item)
                            <label>
                                <span class="{{ $labelClass }}">Opening stock</span>
                                <input type="number" step="any" min="0" inputmode="decimal" wire:model="openingStock" class="tnum {{ $inputClass }}">
                            </label>
                        @endunless
                        <label>
                            <span class="{{ $labelClass }}">Low-stock alert at</span>
                            <input type="number" step="any" min="0" inputmode="decimal" wire:model="form.reorder_level" class="tnum {{ $inputClass }}">
                        </label>
                    </div>
                @endif
            </x-ui.panel>
        @endif

        <x-ui.panel title="Availability">
            <label class="flex items-center justify-between gap-4">
                <span>
                    <span class="block text-[14.5px] font-semibold text-ink">Active</span>
                    <span class="block text-[12.5px] text-muted">Inactive items stay in records but can't be added to new documents.</span>
                </span>
                <input type="checkbox" wire:model="form.is_active"
                       class="size-6 rounded border-border-strong text-brand focus:ring-brand/30">
            </label>
        </x-ui.panel>

        <button type="button" wire:click="save" wire:loading.attr="disabled"
                class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
            <span wire:loading.remove wire:target="save">{{ $item ? 'Save Changes' : 'Add '.ucfirst($type) }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>
</div>
