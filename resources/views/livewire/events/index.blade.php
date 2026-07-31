<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Events</h1>
            <p class="mt-1 text-[14.5px] text-muted">Publish an event, share the link, sell tickets with built-in QR check-in.</p>
        </div>

        @can('events.create')
            <a href="{{ route('events.create') }}"
               class="tap focusable flex h-11 shrink-0 items-center gap-2 rounded-xl bg-brand px-4 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.2" />
                New event
            </a>
        @endcan
    </div>

    <div class="relative mt-6">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.250ms="search"
               placeholder="Search events…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="mt-4 space-y-2.5">
        @forelse ($events as $event)
            @php $state = $event->state(); @endphp
            <a href="{{ route('events.show', $event) }}" wire:key="ev-{{ $event->id }}"
               class="card focusable flex items-center gap-3.5 p-4 transition-colors hover:border-brand/40">
                <span class="flex size-[46px] shrink-0 flex-col items-center justify-center rounded-xl bg-tint-orange">
                    <span class="text-[10px] font-bold uppercase leading-none text-warning">{{ $event->starts_at->format('M') }}</span>
                    <span class="tnum text-[17px] font-bold leading-tight text-ink">{{ $event->starts_at->format('j') }}</span>
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink">{{ $event->title }}</span>
                    <span class="block truncate text-[13px] text-muted">
                        {{ $event->starts_at->format('D, g:ia') }}@if ($event->venue) · {{ $event->venue }}@endif
                        · {{ $event->sold_count }} {{ Str::plural('ticket', $event->sold_count) }}
                    </span>
                </span>

                <x-ui.status-badge class="shrink-0" :label="$state['label']" :tone="$state['tone']" />
            </a>
        @empty
            <div class="card px-5 py-12 text-center">
                <p class="text-[15px] font-semibold text-ink">
                    {{ $search !== '' ? 'Nothing matches that search.' : 'No events yet.' }}
                </p>
                <p class="mt-1.5 text-[13.5px] text-muted">
                    {{ $search !== '' ? 'Try a different name or venue.' : 'Create one — every ticket carries its own QR code for the door.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($events->hasPages())
        <div class="mt-5">{{ $events->links() }}</div>
    @endif
</div>
