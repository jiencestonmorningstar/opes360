@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

{{-- State lives in Alpine so an item can be added with no connection —
     see resources/js/forms/record.js. --}}
<div class="px-5 pb-8 lg:px-6 lg:pt-6"
     x-data="opesRecordForm({
        entityType: 'item',
        isEdit: @js((bool) $item),
        initial: @js($initial),
        rules: {
            name: { required: true },
            price: { required: true, numeric: true, message: 'Give it a selling price — zero is fine.' },
            cost: { numeric: true },
            reorder_level: { numeric: true },
        },
        toPayload: (form) => ({
            type: form.type,
            name: form.name.trim(),
            sku: form.sku || null,
            barcode: form.barcode || null,
            unit: form.unit || 'unit',
            price: Number(form.price),
            cost: form.cost === '' ? null : Number(form.cost),
            description: form.description || null,
            track_stock: form.type === 'product' && !! form.track_stock,
            reorder_level: form.reorder_level === '' ? null : Number(form.reorder_level),
            is_active: !! form.is_active,
        }),
        label: (form) => form.name,
     })">

    <div class="flex items-center gap-3">
        <a href="{{ route('products', ['type' => $type]) }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
            {{ $item ? 'Edit '.ucfirst($type) : 'New '.ucfirst($type) }}
        </h1>
    </div>

    <template x-if="savedOffline">
        <div class="card mx-auto mt-5 max-w-[640px] p-6 text-center">
            <span class="mx-auto flex size-[52px] items-center justify-center rounded-full bg-tint-green">
                <x-icon name="check-circle" class="size-[26px] text-accent-green" stroke-width="2.2" />
            </span>
            <h2 class="mt-4 text-[19px] font-bold tracking-[-0.02em] text-ink">
                <span x-text="savedOffline.name"></span> saved on this device
            </h2>
            <p class="mt-1.5 text-[14px] text-muted">It will sync when you're back online.</p>
            <div class="mt-5 flex gap-3">
                <a href="{{ route('products', ['type' => $type]) }}"
                   class="focusable flex h-12 flex-1 items-center justify-center rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                    Done
                </a>
                <button type="button" @click="startAnother()"
                        class="focusable h-12 flex-[1.4] rounded-xl bg-brand text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    Add Another
                </button>
            </div>
        </div>
    </template>

    <div class="mx-auto mt-5 max-w-[640px] space-y-4" x-show="! savedOffline">

        @unless ($item)
            <div class="grid grid-cols-2 gap-2">
                @foreach (['product' => 'Product', 'service' => 'Service'] as $key => $label)
                    <button type="button" @click="form.type = @js($key)"
                            class="focusable h-11 rounded-xl text-[14px] font-semibold transition-colors"
                            :class="form.type === @js($key)
                                ? 'bg-tint-blue text-brand ring-1 ring-brand/40'
                                : 'border border-border bg-surface text-ink-2 hover:bg-surface-2'">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        @endunless

        <x-ui.panel title="Basics">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="min-[560px]:col-span-2">
                    <span class="{{ $labelClass }}">Name</span>
                    <input type="text" x-model="form.name" class="{{ $inputClass }}">
                    <p class="mt-1 text-[12.5px] font-medium text-warning" x-cloak
                       x-show="error('name')" x-text="error('name')"></p>
                </label>
                <label>
                    <span class="{{ $labelClass }}">SKU</span>
                    <input type="text" x-model="form.sku" class="{{ $inputClass }}">
                </label>
                <label>
                    <span class="{{ $labelClass }}">Barcode</span>
                    <input type="text" x-model="form.barcode" class="{{ $inputClass }}">
                </label>
                <label class="min-[560px]:col-span-2">
                    <span class="{{ $labelClass }}">Description</span>
                    <textarea x-model="form.description" rows="2"
                              class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                </label>
            </div>
        </x-ui.panel>

        <x-ui.panel title="Pricing">
            <div class="grid grid-cols-3 gap-3">
                <label>
                    <span class="{{ $labelClass }}">Price</span>
                    <input type="number" step="any" min="0" inputmode="decimal" x-model="form.price" class="tnum {{ $inputClass }}">
                    <p class="mt-1 text-[12.5px] font-medium text-warning" x-cloak
                       x-show="error('price')" x-text="error('price')"></p>
                </label>
                <label>
                    <span class="{{ $labelClass }}">Cost</span>
                    <input type="number" step="any" min="0" inputmode="decimal" x-model="form.cost" class="tnum {{ $inputClass }}">
                </label>
                <label>
                    <span class="{{ $labelClass }}">Unit</span>
                    <input type="text" x-model="form.unit" placeholder="unit, kg, hour…" class="{{ $inputClass }}">
                </label>
            </div>
        </x-ui.panel>

        <div x-show="form.type === 'product'" x-cloak>
            <x-ui.panel title="Inventory">
                <label class="flex items-center justify-between gap-4">
                    <span>
                        <span class="block text-[14.5px] font-semibold text-ink">Track stock</span>
                        <span class="block text-[12.5px] text-muted">Counts every sale and warns when you run low.</span>
                    </span>
                    <input type="checkbox" x-model="form.track_stock"
                           class="size-6 rounded border-border-strong text-brand focus:ring-brand/30">
                </label>

                <div class="mt-4 grid grid-cols-2 gap-3" x-show="form.track_stock" x-cloak>
                    @unless ($item)
                        <label>
                            <span class="{{ $labelClass }}">Opening stock</span>
                            <input type="number" step="any" min="0" inputmode="decimal" x-model="form.opening_stock" class="tnum {{ $inputClass }}">
                        </label>
                    @endunless
                    <label>
                        <span class="{{ $labelClass }}">Low-stock alert at</span>
                        <input type="number" step="any" min="0" inputmode="decimal" x-model="form.reorder_level" class="tnum {{ $inputClass }}">
                    </label>
                </div>
            </x-ui.panel>
        </div>

        <x-ui.panel title="Availability">
            <label class="flex items-center justify-between gap-4">
                <span>
                    <span class="block text-[14.5px] font-semibold text-ink">Active</span>
                    <span class="block text-[12.5px] text-muted">Inactive items stay in records but can't be added to new documents.</span>
                </span>
                <input type="checkbox" x-model="form.is_active"
                       class="size-6 rounded border-border-strong text-brand focus:ring-brand/30">
            </label>
        </x-ui.panel>

        <p class="rounded-lg bg-tint-orange px-3 py-2 text-[12.5px] font-medium text-warning" x-cloak
           x-show="error('form')" x-text="error('form')"></p>

        <button type="button" @click="save()" :disabled="saving"
                class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60">
            <span x-show="! saving">{{ $item ? 'Save Changes' : 'Add '.ucfirst($type) }}</span>
            <span x-show="saving" x-cloak>Saving…</span>
        </button>
    </div>
</div>
