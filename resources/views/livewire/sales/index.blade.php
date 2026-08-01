@php
    use App\Enums\DocumentType;
    use App\Support\Accent;
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    {{-- Page header --}}
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Sales</h1>
            <p class="mt-1 text-[14.5px] text-muted">Quotations, invoices, receipts and more.</p>
        </div>

        @can('sales.create')
            <a href="{{ route('documents.create', ['type' => $type]) }}"
               class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="hidden min-[420px]:inline">New {{ DocumentType::from($type)->label() }}</span>
                <span class="min-[420px]:hidden">New</span>
            </a>
        @endcan
    </div>

    {{-- Search --}}
    <div class="relative mt-5">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Search by number or customer…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    {{-- Document type tabs --}}
    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="tablist">
        @foreach (DocumentType::cases() as $docType)
            @php $isActive = $type === $docType->value; @endphp
            <button type="button" wire:click="setType('{{ $docType->value }}')" role="tab"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center gap-2 rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $isActive ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $docType->label() }}
                @if (($typeCounts[$docType->value] ?? 0) > 0)
                    <span class="tnum rounded-full px-1.5 text-[11.5px] {{ $isActive ? 'bg-white/20' : 'bg-surface-2 text-muted' }}">
                        {{ $typeCounts[$docType->value] }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Payment-state chips --}}
    <div class="no-scrollbar -mx-5 mt-3 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0">
        @foreach (['all' => 'All', 'paid' => 'Paid', 'pending' => 'Pending', 'overdue' => 'Overdue', 'draft' => 'Drafts'] as $key => $label)
            <button type="button" wire:click="setState('{{ $key }}')"
                    class="focusable h-9 shrink-0 rounded-full px-3.5 text-[13px] font-semibold transition-colors
                           {{ $state === $key ? 'bg-tint-blue text-brand' : 'text-muted hover:text-ink-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Results --}}
    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($documents as $index => $document)
            @php
                $docState = $document->paymentState();
                $accent = Accent::byIndex($index);
            @endphp
            <a href="{{ route('documents.show', $document) }}" wire:key="{{ $document->id }}"
               class="focusable flex items-center gap-3.5 rounded-xl px-3 py-3 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[46px] shrink-0 items-center justify-center rounded-xl {{ Accent::tint($accent) }}">
                    <x-icon name="document" class="size-[23px] {{ Accent::text($accent) }}" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink">{{ $document->number ?? 'Draft' }}</span>
                    <span class="block truncate text-[13px] text-muted">
                        {{ $document->contact?->displayName() ?? 'No customer' }}
                        <span class="hidden min-[480px]:inline">· {{ $document->issue_date?->format('M j, Y') }}</span>
                    </span>
                </span>

                <span class="shrink-0 text-right">
                    <span class="tnum block text-[15px] font-bold text-ink">
                        {{ Money::format($document->total, $document->currency) }}
                    </span>
                    <x-ui.status-badge :label="$docState['label']" :tone="$docState['tone']" class="mt-0.5" />
                </span>

                <x-icon name="chevron-right" class="size-[18px] shrink-0 text-faint" stroke-width="2" />
            </a>
        @empty
            <div class="flex flex-col items-center px-6 py-14 text-center">
                <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-blue">
                    <x-icon name="document" class="size-7 text-accent-blue" />
                </span>
                <p class="mt-4 text-[16px] font-semibold text-ink">
                    No {{ strtolower(DocumentType::from($type)->label()) }}s{{ $search !== '' ? ' match your search' : ' yet' }}
                </p>
                <p class="mt-1 max-w-xs text-[13.5px] text-muted">
                    @if ($search !== '')
                        Try a different number or customer name.
                    @else
                        Create your first one and it will appear here.
                    @endif
                </p>
            </div>
        @endforelse
    </div>

    @if ($documents->hasPages())
        <div class="mt-4">{{ $documents->links('pagination.opes') }}</div>
    @endif
</div>
