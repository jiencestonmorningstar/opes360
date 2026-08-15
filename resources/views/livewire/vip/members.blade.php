@php
    use App\Models\VipMembership;
    use App\Support\Money;

    $currency = app(\App\Support\CurrentCompany::class)->get()?->currency ?? 'XAF';

    $filters = ['active' => 'Active', 'expired' => 'Lapsed', 'all' => 'All'];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">VIP members</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $liveCount }} {{ Str::plural('member', $liveCount) }} with a live membership
            </p>
        </div>

        @can('vip.manage')
            <a href="{{ route('vip.tiers') }}" wire:navigate
               class="tap focusable flex shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink transition-colors hover:bg-surface-2">
                <x-icon name="cog" class="size-[17px]" stroke-width="2" />
                <span class="sr-only min-[420px]:not-sr-only">Tiers</span>
            </a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
            {{ session('status') }}
        </div>
    @endif

    @can('vip.sell')
        <x-ui.panel title="Sign somebody up" class="mt-5">
            @if ($tiers->isEmpty())
                <p class="text-[13.5px] leading-relaxed text-muted">
                    There are no tiers on sale yet.
                    @can('vip.manage')
                        <a href="{{ route('vip.tiers') }}" wire:navigate class="focusable font-semibold text-brand hover:underline">Add one first</a>.
                    @endcan
                </p>
            @else
                <form wire:submit="sell" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="sell-to" class="block text-[13.5px] font-semibold text-ink-2">Customer</label>
                            <select id="sell-to" wire:model="sellTo"
                                    class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                <option value="">Choose a customer…</option>
                                @foreach ($contacts as $contact)
                                    <option value="{{ $contact->id }}">{{ $contact->company_name ?: $contact->name }}</option>
                                @endforeach
                            </select>
                            @error('sellTo') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="sell-tier" class="block text-[13.5px] font-semibold text-ink-2">Tier</label>
                            <select id="sell-tier" wire:model="sellTier"
                                    class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                <option value="">Choose a tier…</option>
                                @foreach ($tiers as $tier)
                                    <option value="{{ $tier->id }}">
                                        {{ $tier->name }} — {{ Money::format($tier->price, $currency) }} / {{ $tier->period_months }}mo
                                    </option>
                                @endforeach
                            </select>
                            @error('sellTier') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <p class="text-[12.5px] leading-relaxed text-faint">
                        This raises an invoice for the fee on the customer's account. If they are already a
                        member, the new term starts when the current one ends.
                    </p>

                    <button type="submit" wire:loading.attr="disabled" wire:target="sell"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                        <span wire:loading.remove wire:target="sell">Sell membership</span>
                        <span wire:loading wire:target="sell">Selling…</span>
                    </button>
                </form>
            @endif
        </x-ui.panel>
    @endcan

    <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Filter memberships">
        @foreach ($filters as $key => $label)
            @php $isActive = $filter === $key; @endphp
            <button type="button" wire:click="setFilter('{{ $key }}')"
                    aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $isActive ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($memberships as $index => $membership)
            <div wire:key="m-{{ $membership->id }}"
                 class="flex items-center gap-3.5 px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ $membership->isActive() ? 'bg-tint-green' : 'bg-tint-slate' }}">
                    <x-icon name="spark" class="size-[19px] {{ $membership->isActive() ? 'text-positive' : 'text-accent-slate' }}" stroke-width="1.9" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-semibold text-ink">
                        @if ($membership->contact)
                            <a href="{{ route('customers.show', $membership->contact) }}" wire:navigate
                               class="focusable hover:text-brand hover:underline">{{ $membership->contact->displayName() }}</a>
                        @else
                            Unknown customer
                        @endif
                    </p>
                    <p class="truncate text-[13px] text-muted">
                        {{ $membership->tier_name }}
                        · {{ rtrim(rtrim(number_format((float) $membership->discount_percent, 2), '0'), '.') }}% off
                        · {{ $membership->isActive() ? 'until' : 'ended' }} {{ $membership->ends_on->format('j M Y') }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    @if ($membership->status === VipMembership::CANCELLED)
                        <span class="rounded-full bg-tint-orange px-2.5 py-1 text-[11.5px] font-semibold text-warning">Cancelled</span>
                    @elseif ($membership->isActive())
                        <span class="rounded-full bg-tint-green px-2.5 py-1 text-[11.5px] font-semibold text-positive">Active</span>
                    @else
                        <span class="rounded-full bg-surface-2 px-2.5 py-1 text-[11.5px] font-semibold text-muted">Lapsed</span>
                    @endif

                    {{-- Opened in a new tab: the card is a print sheet, and
                         losing the list to it is a back-button every time. --}}
                    <a href="{{ route('vip.card.print', $membership) }}" target="_blank" rel="noopener"
                       class="focusable text-[13px] font-semibold text-brand hover:underline">Card</a>

                    @can('vip.manage')
                        @if ($membership->status !== VipMembership::CANCELLED)
                            <button type="button" wire:click="cancel('{{ $membership->id }}')"
                                    wire:confirm="Cancel this membership? The discount stops immediately. The record is kept."
                                    class="focusable text-[13px] font-semibold text-negative hover:underline">Cancel</button>
                        @endif
                    @endcan
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center px-6 py-14 text-center">
                <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-blue">
                    <x-icon name="spark" class="size-7 text-accent-blue" />
                </span>
                <p class="mt-4 text-[16px] font-semibold text-ink">
                    {{ $filter === 'active' ? 'Nobody is a member yet' : 'Nothing here' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    A member buys a tier for a term, and everything they buy while it runs is discounted
                    automatically.
                </p>
            </div>
        @endforelse
    </div>

    @if ($memberships->hasPages())
        <div class="mt-4">{{ $memberships->links() }}</div>
    @endif
</div>
