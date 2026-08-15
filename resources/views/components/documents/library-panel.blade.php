@props([
    // Any ERP record — a contact, an invoice, an employee, a purchase order.
    // The panel knows nothing about which; it asks the linker.
    'record' => null,
    'title' => 'Library',
    'limit' => 6,
])

@php
    use App\Services\Documents\DocumentLinker;

    /*
     * Named "Library", not "Documents", and the distinction is load-bearing.
     *
     * On a customer profile there is already a Documents panel, and it lists
     * invoices and quotations — transactional records owned by the sales
     * module. A second panel with the same name would leave somebody guessing
     * which one holds the signed contract. Library is what the route and the
     * permissions already call this, so the name is consistent as well as
     * distinct.
     */
    $linker = app(DocumentLinker::class);

    $papers = $record === null
        ? collect()
        : $linker->documentsFor($record)->take($limit);

    $total = $record === null ? 0 : $linker->countFor($record);
@endphp

@can('papers.view')
    <x-ui.panel :title="$title" body-class="-mx-1.5">
        @forelse ($papers as $index => $paper)
            @php $state = $paper->state(); @endphp
            <a href="{{ route('papers.show', $paper) }}" wire:key="lib-{{ $paper->id }}"
               class="focusable flex items-center gap-3 rounded-lg px-1.5 py-2.5 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="document" class="size-[18px] text-accent-slate" stroke-width="1.9" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $paper->title }}</span>
                    <span class="block truncate text-[12.5px] text-muted">
                        {{ $paper->kindLabel() }}
                        @if ($paper->issued_at)
                            · {{ $paper->issued_at->format('M j, Y') }}
                        @endif
                        @if ($paper->isConfidential())
                            · <span class="font-semibold text-warning">Confidential</span>
                        @endif
                    </span>
                </span>

                <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />
                <x-icon name="chevron-right" class="size-[16px] shrink-0 text-faint" stroke-width="2" />
            </a>
        @empty
            <p class="px-1.5 py-6 text-center text-[13.5px] text-muted">
                No documents filed against this record yet.
            </p>
        @endforelse

        @if ($total > $papers->count())
            <p class="px-1.5 pt-2 text-[12.5px] text-faint">
                Showing {{ $papers->count() }} of {{ $total }}.
            </p>
        @endif

        @can('papers.create')
            <a href="{{ route('papers') }}" wire:navigate
               class="focusable mt-2 flex items-center justify-center gap-2 rounded-lg border border-border px-3 py-2.5 text-[13.5px] font-semibold text-ink-2 transition-colors hover:bg-surface-2">
                <x-icon name="plus" class="size-[15px]" stroke-width="2.2" />
                Add a document
            </a>
        @endcan
    </x-ui.panel>
@endcan
