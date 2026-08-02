@php
    use App\Livewire\Customers\Index;
    use App\Support\Accent;
    use App\Support\Money;

    $company = app(\App\Support\CurrentCompany::class)->get();
    $currency = $company?->currency ?? 'USD';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Customers</h1>
            <p class="mt-1 text-[14.5px] text-muted">Everyone you do business with.</p>
        </div>

        @can('customers.create')
            <a href="{{ route('customers.create', ['type' => $type]) }}"
               class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="user-plus" class="size-[18px]" stroke-width="2" />
                <span class="sr-only min-[420px]:not-sr-only">Add Customer</span>
                <span class="min-[420px]:hidden">Add</span>
            </a>
        @endcan
    </div>

    <div class="relative mt-5">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Search by name or email…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Filter customers">
        @foreach (Index::TYPES as $key => $label)
            @php $isActive = $type === $key; @endphp
            <button type="button" wire:click="setType('{{ $key }}')"
                    aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center gap-2 rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $isActive ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
                @if (($typeCounts[$key] ?? 0) > 0)
                    <span class="tnum rounded-full px-1.5 text-[11.5px] {{ $isActive ? 'bg-black/20' : 'bg-surface-2 text-muted' }}">
                        {{ $typeCounts[$key] }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($contacts as $index => $contact)
            @php $accent = $contact->accent(); @endphp
            <a href="{{ route('customers.show', $contact) }}" wire:key="{{ $contact->id }}"
               class="focusable flex items-center gap-3.5 rounded-xl px-3 py-3 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[13px] font-bold {{ Accent::text($accent) }}">
                    {{ $contact->initials() }}
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink">{{ $contact->displayName() }}</span>
                    <span class="block truncate text-[13px] text-muted">
                        {{ $contact->email ?? data_get($contact->phones, 0, '—') }}
                    </span>
                </span>

                @if ((float) $contact->balance > 0)
                    <span class="shrink-0 text-right">
                        <span class="tnum block text-[15px] font-bold text-warning">
                            {{ Money::format($contact->balance, $currency) }}
                        </span>
                        <span class="block text-[11.5px] font-medium text-muted">owing</span>
                    </span>
                @endif

                <x-icon name="chevron-right" class="size-[18px] shrink-0 text-faint" stroke-width="2" />
            </a>
        @empty
            <div class="flex flex-col items-center px-6 py-14 text-center">
                <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-purple">
                    <x-icon name="users" class="size-7 text-accent-purple" />
                </span>
                <p class="mt-4 text-[16px] font-semibold text-ink">
                    No {{ strtolower(Index::TYPES[$type]) }}{{ $search !== '' ? ' match your search' : ' yet' }}
                </p>
                <p class="mt-1 max-w-xs text-[13.5px] text-muted">
                    @if ($search !== '')
                        Try a different name or email.
                    @else
                        Add your first one and they will appear here.
                    @endif
                </p>
            </div>
        @endforelse
    </div>

    @if ($contacts->hasPages())
        <div class="mt-4">{{ $contacts->links('pagination.opes') }}</div>
    @endif
</div>
