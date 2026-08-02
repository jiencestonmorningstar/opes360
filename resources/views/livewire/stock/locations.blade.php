@php
    use App\Models\StockLocation;
    use App\Support\Accent;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('products') }}" wire:navigate
       class="focusable -ml-1.5 inline-flex min-h-[24px] items-center gap-1.5 rounded-lg px-1.5 py-1 text-[13.5px] font-semibold text-muted hover:text-ink-2">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Products
    </a>

    <div class="mt-3 flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Stock locations</h1>
            <p class="mt-1 text-[14.5px] text-muted">Where things actually are, and moving them between places.</p>
        </div>

        <button type="button" wire:click="startAdding"
                class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
            <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
            <span class="sr-only min-[420px]:not-sr-only">Add</span>
        </button>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    @if ($adding)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Add a place</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                A shop, a store room, a delivery van — anywhere stock sits and can be counted separately.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="loc-name">Name</label>
                    <input id="loc-name" type="text" wire:model="name" class="{{ $inputClass }}" placeholder="Boutique Akwa">
                    @error('name') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="loc-kind">Kind</label>
                    <select id="loc-kind" wire:model="kind" class="{{ $inputClass }}">
                        @foreach (StockLocation::KINDS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="loc-code">Short code <span class="font-normal text-faint">(optional)</span></label>
                    <input id="loc-code" type="text" wire:model="code" class="{{ $inputClass }}" placeholder="AKW">
                    @error('code') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="loc-city">Town</label>
                    <input id="loc-city" type="text" wire:model="city" class="{{ $inputClass }}" placeholder="Douala">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="loc-manager">Who runs it <span class="font-normal text-faint">(optional)</span></label>
                    <input id="loc-manager" type="text" wire:model="manager" class="{{ $inputClass }}">
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Add place
                </button>
                <button type="button" wire:click="$set('adding', false)"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    @if ($locations->isEmpty())
        <div class="card mt-5 px-4 py-12 text-center">
            <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                <x-icon name="cube" class="size-[24px] text-accent-slate" stroke-width="1.7" />
            </span>
            <p class="mt-4 text-[15.5px] font-semibold text-ink">Nowhere set up yet</p>
            <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                Add a place and stock can be counted per shop, per store room, per van — so running out at the
                counter while a case sits in the back stops being invisible.
            </p>
        </div>
    @else
        @if ($locations->count() > 1)
            <div class="mt-5 flex justify-end">
                <button type="button" wire:click="startTransfer"
                        class="tap focusable flex items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    <x-icon name="sync" class="size-[17px]" stroke-width="2.2" />
                    Move stock
                </button>
            </div>
        @endif

        @if ($transferring)
            <div class="card mt-4 p-5">
                <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Move stock</h2>
                <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                    Nothing is created or destroyed — the total across every place stays the same.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="{{ $labelClass }}" for="t-from">From</label>
                        <select id="t-from" wire:model="fromId" class="{{ $inputClass }}">
                            <option value="">Choose…</option>
                            @foreach ($locations as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('fromId') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="t-to">To</label>
                        <select id="t-to" wire:model="toId" class="{{ $inputClass }}">
                            <option value="">Choose…</option>
                            @foreach ($locations as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('toId') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="t-date">On</label>
                        <input id="t-date" type="date" wire:model="movedOn" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    @foreach ($transferLines as $index => $line)
                        <div wire:key="tl-{{ $index }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div class="flex-1">
                                <label class="{{ $labelClass }}" for="tl-item-{{ $index }}">Item</label>
                                <select id="tl-item-{{ $index }}" wire:model="transferLines.{{ $index }}.item_id" class="{{ $inputClass }}">
                                    <option value="">Choose…</option>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}">{{ $item->name }}@if ($item->sku) — {{ $item->sku }}@endif</option>
                                    @endforeach
                                </select>
                                @error('transferLines.'.$index.'.item_id') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:w-[140px]">
                                <label class="{{ $labelClass }}" for="tl-qty-{{ $index }}">Quantity</label>
                                <input id="tl-qty-{{ $index }}" type="number" step="any" min="0" inputmode="decimal"
                                       wire:model="transferLines.{{ $index }}.quantity" class="{{ $inputClass }} tnum">
                                @error('transferLines.'.$index.'.quantity') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                            </div>
                            @if (count($transferLines) > 1)
                                <button type="button" wire:click="removeTransferLine({{ $index }})"
                                        aria-label="Remove this line"
                                        class="tap focusable flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-border text-muted hover:bg-tint-red hover:text-negative">
                                    ×
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>

                @error('transferLines') <p class="mt-3 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror

                <button type="button" wire:click="addTransferLine"
                        class="focusable mt-3 rounded-lg px-3 py-2 text-[13.5px] font-semibold text-brand hover:bg-tint-blue">
                    + Another item
                </button>

                <div class="mt-4">
                    <label class="{{ $labelClass }}" for="t-note">Note <span class="font-normal text-faint">(optional)</span></label>
                    <input id="t-note" type="text" wire:model="transferNote" class="{{ $inputClass }}" placeholder="Réassort boutique">
                </div>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                    <button type="button" wire:click="saveTransfer"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                        Move it
                    </button>
                    <button type="button" wire:click="$set('transferring', false)"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Choose a location">
            @foreach ($locations as $location)
                <button type="button" wire:click="$set('locationId', '{{ $location->id }}')"
                        aria-pressed="{{ $selected?->id === $location->id ? 'true' : 'false' }}"
                        class="focusable flex h-10 shrink-0 items-center gap-2 rounded-full px-4 text-[13.5px] font-semibold transition-colors
                               {{ $selected?->id === $location->id ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                    {{ $location->name }}
                    @if ($location->is_default)
                        <span class="text-[11px] opacity-70">default</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($selected)
            <div class="card mt-4 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">{{ $selected->label() }}</h2>
                        <p class="mt-1 text-[13.5px] text-muted">
                            {{ $selected->kindLabel() }}
                            @if ($selected->city) · {{ $selected->city }} @endif
                            @if ($selected->manager) · {{ $selected->manager }} @endif
                        </p>
                    </div>
                    @unless ($selected->is_default)
                        <button type="button" wire:click="makeDefault('{{ $selected->id }}')"
                                class="focusable rounded-lg bg-surface-2 px-3 py-2 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                            Make this the default
                        </button>
                    @endunless
                </div>
            </div>

            <div class="card mt-4 p-2">
                @forelse ($contents as $row)
                    @php $accent = Accent::forKey($row['item']->id); @endphp
                    <div wire:key="c-{{ $row['item']->id }}" class="flex items-center gap-3.5 rounded-xl px-3 py-3 {{ $loop->index > 0 ? 'border-t border-border' : '' }}">
                        <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }}">
                            <x-icon name="cube" class="size-[16px] {{ Accent::text($accent) }}" stroke-width="1.9" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[14.5px] font-semibold text-ink">{{ $row['item']->name }}</p>
                            @if ($row['item']->sku)
                                <p class="truncate text-[12.5px] text-muted">{{ $row['item']->sku }}</p>
                            @endif
                        </div>
                        <p class="tnum shrink-0 text-[15px] font-bold {{ $row['quantity'] < 0 ? 'text-negative' : 'text-ink' }}">
                            {{ $qty($row['quantity']) }}
                        </p>
                    </div>
                @empty
                    <p class="px-4 py-10 text-center text-[14px] text-muted">Nothing counted here yet.</p>
                @endforelse
            </div>
        @endif

        @if (abs((float) $unattributed) > 0.0005)
            <p class="mt-4 rounded-xl bg-tint-amber px-4 py-3 text-[13px] leading-relaxed text-warning">
                {{ $qty($unattributed) }} units of stock are recorded without a place — everything counted before you
                set locations up. They still count towards each product's total; they just are not attributed here.
            </p>
        @endif

        @if ($transfers->isNotEmpty())
            <h2 class="mt-7 text-[16px] font-bold tracking-[-0.02em] text-ink">Recent moves</h2>
            <div class="card mt-3 p-2">
                @foreach ($transfers as $transfer)
                    <div wire:key="t-{{ $transfer->id }}" class="flex items-center gap-3 rounded-xl px-3 py-3 {{ $loop->index > 0 ? 'border-t border-border' : '' }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[14.5px] font-semibold text-ink">
                                {{ $transfer->from?->name }} → {{ $transfer->to?->name }}
                            </p>
                            <p class="truncate text-[12.5px] text-muted">
                                {{ $transfer->moved_on?->format('j M Y') }}
                                · {{ $transfer->lines->count() }} {{ Str::plural('item', $transfer->lines->count()) }}
                                @if ($transfer->note) · {{ $transfer->note }} @endif
                            </p>
                        </div>
                        <p class="tnum shrink-0 text-[14px] font-bold text-ink">{{ $qty($transfer->totalQuantity()) }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
