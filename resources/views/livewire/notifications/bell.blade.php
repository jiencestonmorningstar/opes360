<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" wire:poll.30s>
    <button type="button" @click="open = ! open"
            class="tap focusable relative flex items-center justify-center rounded-lg text-ink"
            :aria-expanded="open.toString()" aria-haspopup="menu"
            aria-label="Notifications{{ $unreadCount > 0 ? ', '.$unreadCount.' unread' : '' }}">
        <x-icon name="bell" class="size-[25px]" />
        @if ($unreadCount > 0)
            <span class="absolute right-1.5 top-1.5 size-[9px] rounded-full bg-fill-brand ring-2 ring-canvas"></span>
        @endif
    </button>

    <div x-cloak x-show="open" @click.outside="open = false" x-transition.origin.top.right
         class="card absolute right-0 top-[calc(100%+0.5rem)] z-30 w-80 max-w-[calc(100vw-2.5rem)] overflow-hidden p-0 shadow-[var(--shadow-raised)]"
         role="menu">
        <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
            <p class="text-[14.5px] font-bold text-ink">Notifications</p>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="focusable text-[12.5px] font-semibold text-brand hover:underline">
                    Mark all read
                </button>
            @endif
        </div>

        <div class="max-h-[70vh] overflow-y-auto">
            @forelse ($notifications as $notification)
                <a href="{{ $notification->data['url'] ?? '#' }}" wire:key="n-{{ $notification->id }}"
                   wire:click="markRead('{{ $notification->id }}')"
                   class="focusable flex items-start gap-3 border-b border-border px-4 py-3 last:border-0 hover:bg-surface-2 {{ $notification->read_at ? '' : 'bg-tint-blue/40' }}">
                    <span class="mt-1 size-[7px] shrink-0 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-fill-brand' }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-semibold text-ink">{{ $notification->data['title'] ?? 'Notification' }}</span>
                        <span class="block truncate text-[12.5px] text-muted">{{ $notification->data['body'] ?? '' }}</span>
                        <span class="mt-0.5 block text-[11px] text-faint">{{ $notification->created_at->diffForHumans() }}</span>
                    </span>
                </a>
            @empty
                <p class="px-4 py-8 text-center text-[13.5px] text-muted">Nothing yet.</p>
            @endforelse
        </div>
    </div>
</div>
