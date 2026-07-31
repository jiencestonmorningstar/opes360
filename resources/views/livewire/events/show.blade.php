@php
    use App\Support\Money;
    $currency = auth()->user()?->currentCompany?->currency ?? 'USD';
    $state = $event->state();
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('events') }}" class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        All events
    </a>

    <div class="mt-4 flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $event->title }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $event->starts_at->format('D, F j, Y · g:ia') }}@if ($event->venue) · {{ $event->venue }}@endif
            </p>
        </div>
        <x-ui.status-badge class="shrink-0" :label="$state['label']" :tone="$state['tone']" />
    </div>

    @error('event')
        <div class="mt-4 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">{{ $message }}</div>
    @enderror

    {{-- Actions --}}
    @can('update', $event)
        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            @if (! $event->isPublished())
                <button type="button" wire:click="publish"
                        class="tap focusable flex h-10 items-center gap-1.5 rounded-xl bg-brand px-4 text-[13.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    <x-icon name="check-circle" class="size-[17px]" stroke-width="2" />
                    Publish & start selling
                </button>
            @else
                <button type="button" wire:click="unpublish"
                        class="focusable flex h-10 items-center rounded-xl border border-border bg-surface px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                    Pause sales
                </button>
            @endif

            <a href="{{ route('events.edit', $event) }}"
               class="focusable flex h-10 items-center rounded-xl border border-border bg-surface px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                Edit
            </a>

            @if ($event->status !== 'cancelled')
                <button type="button" wire:click="cancel" wire:confirm="Cancel this event? The public page will show it as cancelled."
                        class="focusable flex h-10 items-center rounded-xl border border-border bg-surface px-4 text-[13.5px] font-semibold text-warning hover:bg-tint-orange">
                    Cancel event
                </button>
            @endif
        </div>
    @endcan

    {{-- Share link --}}
    @if ($event->isPublished())
        <div class="card mt-4 p-5" x-data="{ copied: false }">
            <div class="flex items-center gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-[11.5px] font-medium uppercase tracking-wide text-faint">Ticket sales page</p>
                    <div class="mt-1.5 flex items-center gap-2">
                        <a href="{{ $event->publicUrl() }}" target="_blank" rel="noopener"
                           class="tnum focusable min-w-0 flex-1 truncate text-[13.5px] font-semibold text-brand hover:underline">{{ $event->publicUrl() }}</a>
                        <button type="button"
                                @click="navigator.clipboard?.writeText('{{ $event->publicUrl() }}').then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                class="focusable shrink-0 rounded-lg border border-border bg-surface px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            <span x-show="!copied">Copy</span>
                            <span x-show="copied" x-cloak class="text-positive">Copied</span>
                        </button>
                    </div>
                    <p class="mt-2 text-[12.5px] leading-snug text-muted">
                        Put the QR on the poster — it opens this page.
                    </p>
                </div>
                @if ($shareQr)
                    <span class="block size-[92px] shrink-0 overflow-hidden rounded-lg bg-white p-1.5 [&>svg]:h-full [&>svg]:w-full">
                        {!! $shareQr !!}
                    </span>
                @endif
            </div>
        </div>
    @endif

    {{-- The numbers that matter on the day --}}
    <div class="mt-4 grid grid-cols-2 gap-3 min-[640px]:grid-cols-4">
        @foreach ([
            ['Tickets sold', $stats['sold'], false],
            ['Checked in', $stats['checked_in'], false],
            ['Paid revenue', Money::format($stats['revenue'], $currency), true],
            ['Awaiting payment', $stats['unpaid'], false],
        ] as [$label, $value, $isMoney])
            <div class="card p-4">
                <p class="text-[12px] font-medium text-muted">{{ $label }}</p>
                <p class="tnum mt-1 text-[22px] font-bold tracking-[-0.02em] text-ink">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- Ticket types --}}
    <div class="card mt-4 p-5">
        <p class="text-[15px] font-bold text-ink">Ticket types</p>
        <div class="mt-3 space-y-2.5">
            @foreach ($types as $type)
                <div wire:key="tt-{{ $type->id }}" class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-[14.5px] font-semibold text-ink">{{ $type->name }}</p>
                        <p class="text-[12.5px] text-muted">
                            {{ (float) $type->price > 0 ? Money::format($type->price, $currency) : 'Free' }}
                            · {{ $type->quantity === null ? 'Unlimited' : $type->remaining().' of '.$type->quantity.' left' }}
                        </p>
                    </div>
                    <span class="tnum shrink-0 text-[14px] font-semibold text-ink-2">{{ $type->sold }} sold</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Attendees --}}
    <div class="mt-6">
        <p class="text-[16px] font-bold text-ink">Attendees</p>

        <div class="no-scrollbar mt-3 flex gap-2 overflow-x-auto">
            @foreach (['all' => 'All', 'issued' => 'Issued', 'checked_in' => 'Checked in', 'void' => 'Void'] as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" wire:key="fl-{{ $key }}"
                        class="focusable flex h-9 shrink-0 items-center rounded-full px-3.5 text-[13.5px] font-semibold transition-colors
                               {{ $filter === $key ? 'bg-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="mt-3 space-y-2.5">
            @forelse ($tickets as $ticket)
                @php $tState = $ticket->state(); @endphp
                <div wire:key="tk-{{ $ticket->id }}" class="card p-4">
                    <div class="flex items-center gap-3.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[14.5px] font-semibold text-ink">{{ $ticket->buyer_name }}</p>
                            <p class="truncate text-[12.5px] text-muted">
                                <span class="tnum">{{ $ticket->serial }}</span>
                                · {{ $ticket->ticketType?->name }}
                                @if ((float) $ticket->price > 0)
                                    · {{ Money::format($ticket->price, $currency) }}
                                    · {{ $ticket->paid_at ? 'Paid' : 'Unpaid' }}
                                @endif
                            </p>
                        </div>
                        <x-ui.status-badge class="shrink-0" :label="$tState['label']" :tone="$tState['tone']" />
                    </div>

                    @if (! $ticket->isVoid())
                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                            @can('checkIn', $ticket)
                                @if (! $ticket->isCheckedIn())
                                    <button type="button" wire:click="checkIn('{{ $ticket->id }}')"
                                            class="focusable rounded-lg bg-brand px-3 py-1.5 text-[12.5px] font-semibold text-white transition-opacity hover:opacity-90">
                                        Check in
                                    </button>
                                @else
                                    <span class="text-[12.5px] text-muted">In at {{ $ticket->checked_in_at?->format('g:ia') }}</span>
                                @endif
                            @endcan

                            @can('update', $event)
                                @if ((float) $ticket->price > 0)
                                    <button type="button" wire:click="togglePaid('{{ $ticket->id }}')"
                                            class="focusable rounded-lg border border-border px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                        {{ $ticket->paid_at ? 'Mark unpaid' : 'Mark paid' }}
                                    </button>
                                @endif
                            @endcan

                            @can('void', $ticket)
                                <button type="button" wire:click="voidTicket('{{ $ticket->id }}')"
                                        wire:confirm="Void this ticket? Its QR will stop admitting anyone."
                                        class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-faint hover:bg-tint-orange hover:text-warning">
                                    Void
                                </button>
                            @endcan
                        </div>
                    @endif
                </div>
            @empty
                <div class="card px-5 py-12 text-center">
                    <p class="text-[15px] font-semibold text-ink">No tickets {{ $filter !== 'all' ? 'in this state' : 'yet' }}.</p>
                    <p class="mt-1.5 text-[13.5px] text-muted">
                        {{ $event->isPublished() ? 'Share the sales page — tickets appear here as they sell.' : 'Publish the event to start selling.' }}
                    </p>
                </div>
            @endforelse
        </div>

        @if ($tickets->hasPages())
            <div class="mt-5">{{ $tickets->links() }}</div>
        @endif
    </div>
</div>
