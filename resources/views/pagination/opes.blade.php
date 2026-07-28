@if ($paginator->hasPages())
    {{-- Prev / position / next — page-number strips don't survive 390px screens. --}}
    <nav role="navigation" aria-label="Pagination"
         class="flex items-center justify-between gap-3">
        @if ($paginator->onFirstPage())
            <span class="flex h-11 items-center gap-1.5 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-faint">
                <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
                Previous
            </span>
        @else
            <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                    class="focusable flex h-11 items-center gap-1.5 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-surface-2">
                <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
                Previous
            </button>
        @endif

        <span class="tnum text-[13px] font-medium text-muted">
            Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
        </span>

        @if ($paginator->hasMorePages())
            <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                    class="focusable flex h-11 items-center gap-1.5 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-surface-2">
                Next
                <x-icon name="chevron-right" class="size-[16px]" stroke-width="2.2" />
            </button>
        @else
            <span class="flex h-11 items-center gap-1.5 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-faint">
                Next
                <x-icon name="chevron-right" class="size-[16px]" stroke-width="2.2" />
            </span>
        @endif
    </nav>
@endif
