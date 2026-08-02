@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('products.stock') }}" wire:navigate
       class="focusable -ml-1.5 inline-flex min-h-[24px] items-center gap-1.5 rounded-lg px-1.5 py-1 text-[13.5px] font-semibold text-muted hover:text-ink-2">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Stock value
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[23px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[26px]">{{ $stocktake->reference }}</h1>
            <p class="mt-1 text-[14px] text-muted">
                {{ $stocktake->counted_on->format('j M Y') }}
                @if ($stocktake->location) · {{ $stocktake->location->name }} @endif
                · {{ $countedLines }} of {{ $total }} counted
            </p>
        </div>

        <span class="shrink-0 rounded-full px-3 py-1.5 text-[12.5px] font-semibold
                     {{ $stocktake->isPosted() ? 'bg-tint-green text-positive' : ($stocktake->isVoid() ? 'bg-tint-slate text-accent-slate' : 'bg-tint-amber text-warning') }}">
            {{ $stocktake->statusLabel() }}
        </span>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    {{-- The running total as it stands, so somebody counting can see the
         difference growing rather than discovering it at the end. --}}
    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-2">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Counted value</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $money($countedValue) }}</p>
            <p class="mt-1 text-[12px] text-faint">Uncounted lines are held at their book quantity.</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Difference found</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] {{ abs($varianceValue) > 0.005 ? ($varianceValue < 0 ? 'text-negative' : 'text-positive') : 'text-ink' }}">
                {{ $money($varianceValue) }}
            </p>
            <p class="mt-1 text-[12px] text-faint">
                {{ $varianceValue < -0.005 ? 'Less on the shelf than the system thought.' : ($varianceValue > 0.005 ? 'More on the shelf than the system thought.' : 'Nothing counted differs so far.') }}
            </p>
        </div>
    </div>

    @if ($stocktake->isDraft())
        <div class="mt-5 flex flex-wrap gap-3">
            @can('products.adjust-stock')
                <button type="button" wire:click="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    Save progress
                </button>
                <button type="button" wire:click="post"
                        wire:confirm="Post this count? Stock is corrected to what you counted and the difference goes to the books. This cannot be edited afterwards."
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Post the count
                </button>
                <button type="button" wire:click="voidCount"
                        wire:confirm="Discard this count sheet?"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl px-4 text-[14.5px] font-semibold text-negative hover:bg-tint-red">
                    Discard
                </button>
            @endcan
        </div>
    @elseif ($stocktake->isPosted())
        <div class="mt-5 rounded-xl bg-tint-blue px-4 py-3.5">
            <p class="text-[13.5px] font-semibold text-brand">Posted {{ $stocktake->posted_at?->format('j M Y') }}</p>
            <p class="mt-1 text-[13px] leading-relaxed text-muted">
                Stock was corrected to what was counted, and
                {{ $money(abs((float) $stocktake->variance_value)) }}
                {{ (float) $stocktake->variance_value < 0 ? 'was written off through' : 'was carried into stock through' }}
                account 6031.
            </p>
            @can('products.adjust-stock')
                <button type="button" wire:click="voidCount"
                        wire:confirm="Void this count? The adjustments and the journal entry are reversed, not deleted."
                        class="tap focusable mt-3 flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-negative hover:bg-tint-red">
                    Void this count
                </button>
            @endcan
        </div>
    @endif

    @if ($stocktake->isDraft() && $total > 0)
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="flex-1">
                <label class="sr-only" for="count-search">Search the sheet</label>
                <input id="count-search" type="search" wire:model.live.debounce.250ms="search"
                       placeholder="Find an item…" class="{{ $inputClass }}">
            </div>
            <label class="focusable flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-xl border border-border bg-surface px-3.5 text-[14px] font-semibold text-ink-2">
                <input type="checkbox" wire:model.live="onlyUncounted" class="size-[18px] rounded border-border text-brand focus:ring-brand/30">
                Only what is left
            </label>
        </div>
    @endif

    <div class="card mt-3 p-2">
        @forelse ($lines as $index => $line)
            <div wire:key="line-{{ $line->id }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1 basis-full sm:basis-0">
                        <p class="truncate text-[15px] font-semibold text-ink">{{ $line->item?->name ?? 'Deleted item' }}</p>
                        <p class="tnum truncate text-[13px] text-muted">
                            System says {{ $qty($line->book_quantity) }}
                            @if ((float) $line->unit_cost > 0)
                                · {{ $money($line->unit_cost) }} each
                            @else
                                · <span class="text-warning">no cost price</span>
                            @endif
                        </p>
                    </div>

                    @if ($stocktake->isDraft())
                        <div class="flex shrink-0 items-center gap-2">
                            <label class="sr-only" for="count-{{ $line->item_id }}">
                                Counted quantity of {{ $line->item?->name }}
                            </label>
                            <input id="count-{{ $line->item_id }}" type="number" step="any" inputmode="decimal"
                                   wire:model.blur="counts.{{ $line->item_id }}"
                                   placeholder="—"
                                   class="tnum h-12 w-[92px] rounded-xl border border-border bg-surface px-3 text-right text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            <button type="button" wire:click="acceptBook('{{ $line->item_id }}')"
                                    aria-label="Accept the system quantity for {{ $line->item?->name }}"
                                    class="tap focusable flex size-12 items-center justify-center rounded-xl border border-border bg-surface text-muted hover:bg-surface-2 hover:text-ink-2">
                                <x-icon name="check-circle" class="size-[18px]" stroke-width="2.2" />
                            </button>
                        </div>
                    @else
                        <div class="shrink-0 text-right">
                            <p class="tnum text-[15px] font-bold text-ink">
                                {{ $line->isCounted() ? $qty($line->counted_quantity) : '—' }}
                            </p>
                            @if ($line->isCounted() && abs($line->variance()) > 0.0005)
                                <p class="tnum text-[12px] font-medium {{ $line->variance() < 0 ? 'text-negative' : 'text-positive' }}">
                                    {{ $line->variance() > 0 ? '+' : '' }}{{ $qty($line->variance()) }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-4 py-10 text-center">
                <p class="text-[15px] font-semibold text-ink">
                    {{ $total === 0 ? 'Nothing to count' : 'Nothing matches' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    {{ $total === 0
                        ? 'This sheet was opened when no product had stock tracking switched on.'
                        : 'Every item on this sheet has been counted, or none match what you typed.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($stocktake->isDraft() && $total > 0)
        <p class="mt-4 text-[12.5px] leading-relaxed text-faint">
            A blank box means not counted yet, and stays that way — it is never read as zero. Lines you never reach keep
            the quantity the system already had.
        </p>
    @endif
</div>
