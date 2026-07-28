@php
    use App\Support\Accent;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Documents</h1>
            <p class="mt-1 text-[14.5px] text-muted">Contracts, letters and certificates on your letterhead.</p>
        </div>
    </div>

    {{-- The gallery leads: someone arriving here almost always wants to make
         something, and a business with three documents to its name should not
         be greeted by an empty table. --}}
    @can('papers.create')
        <div class="mt-5 grid grid-cols-2 gap-3 min-[560px]:grid-cols-3 lg:grid-cols-4">
            @foreach ($templates as $key => $template)
                <a href="{{ route('papers.create', $key) }}" wire:key="tpl-{{ $key }}"
                   class="card focusable flex flex-col gap-2.5 p-4 transition-colors hover:border-brand/40">
                    <span class="flex size-[38px] items-center justify-center rounded-xl {{ Accent::tint($template['accent']) }}">
                        <x-icon :name="$template['icon']" class="size-[20px] {{ Accent::text($template['accent']) }}" stroke-width="1.9" />
                    </span>
                    <span class="text-[14.5px] font-semibold leading-tight text-ink">{{ $template['name'] }}</span>
                    <span class="text-[12.5px] leading-snug text-muted">{{ $template['summary'] }}</span>
                </a>
            @endforeach
        </div>
    @endcan

    {{-- Search + filters --}}
    <div class="relative mt-6">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.250ms="search"
               placeholder="Search your documents…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="no-scrollbar mt-3 flex gap-2 overflow-x-auto">
        @foreach (['all' => 'All', 'draft' => 'Drafts', 'issued' => 'Issued'] as $key => $label)
            <button type="button" wire:click="setState('{{ $key }}')" wire:key="f-{{ $key }}"
                    class="focusable flex h-9 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-[13.5px] font-semibold transition-colors
                           {{ $state === $key ? 'bg-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
                <span class="tnum {{ $state === $key ? 'text-white/75' : 'text-faint' }}">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    {{-- List --}}
    <div class="mt-4 space-y-2.5">
        @forelse ($papers as $paper)
            @php $state = $paper->state(); @endphp
            <a href="{{ route('papers.show', $paper) }}" wire:key="p-{{ $paper->id }}"
               class="card focusable flex items-center gap-3.5 p-4 transition-colors hover:border-brand/40">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl {{ Accent::tint($paper->accent()) }}">
                    <x-icon :name="$paper->template()['icon'] ?? 'document'"
                            class="size-[21px] {{ Accent::text($paper->accent()) }}" stroke-width="1.9" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink">{{ $paper->title }}</span>
                    <span class="block truncate text-[13px] text-muted">
                        {{ $paper->templateName() }}@if ($paper->recipient) · {{ $paper->recipient }}@endif
                    </span>
                </span>

                <span class="shrink-0 text-right">
                    <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />
                    <span class="mt-1 block text-[12px] text-faint">{{ $paper->created_at->format('j M Y') }}</span>
                </span>
            </a>
        @empty
            <div class="card px-5 py-12 text-center">
                <p class="text-[15px] font-semibold text-ink">
                    {{ $search !== '' ? 'Nothing matches that search.' : 'No documents yet.' }}
                </p>
                <p class="mt-1.5 text-[13.5px] text-muted">
                    {{ $search !== '' ? 'Try a different name or reference.' : 'Pick a template above to make your first one.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($papers->hasPages())
        <div class="mt-5">{{ $papers->links() }}</div>
    @endif
</div>
