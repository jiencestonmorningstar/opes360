@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('products') }}" wire:navigate
       class="focusable -ml-1.5 inline-flex min-h-[24px] items-center gap-1.5 rounded-lg px-1.5 py-1 text-[13.5px] font-semibold text-muted hover:text-ink-2">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Products
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[23px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[26px]">Stock value</h1>
            <p class="mt-1 text-[14px] text-muted">What is on the shelves, and what the books carry for it.</p>
        </div>

        @can('products.adjust-stock')
            <button type="button" wire:click="$set('starting', true)"
                    class="tap focusable flex h-12 shrink-0 items-center justify-center rounded-xl bg-fill-brand px-5 text-[15px] font-semibold text-white hover:opacity-90">
                Start a count
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    {{-- The two figures and the gap between them, which is the whole point of
         the page: a difference is not an error, it is work not yet done. --}}
    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-3">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">On the shelves</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($onShelf) }}</p>
            <p class="mt-1 text-[12px] text-faint">At weighted average cost.</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">In the books</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($inBooks) }}</p>
            <p class="mt-1 text-[12px] text-faint">Account 31 Marchandises.</p>
        </div>
        <div class="card p-4 {{ abs($difference) > 0.005 ? 'ring-1 ring-warning/30' : '' }}">
            <p class="text-[12.5px] font-medium text-muted">Not yet posted</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] {{ abs($difference) > 0.005 ? 'text-warning' : 'text-positive' }}">
                {{ $money($difference) }}
            </p>
            <p class="mt-1 text-[12px] text-faint">
                {{ abs($difference) > 0.005 ? 'A count will post this to 6031.' : 'The shelves and the books agree.' }}
            </p>
        </div>
    </div>

    {{-- A valuation that quietly leaves things out is worse than no valuation,
         so the items it could not price are named. --}}
    @if ($unpriced->isNotEmpty())
        <div class="mt-4 rounded-xl bg-tint-amber px-4 py-3.5">
            <p class="text-[13.5px] font-semibold text-warning">
                {{ $unpriced->count() }} {{ Str::plural('item', $unpriced->count()) }} in stock with no cost price
            </p>
            <p class="mt-1 text-[13px] leading-relaxed text-muted">
                These are counted as worth nothing, so the total above is lower than the truth. Put a cost on each and
                the valuation corrects itself.
            </p>
            <div class="mt-2.5 flex flex-wrap gap-2">
                @foreach ($unpriced->take(12) as $item)
                    <a href="{{ route('products.edit', $item) }}" wire:navigate
                       class="focusable inline-flex min-h-[28px] items-center rounded-lg bg-surface px-2.5 py-1 text-[12.5px] font-semibold text-ink-2 hover:text-brand">
                        {{ $item->name }}
                    </a>
                @endforeach
                @if ($unpriced->count() > 12)
                    <span class="inline-flex min-h-[28px] items-center px-1 text-[12.5px] text-muted">
                        and {{ $unpriced->count() - 12 }} more
                    </span>
                @endif
            </div>
        </div>
    @endif

    @if ($starting)
        <div class="card mt-4 border-brand p-5">
            <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Start a count</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                This opens a sheet listing everything you track, with what the system thinks you have. Count at your own
                pace — nothing changes until you post it.
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="counted-on">Counted on</label>
                    <input id="counted-on" type="date" wire:model="countedOn" class="{{ $inputClass }}">
                    @error('countedOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                @if ($locations->isNotEmpty())
                    <div>
                        <label class="{{ $labelClass }}" for="count-location">Where</label>
                        <select id="count-location" wire:model="locationId" class="{{ $inputClass }}">
                            <option value="">Everywhere</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            <div class="mt-5 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="startCount"
                        class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                    Open the sheet
                </button>
                <button type="button" wire:click="$set('starting', false)"
                        class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <h2 class="mt-7 text-[16px] font-bold tracking-[-0.02em] text-ink">What you are holding</h2>

    <div class="card mt-3 p-2">
        @forelse ($holdings as $index => $row)
            <div wire:key="hold-{{ $row['item']->id }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[15px] font-semibold text-ink">{{ $row['item']->name }}</p>
                        <p class="tnum truncate text-[13px] text-muted">
                            {{ $qty($row['quantity']) }} × {{ $money($row['unit_cost']) }}
                        </p>
                    </div>
                    <p class="tnum shrink-0 text-[15px] font-bold text-ink">{{ $money($row['value']) }}</p>
                </div>
            </div>
        @empty
            <div class="px-4 py-10 text-center">
                <p class="text-[15px] font-semibold text-ink">Nothing in stock</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    Products with <span class="font-semibold">track stock</span> switched on appear here once they have
                    an opening quantity.
                </p>
            </div>
        @endforelse
    </div>

    @if ($counts->isNotEmpty())
        <h2 class="mt-7 text-[16px] font-bold tracking-[-0.02em] text-ink">Counts</h2>

        <div class="card mt-3 p-2">
            @foreach ($counts as $index => $count)
                <a href="{{ route('products.stock.count', $count) }}" wire:navigate wire:key="count-{{ $count->id }}"
                   class="focusable flex items-center gap-3 rounded-xl px-3 py-3.5 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[15px] font-semibold text-ink">{{ $count->reference }}</p>
                        <p class="truncate text-[13px] text-muted">
                            {{ $count->counted_on->format('j M Y') }}@if ($count->location) · {{ $count->location->name }} @endif
                        </p>
                    </div>
                    @if ($count->isPosted())
                        <p class="tnum shrink-0 text-right text-[14px] font-semibold text-ink">{{ $money($count->total_value) }}</p>
                    @endif
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[12px] font-semibold
                                 {{ $count->isPosted() ? 'bg-tint-green text-positive' : ($count->isVoid() ? 'bg-tint-slate text-accent-slate' : 'bg-tint-amber text-warning') }}">
                        {{ $count->statusLabel() }}
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    <p class="mt-6 text-[12.5px] leading-relaxed text-faint">
        Stock is valued at the weighted average of what you paid for it (CUMP), one of the two methods the AUDCIF
        allows. Purchases are charged to 601 as they happen; posting a count carries what is left onto the balance
        sheet in 31 and puts the movement through 6031, so achats less variation is the cost of what you actually sold.
    </p>
</div>
