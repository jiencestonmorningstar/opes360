<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ $event ? route('events.show', $event) : route('events') }}"
       class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        {{ $event ? $event->title : 'All events' }}
    </a>

    <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">
        {{ $event ? 'Edit event' : 'New event' }}
    </h1>

    <form wire:submit="save" class="mt-5 space-y-4">

        <div class="card space-y-4 p-5">
            <div>
                <label for="title" class="block text-[13.5px] font-semibold text-ink-2">Event name</label>
                <input id="title" type="text" wire:model="title" placeholder="Product Launch Night"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                @error('title')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="description" class="block text-[13.5px] font-semibold text-ink-2">Description</label>
                <textarea id="description" wire:model="description" rows="4"
                          placeholder="What attendees should know…"
                          class="mt-1.5 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                @error('description')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="venue" class="block text-[13.5px] font-semibold text-ink-2">Venue</label>
                <input id="venue" type="text" wire:model="venue" placeholder="Landmark Centre, Lagos"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                @error('venue')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror
            </div>

            <div class="grid gap-4 min-[480px]:grid-cols-2">
                <div>
                    <label for="startsAt" class="block text-[13.5px] font-semibold text-ink-2">Starts</label>
                    <input id="startsAt" type="datetime-local" wire:model="startsAt"
                           class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('startsAt')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="endsAt" class="block text-[13.5px] font-semibold text-ink-2">Ends <span class="font-normal text-faint">(optional)</span></label>
                    <input id="endsAt" type="datetime-local" wire:model="endsAt"
                           class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('endsAt')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Ticket types --}}
        <div class="card p-5">
            <div class="flex items-center justify-between gap-3">
                <p class="text-[15px] font-bold text-ink">Tickets</p>
                <button type="button" wire:click="addType"
                        class="focusable text-[13.5px] font-semibold text-brand hover:underline">Add ticket type</button>
            </div>
            @error('types')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror

            <div class="mt-3 space-y-3">
                @foreach ($types as $i => $type)
                    <div wire:key="type-{{ $i }}" class="rounded-xl border border-border p-4">
                        <div class="grid gap-3 min-[560px]:grid-cols-[1fr_130px_130px]">
                            <div>
                                <label for="ticket-name-{{ $i }}" class="block text-[12.5px] font-semibold text-ink-2">Name</label>
                                <input id="ticket-name-{{ $i }}" type="text" wire:model="types.{{ $i }}.name" placeholder="General admission"
                                       class="mt-1 h-11 w-full rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                            </div>
                            <div>
                                <label for="ticket-price-{{ $i }}" class="block text-[12.5px] font-semibold text-ink-2">Price</label>
                                <input id="ticket-price-{{ $i }}" type="number" step="0.01" min="0" wire:model="types.{{ $i }}.price"
                                       class="tnum mt-1 h-11 w-full rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                            </div>
                            <div>
                                <label for="ticket-qty-{{ $i }}" class="block text-[12.5px] font-semibold text-ink-2">Quantity <span class="font-normal text-faint">(blank = unlimited)</span></label>
                                <input id="ticket-qty-{{ $i }}" type="number" min="0" wire:model="types.{{ $i }}.quantity"
                                       class="tnum mt-1 h-11 w-full rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                            </div>
                        </div>

                        @error('types.'.$i.'.name')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror
                        @error('types.'.$i.'.price')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror
                        @error('types.'.$i.'.quantity')<p class="mt-1.5 text-[13px] font-medium text-warning">{{ $message }}</p>@enderror

                        <div class="mt-2.5 flex items-center justify-between">
                            <span class="text-[12.5px] text-faint">
                                @if (($type['sold'] ?? 0) > 0){{ $type['sold'] }} sold — cannot be removed @endif
                            </span>
                            @if (($type['sold'] ?? 0) === 0 && count($types) > 1)
                                <button type="button" wire:click="removeType({{ $i }})"
                                        class="focusable text-[12.5px] font-semibold text-faint hover:text-warning">Remove</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <button type="submit"
                class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
            {{ $event ? 'Save changes' : 'Create event' }}
        </button>
    </form>
</div>
