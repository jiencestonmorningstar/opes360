<div x-data="{
        open: false,
        active: 0,
        items() { return Array.from($refs.list?.querySelectorAll('[data-result]') ?? []) },
        move(step) {
            const items = this.items();
            if (! items.length) return;
            this.active = (this.active + step + items.length) % items.length;
            items.forEach((el, i) => el.classList.toggle('bg-tint-blue', i === this.active));
            items[this.active]?.scrollIntoView({ block: 'nearest' });
        },
        go() { this.items()[this.active]?.click() },
        show() { this.open = true; this.active = 0; $nextTick(() => $refs.input?.focus()) },
    }"
    @keydown.window.prevent.ctrl.k="show()"
    @keydown.window.prevent.cmd.k="show()"
    @keydown.escape.window="open = false">

    {{-- The trigger: a search button that sits with the other topbar icons. --}}
    <button type="button" @click="show()"
            class="tap focusable flex items-center gap-2 rounded-xl px-2 py-2 text-muted hover:text-ink lg:mr-1"
            aria-label="{{ __('Search') }}" title="{{ __('Search') }} (Ctrl+K)">
        <x-icon name="search" class="size-[22px]" />
        <span class="hidden text-[13px] xl:block">Ctrl+K</span>
    </button>

    {{-- The palette itself. --}}
    <div x-cloak x-show="open" class="fixed inset-0 z-40" role="dialog" aria-modal="true" aria-label="{{ __('Search') }}">
        <div class="absolute inset-0 bg-slate-900/40" @click="open = false" aria-hidden="true"></div>

        <div class="absolute inset-x-3 top-16 mx-auto max-w-xl sm:inset-x-6"
             @keydown.arrow-down.prevent="move(1)"
             @keydown.arrow-up.prevent="move(-1)"
             @keydown.enter.prevent="go()">
            <div class="card overflow-hidden shadow-[var(--shadow-raised)]">
                <div class="flex items-center gap-3 border-b border-border px-4">
                    <x-icon name="search" class="size-[18px] shrink-0 text-muted" />
                    <input x-ref="input" type="search" autocomplete="off"
                           wire:model.live.debounce.250ms="query"
                           @input="active = 0"
                           placeholder="{{ __('Search customers, invoices, papers, tickets…') }}"
                           class="w-full border-0 bg-transparent py-3.5 text-[15px] text-ink placeholder:text-muted focus:outline-none focus:ring-0">
                    <kbd class="hidden rounded-md border border-border px-1.5 py-0.5 text-[11px] text-muted sm:block">esc</kbd>
                </div>

                <div x-ref="list" class="max-h-[60vh] overflow-y-auto p-1.5">
                    @forelse ($groups as $label => $entries)
                        <p class="px-3 pb-1 pt-2.5 text-[11.5px] font-semibold uppercase tracking-wide text-muted">
                            {{ $label }}
                        </p>

                        @foreach ($entries as $entry)
                            <a href="{{ $entry['url'] }}" data-result
                               class="flex items-baseline justify-between gap-3 rounded-lg px-3 py-2 hover:bg-tint-blue">
                                <span class="truncate text-[14.5px] font-medium text-ink">{{ $entry['title'] }}</span>
                                @if ($entry['subtitle'])
                                    <span class="shrink-0 truncate text-[12.5px] text-muted">{{ $entry['subtitle'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    @empty
                        <p class="px-3 py-6 text-center text-[13.5px] text-muted">
                            {{ mb_strlen(trim($query)) < 2 ? __('Type at least two characters to search.') : __('Nothing matches that.') }}
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
