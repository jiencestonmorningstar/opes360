@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('products') }}" wire:navigate
       class="focusable -ml-1.5 inline-flex min-h-[24px] items-center gap-1.5 rounded-lg px-1.5 py-1 text-[13.5px] font-semibold text-muted hover:text-ink-2">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Products
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[23px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[26px]">Replenishment</h1>
            <p class="mt-1 text-[14px] text-muted">
                What is running low, how fast, and whether the supplier can get there in time.
            </p>
        </div>

        @if ($canAccept && $rows->isNotEmpty())
            <button type="button" wire:click="accept" wire:loading.attr="disabled"
                    class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-5 text-[15px] font-semibold text-white hover:opacity-90">
                Raise requisitions
            </button>
        @endif
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @error('selected')
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ $message }}</div>
    @enderror

    {{-- The one distinction that changes what somebody does today. --}}
    @if ($urgent > 0)
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3.5">
            <p class="text-[13.5px] font-semibold text-negative">
                {{ $urgent }} {{ Str::plural('item', $urgent) }} will run out before a replacement can arrive
            </p>
            <p class="mt-1 text-[13px] leading-relaxed text-muted">
                At the current rate of sale, the shelf goes empty before the supplier's lead time is up. These are the
                rows to deal with first.
            </p>
        </div>
    @endif

    <div class="card mt-5 p-2">
        @forelse ($rows as $index => $row)
            <div wire:key="rep-{{ $row['item']->id }}"
                 class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }} {{ $row['will_run_out'] ? 'bg-tint-red/40' : '' }}">
                <label class="flex cursor-pointer items-start gap-3">
                    @if ($canAccept)
                        <input type="checkbox" wire:model="selected.{{ $row['item']->id }}"
                               class="mt-1 size-[18px] shrink-0 rounded border-border text-brand focus:ring-brand/30">
                    @endif

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate text-[15px] font-semibold text-ink">{{ $row['item']->name }}</p>
                            @if ($row['will_run_out'])
                                <span class="shrink-0 rounded-full bg-tint-red px-2 py-0.5 text-[11.5px] font-semibold text-negative">
                                    Will run out
                                </span>
                            @elseif (! $row['below_now'])
                                <span class="shrink-0 rounded-full bg-tint-amber px-2 py-0.5 text-[11.5px] font-semibold text-warning">
                                    Falling
                                </span>
                            @endif
                        </div>

                        <p class="tnum mt-0.5 text-[13px] text-muted">
                            {{ $qty($row['available']) }} available
                            @if ($row['reserved'] > 0)
                                ({{ $qty($row['on_hand']) }} on hand, {{ $qty($row['reserved']) }} promised)
                            @endif
                            · reorder at {{ $qty($row['reorder_level']) }}
                            @if ($row['days_of_cover'] !== null)
                                · {{ $qty($row['days_of_cover']) }} {{ Str::plural('day', $row['days_of_cover']) }} of cover
                            @endif
                        </p>

                        <p class="mt-0.5 truncate text-[13px] text-muted">
                            @if ($row['supplier'])
                                {{ $row['supplier']->name }} · about {{ $row['lead_days'] }} {{ Str::plural('day', $row['lead_days']) }} to deliver
                                @if ($row['estimated_unit_price'] !== null)
                                    · {{ $money($row['estimated_unit_price']) }} each
                                @endif
                            @else
                                <span class="text-warning">No supplier on file</span> — procurement will source one
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="tnum text-[15px] font-bold text-ink">{{ $qty($row['suggested_quantity']) }}</p>
                        <p class="text-[12px] text-faint">to order</p>
                    </div>
                </label>
            </div>
        @empty
            <div class="px-4 py-10 text-center">
                <p class="text-[15px] font-semibold text-ink">Nothing needs ordering</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    Everything with a reorder level is above it, and staying there. Items appear here when they fall
                    below their reorder level — or are selling fast enough to get there before a supplier could
                    deliver.
                </p>
            </div>
        @endforelse
    </div>

    <p class="mt-6 text-[12.5px] leading-relaxed text-faint">
        Ticked rows become draft purchase requisitions, one per supplier, priced at the supplier's last price. They
        follow the normal path from there: submitted from Procurement, approved through the workflow, then sourced or
        ordered — nothing is bought from this screen.
    </p>
</div>
