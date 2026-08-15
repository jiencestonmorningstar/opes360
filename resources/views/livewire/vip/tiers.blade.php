@php
    use App\Support\Money;

    $currency = app(\App\Support\CurrentCompany::class)->get()?->currency ?? 'XAF';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-3xl">

        <a href="{{ route('vip.members') }}" wire:navigate
           class="focusable -ml-1 inline-flex items-center gap-1.5 rounded-lg p-1 text-[14px] font-semibold text-muted hover:text-ink">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            VIP members
        </a>

        <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">VIP tiers</h1>
        <p class="mt-1 text-[14.5px] leading-relaxed text-muted">
            What a customer can buy, and what it saves them. A tier's discount applies to everything
            they buy afterwards, for as long as their membership runs.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
                {{ session('status') }}
            </div>
        @endif

        @can('vip.manage')
            <x-ui.panel :title="$editing ? 'Edit tier' : 'Add a tier'" class="mt-5">
                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label for="tier-name" class="block text-[13.5px] font-semibold text-ink-2">Name</label>
                        <input id="tier-name" type="text" wire:model="name" autocomplete="off" placeholder="Gold"
                               class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('name') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="tier-price" class="block text-[13.5px] font-semibold text-ink-2">Price</label>
                            <input id="tier-price" type="number" step="0.01" min="0" inputmode="decimal" wire:model="price"
                                   class="tnum control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            @error('price') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="tier-period" class="block text-[13.5px] font-semibold text-ink-2">Months</label>
                            <input id="tier-period" type="number" min="1" max="120" inputmode="numeric" wire:model="periodMonths"
                                   class="tnum control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            @error('periodMonths') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="tier-discount" class="block text-[13.5px] font-semibold text-ink-2">Discount %</label>
                            <input id="tier-discount" type="number" step="0.01" min="0" max="100" inputmode="decimal" wire:model="discountPercent"
                                   class="tnum control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            @error('discountPercent') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="tier-perks" class="block text-[13.5px] font-semibold text-ink-2">Perks</label>
                        {{-- Free text on purpose: these are honoured by staff at the
                             counter, not enforced by the system, and pretending
                             otherwise would promise something the app cannot keep. --}}
                        <textarea id="tier-perks" rows="2" wire:model="perks"
                                  placeholder="Free breakfast, late checkout"
                                  class="mt-1.5 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] leading-relaxed text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                        <p class="mt-1 text-[12.5px] text-faint">Written on the card and honoured by your staff — the app does not enforce these.</p>
                        @error('perks') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                                class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                            <span wire:loading.remove wire:target="save">{{ $editing ? 'Save changes' : 'Add tier' }}</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>

                        @if ($editing)
                            <button type="button" wire:click="cancelEdit"
                                    class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                                Cancel
                            </button>
                        @endif
                    </div>
                </form>
            </x-ui.panel>
        @endcan

        <x-ui.panel title="Your tiers" class="mt-5">
            @forelse ($tiers as $tier)
                <div wire:key="tier-{{ $tier->id }}"
                     class="flex items-start justify-between gap-4 border-border py-3.5 {{ ! $loop->first ? 'border-t' : '' }}">
                    <div class="min-w-0">
                        <p class="text-[15px] font-bold text-ink">
                            {{ $tier->name }}
                            @unless ($tier->is_active)
                                <span class="ml-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-semibold text-muted">Withdrawn</span>
                            @endunless
                        </p>
                        <p class="mt-0.5 text-[13.5px] text-muted">
                            <span class="tnum font-semibold text-ink-2">{{ Money::format($tier->price, $currency) }}</span>
                            for {{ $tier->period_months }} {{ Str::plural('month', $tier->period_months) }}
                            · saves <span class="tnum font-semibold text-positive">{{ rtrim(rtrim(number_format((float) $tier->discount_percent, 2), '0'), '.') }}%</span>
                        </p>
                        @if ($tier->perks)
                            <p class="mt-1 text-[12.5px] leading-relaxed text-faint">{{ $tier->perks }}</p>
                        @endif
                        <p class="mt-1 text-[12px] text-faint">
                            {{ $tier->memberships_count }} {{ Str::plural('member', $tier->memberships_count) }} sold
                        </p>
                    </div>

                    @can('vip.manage')
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <button type="button" wire:click="edit('{{ $tier->id }}')"
                                    class="focusable text-[13px] font-semibold text-brand hover:underline">Edit</button>

                            @if ($tier->is_active)
                                <button type="button" wire:click="withdraw('{{ $tier->id }}')"
                                        wire:confirm="Withdraw this tier? It can no longer be sold. Existing members keep what they bought."
                                        class="focusable text-[13px] font-semibold text-negative hover:underline">Withdraw</button>
                            @else
                                <button type="button" wire:click="restore('{{ $tier->id }}')"
                                        class="focusable text-[13px] font-semibold text-muted hover:text-ink hover:underline">Put back on sale</button>
                            @endif
                        </div>
                    @endcan
                </div>
            @empty
                <div class="py-8 text-center">
                    <p class="text-[15px] font-semibold text-ink">No tiers yet</p>
                    <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                        A tier is what a customer buys — a name, a price, how long it lasts, and what
                        it takes off their bills. Add one and you can start signing people up.
                    </p>
                </div>
            @endforelse
        </x-ui.panel>
    </div>
</div>
