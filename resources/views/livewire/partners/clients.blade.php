@php
    use App\Support\Accent;
    use App\Support\Money;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Clients</h1>
            <p class="mt-1 text-[14.5px] text-muted">The businesses you print for.</p>
        </div>

        @can('partners.manage')
            <button type="button" wire:click="startAdding"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="user-plus" class="size-[18px]" stroke-width="2" />
                <span class="hidden min-[420px]:inline">Add Client</span>
                <span class="min-[420px]:hidden">Add</span>
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    {{-- The referral code, kept in reach rather than buried in settings: it is
         the thing a partner reads down the phone all day. --}}
    @if ($partnerCode)
        <div class="card mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tint-purple">
                <x-icon name="spark" class="size-[17px] text-accent-purple" stroke-width="2" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Your partner code</p>
                <p class="tnum text-[17px] font-bold tracking-[0.02em] text-ink">{{ $partnerCode }}</p>
            </div>
            <p class="w-full text-[12.5px] leading-relaxed text-muted sm:w-auto sm:max-w-[260px]">
                A business that registers with this code is attributed to you, and you earn on
                every subscription they pay.
            </p>
        </div>
    @endif

    @if ($adding)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">New client</h2>
            <p class="mt-1 text-[13.5px] text-muted">Only the business name is required — the rest can wait.</p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="client-name">Business name</label>
                    <input id="client-name" type="text" wire:model="name" class="{{ $inputClass }}" placeholder="Boulangerie Nkolbisson">
                    @error('name') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="client-contact">Contact person</label>
                    <input id="client-contact" type="text" wire:model="contactName" class="{{ $inputClass }}" placeholder="Marie Nkolo">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="client-phone">Phone</label>
                    <input id="client-phone" type="tel" wire:model="phone" class="{{ $inputClass }}" placeholder="+237 6…">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="client-email">Email</label>
                    <input id="client-email" type="email" wire:model="email" class="{{ $inputClass }}" placeholder="contact@example.cm">
                    @error('email') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="client-industry">Trade</label>
                    <select id="client-industry" wire:model="industry" class="{{ $inputClass }}">
                        <option value="">Not sure yet</option>
                        @foreach (config('opes.industries') as $industry)
                            <option value="{{ $industry }}">{{ $industry }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="client-city">Town</label>
                    <input id="client-city" type="text" wire:model="city" class="{{ $inputClass }}" placeholder="Douala">
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    Save client
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
        <input type="search" wire:model.live.debounce.300ms="search" aria-label="Search clients"
               placeholder="Search by name, contact or phone…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="tablist">
        @foreach (['all' => 'All', 'prospect' => 'Not signed up', 'converted' => 'Signed up'] as $key => $label)
            @php $isActive = $filter === $key; @endphp
            <button type="button" wire:click="$set('filter', '{{ $key }}')" role="tab"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $isActive ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($clients as $index => $client)
            @php $accent = Accent::forKey($client->id); @endphp
            <a href="{{ route('partners.clients.show', $client) }}" wire:key="{{ $client->id }}"
               class="focusable flex items-center gap-3.5 rounded-xl px-3 py-3 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[13px] font-bold {{ Accent::text($accent) }}">
                    {{ collect(explode(' ', $client->name))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-semibold text-ink">{{ $client->name }}</p>
                    <p class="truncate text-[13px] text-muted">
                        {{ $client->contact_name ?: $client->phone ?: ($client->industry ?: 'No details yet') }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    @if ($client->hasConverted())
                        <span class="rounded-full bg-tint-green px-2 py-0.5 text-[11px] font-semibold text-positive">Signed up</span>
                    @endif
                    @if ($client->issuances_count > 0)
                        <span class="tnum text-[12px] text-faint">{{ $client->issuances_count }} {{ Str::plural('card', $client->issuances_count) }}</span>
                    @endif
                </div>
                <x-icon name="chevron-right" class="size-[18px] shrink-0 text-faint" stroke-width="2" />
            </a>
        @empty
            <div class="px-4 py-12 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="printer" class="size-[24px] text-accent-slate" stroke-width="1.7" />
                </span>
                <p class="mt-4 text-[15.5px] font-semibold text-ink">
                    {{ $search !== '' || $filter !== 'all' ? 'Nothing matches that.' : 'No clients yet' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-xs text-[13.5px] leading-relaxed text-muted">
                    {{ $search !== '' || $filter !== 'all'
                        ? 'Try a different search, or clear the filter.'
                        : 'Add the businesses you already print for. Each card you make for them is '.Money::format($cardFee, 'XAF', false).'.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($clients->hasPages())
        <div class="mt-5">{{ $clients->links() }}</div>
    @endif
</div>
