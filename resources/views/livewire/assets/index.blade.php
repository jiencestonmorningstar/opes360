@php
    use App\Models\FixedAsset;
    use App\Support\Accent;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Assets</h1>
            <p class="mt-1 text-[14.5px] text-muted">What the business owns, and what it is still worth.</p>
        </div>

        @can('assets.create')
            <button type="button" wire:click="startAdding"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="hidden min-[420px]:inline">Add</span>
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    {{-- Cost and book value side by side, because the gap between them is the
         whole point: it is a real cost most small businesses have never seen
         written down anywhere. One per row below 400px: two FCFA figures side
         by side would each get 154px, and a 19px bold number does not shrink
         to fit — it pushes the page wider instead. --}}
    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-3">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Cost</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($totalCost) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Still worth</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($totalBookValue) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Charged this year</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($chargedThisYear) }}</p>
        </div>
    </div>

    @can('assets.depreciate')
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Charge a month's depreciation</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Spreads each asset's cost over the months it will actually be used, and posts the charge to the books.
                Running the same month twice is harmless — nothing is charged a second time.
            </p>

            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="sm:max-w-[220px] sm:flex-1">
                    <label class="{{ $labelClass }}" for="dep-period">Month</label>
                    <input id="dep-period" type="date" wire:model="period" class="{{ $inputClass }}">
                    @error('period') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="runDepreciation"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Charge depreciation
                </button>
            </div>
        </div>
    @endcan

    @if ($adding)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Add an asset</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Something the business will keep using after this month — a van, a generator, a shop fitting.
                It is not an expense: its cost is spread over the years it is used, which is also how the DGI sees it.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="a-name">What is it?</label>
                    <input id="a-name" type="text" wire:model="name" class="{{ $inputClass }}" placeholder="Toyota Hiace — livraisons">
                    @error('name') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="a-category">Category</label>
                    <select id="a-category" wire:model.live="category" class="{{ $inputClass }}">
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="a-cost">Cost before tax</label>
                    <input id="a-cost" type="number" step="1" min="0" inputmode="numeric" wire:model="cost" class="{{ $inputClass }} tnum">
                    @error('cost') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="a-acquired">Bought on</label>
                    <input id="a-acquired" type="date" wire:model="acquiredOn" class="{{ $inputClass }}">
                    @error('acquiredOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="a-funded">Paid by</label>
                    <select id="a-funded" wire:model="fundedBy" class="{{ $inputClass }}">
                        <option value="bank">Bank transfer</option>
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="cheque">Cheque</option>
                        <option value="credit">On credit — still owed</option>
                    </select>
                </div>

                @if ($category !== 'land')
                    <div>
                        <label class="{{ $labelClass }}" for="a-life">Useful life (months)</label>
                        <input id="a-life" type="number" step="1" min="0" inputmode="numeric" wire:model="usefulLifeMonths" class="{{ $inputClass }} tnum">
                        @error('usefulLifeMonths') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-[12.5px] leading-relaxed text-faint">
                            Suggested from the category. Your accountant may use a different figure for tax.
                        </p>
                    </div>

                    <div>
                        <label class="{{ $labelClass }}" for="a-method">Method</label>
                        <select id="a-method" wire:model="method" class="{{ $inputClass }}">
                            @foreach (FixedAsset::METHODS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="{{ $labelClass }}" for="a-residual">Worth at the end</label>
                        <input id="a-residual" type="number" step="1" min="0" inputmode="numeric" wire:model="residualValue" class="{{ $inputClass }} tnum">
                        @error('residualValue') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}" for="a-opening">Already written off</label>
                        <input id="a-opening" type="number" step="1" min="0" inputmode="numeric" wire:model.live.debounce.500ms="openingAccumulated" class="{{ $inputClass }} tnum">
                        <p class="mt-1.5 text-[12.5px] leading-relaxed text-faint">
                            Leave at zero for something just bought. Fill it in for an asset carried over from
                            another system — it is already in those books, so this one will not post it again.
                        </p>
                    </div>
                @else
                    <div class="sm:col-span-2 rounded-xl bg-surface-2 p-4">
                        <p class="text-[13px] leading-relaxed text-muted">
                            Land is not depreciated — it does not wear out. It will sit on the balance sheet at cost
                            until it is sold.
                        </p>
                    </div>
                @endif

                <div>
                    <label class="{{ $labelClass }}" for="a-supplier">Supplier <span class="font-normal text-faint">(optional)</span></label>
                    <select id="a-supplier" wire:model="supplierId" class="{{ $inputClass }}">
                        <option value="">None</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->displayName() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="a-ref">Tag or plate <span class="font-normal text-faint">(optional)</span></label>
                    <input id="a-ref" type="text" wire:model="reference" class="{{ $inputClass }}" placeholder="LT 4471 AB">
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="a-location">Where it is <span class="font-normal text-faint">(optional)</span></label>
                    <input id="a-location" type="text" wire:model="location" class="{{ $inputClass }}" placeholder="Bonabéri">
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Add asset
                </button>
                <button type="button" wire:click="cancel"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <div class="relative mt-5">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.300ms="search" aria-label="Search assets"
               placeholder="Search by name, tag or location…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="tablist">
        @foreach (['active' => 'Owned', 'disposed' => 'Gone', 'all' => 'Everything'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')" role="tab"
                    aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($assets as $index => $asset)
            @php $accent = Accent::forKey($asset->category); @endphp
            <div wire:key="{{ $asset->id }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <div class="flex items-center gap-3.5">
                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }}">
                        <x-icon name="briefcase" class="size-[18px] {{ Accent::text($accent) }}" stroke-width="1.9" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[15px] font-semibold text-ink">{{ $asset->name }}</p>
                        <p class="truncate text-[13px] text-muted">
                            {{ $asset->categoryLabel() }}
                            @if ($asset->reference) · {{ $asset->reference }} @endif
                            · {{ $asset->acquired_on?->format('M Y') }}
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="tnum text-[14px] font-bold text-ink sm:text-[15px]">{{ $money($asset->bookValue()) }}</p>
                        @if ($asset->isDisposed())
                            <p class="text-[11.5px] font-semibold text-faint">
                                {{ $asset->status === 'disposed' ? 'Sold' : 'Written off' }}
                                {{ $asset->disposed_on?->format('M Y') }}
                            </p>
                        @elseif (! $asset->isDepreciable())
                            <p class="text-[11.5px] font-semibold text-muted">Not depreciated</p>
                        @elseif ($asset->isFullyDepreciated())
                            <p class="text-[11.5px] font-semibold text-warning">Fully written off</p>
                        @else
                            <p class="tnum text-[11.5px] font-medium text-faint">of {{ $money($asset->cost) }}</p>
                        @endif
                    </div>
                </div>

                @if (! $asset->isDisposed())
                    @can('assets.dispose')
                        <div class="mt-2.5 flex flex-wrap gap-2 pl-[54px]">
                            <button type="button" wire:click="startDisposing('{{ $asset->id }}')"
                                    class="focusable rounded-lg bg-surface-2 px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                                Sell or scrap
                            </button>
                        </div>
                    @endcan
                @endif

                @if ($disposing === $asset->id)
                    <div class="mt-3 rounded-xl border border-brand bg-tint-blue/50 p-4">
                        <p class="text-[13px] leading-relaxed text-muted">
                            Its remaining book value of {{ $money($asset->bookValue()) }} becomes a cost, and whatever
                            you were paid becomes income. The gain or loss is the difference — you do not have to work
                            it out.
                        </p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="{{ $labelClass }}" for="d-date-{{ $asset->id }}">Date</label>
                                <input id="d-date-{{ $asset->id }}" type="date" wire:model="disposedOn" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}" for="d-proceeds-{{ $asset->id }}">Amount received</label>
                                <input id="d-proceeds-{{ $asset->id }}" type="number" step="1" min="0" inputmode="numeric"
                                       wire:model="proceeds" class="{{ $inputClass }} tnum">
                                @error('proceeds') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}" for="d-into-{{ $asset->id }}">Received into</label>
                                <select id="d-into-{{ $asset->id }}" wire:model="receivedBy" class="{{ $inputClass }}">
                                    <option value="bank">Bank</option>
                                    <option value="cash">Cash</option>
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="cheque">Cheque</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                            <button type="button" wire:click="dispose"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                                Confirm
                            </button>
                            <button type="button" wire:click="cancel"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-12 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="briefcase" class="size-[24px] text-accent-slate" stroke-width="1.7" />
                </span>
                <p class="mt-4 text-[15.5px] font-semibold text-ink">
                    {{ $search !== '' ? 'Nothing matches that.' : 'Nothing on the register yet' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    {{ $search !== ''
                        ? 'Try a different search, or clear the filter.'
                        : 'A van put through as an expense wrecks one month and flatters every month after it. Record it here and its cost is spread over the years you actually use it.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($assets->hasPages())
        <div class="mt-5">{{ $assets->links() }}</div>
    @endif
</div>
