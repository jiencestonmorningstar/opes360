<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Guides</h1>
            <p class="mt-1 text-[14.5px] text-muted">How every part of OPES360 works, in plain terms.</p>
        </div>

        <div class="w-full max-w-xs">
            <label for="guide-search" class="sr-only">Search the guides</label>
            <input id="guide-search" wire:model.live.debounce.300ms="search" type="search"
                   placeholder="Search the guides…"
                   class="focusable h-11 w-full rounded-full border border-border bg-surface px-4 text-[14.5px] text-ink">
        </div>
    </div>

    <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,260px)_minmax(0,1fr)] lg:items-start">

        {{-- Contents --}}
        <nav aria-label="Guides" class="lg:sticky lg:top-6">
            <x-ui.panel title="Contents">
                @forelse ($grouped as $group => $guides)
                    <p class="{{ $loop->first ? '' : 'mt-4' }} text-[12px] font-semibold uppercase tracking-[0.08em] text-faint">
                        {{ $group }}
                    </p>

                    <ul class="mt-1.5 space-y-1">
                        @foreach ($guides as $guide)
                            <li wire:key="guide-{{ $guide['slug'] }}">
                                <button type="button" wire:click="open('{{ $guide['slug'] }}')"
                                        aria-current="{{ $guide['slug'] === $current['slug'] ? 'page' : 'false' }}"
                                        class="focusable w-full rounded-xl px-3 py-2 text-left text-[13.5px] font-semibold transition-colors
                                               {{ $guide['slug'] === $current['slug']
                                                    ? 'bg-fill-brand text-white'
                                                    : 'text-ink-2 hover:bg-surface-2' }}">
                                    {{ $guide['title'] }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @empty
                    <p class="py-4 text-[13.5px] text-muted">
                        Nothing matches “{{ $search }}”.
                    </p>
                @endforelse
            </x-ui.panel>
        </nav>

        {{-- The guide --}}
        <div>
            <x-ui.panel>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $current['title'] }}</h2>
                    <span class="rounded-full bg-surface-2 px-3 py-1 text-[12px] font-semibold text-muted">
                        @if ($current['audience'] === 'everyone') For everyone
                        @elseif ($current['audience'] === 'admin') For administrators
                        @else For developers
                        @endif
                    </span>
                </div>

                <p class="mt-1 text-[14px] text-muted">{{ $current['summary'] }}</p>

                <div class="guide-body mt-5 text-[14.5px] leading-relaxed text-ink-2">
                    {!! $html !!}
                </div>
            </x-ui.panel>
        </div>
    </div>
</div>

{{-- Typography for rendered markdown.

     Inline rather than pushed to a stack: the app layout has no @stack, so a
     @push here would silently render nothing. Scoped to .guide-body so it
     cannot leak, and built from the same tokens as the rest of the product so
     it follows the business's branding and both themes. --}}
@once
    <style>
            .guide-body h1 { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; color: var(--ink); margin-top: 1.75rem; }
            .guide-body h1:first-child { margin-top: 0; }
            .guide-body h2 { font-size: 1.1rem; font-weight: 700; color: var(--ink); margin-top: 1.75rem; }
            .guide-body h3 { font-size: 1rem; font-weight: 600; color: var(--ink); margin-top: 1.25rem; }
            .guide-body p { margin-top: 0.75rem; }
            .guide-body ul, .guide-body ol { margin-top: 0.75rem; padding-left: 1.25rem; }
            .guide-body ul { list-style: disc; }
            .guide-body ol { list-style: decimal; }
            .guide-body li { margin-top: 0.35rem; }
            .guide-body strong { color: var(--ink); font-weight: 650; }
            .guide-body a { color: var(--ink-brand); text-decoration: underline; }
            .guide-body blockquote {
                margin-top: 1rem; padding: 0.75rem 1rem;
                border-left: 3px solid var(--border);
                background: var(--surface-2); border-radius: 0 0.75rem 0.75rem 0;
            }
            .guide-body blockquote p:first-child { margin-top: 0; }
            .guide-body code {
                background: var(--surface-2); border-radius: 0.375rem;
                padding: 0.1rem 0.35rem; font-size: 0.9em;
            }
            /* Cells wrap rather than the page scrolling sideways on a phone. */
            .guide-body table {
                width: 100%; margin-top: 1rem;
                border-collapse: collapse; font-size: 0.95em;
                table-layout: auto; overflow-wrap: anywhere;
            }
            .guide-body th, .guide-body td {
                text-align: left; padding: 0.55rem 0.75rem;
                border-bottom: 1px solid var(--border); vertical-align: top;
            }
            .guide-body th { color: var(--ink); font-weight: 650; }
        </style>
@endonce
