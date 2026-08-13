@php
    use App\Models\Deal;
    use App\Support\Money;

    $company = app(\App\Support\CurrentCompany::class)->get();
    $currency = $company?->currency ?? 'XAF';

    // Stage colours reach for the same accent tokens the rest of the app uses,
    // written out in full because Tailwind scans source text.
    $stageAccent = [
        'lead' => ['dot' => 'bg-accent-slate', 'tint' => 'bg-tint-slate', 'text' => 'text-accent-slate'],
        'qualified' => ['dot' => 'bg-accent-blue', 'tint' => 'bg-tint-blue', 'text' => 'text-accent-blue'],
        'proposal' => ['dot' => 'bg-accent-purple', 'tint' => 'bg-tint-purple', 'text' => 'text-accent-purple'],
        'won' => ['dot' => 'bg-accent-green', 'tint' => 'bg-tint-green', 'text' => 'text-accent-green'],
        'lost' => ['dot' => 'bg-accent-orange', 'tint' => 'bg-tint-orange', 'text' => 'text-accent-orange'],
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Pipeline</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $openCount }} {{ Str::plural('deal', $openCount) }} in progress
                @if ($openValue > 0)
                    &middot; <span class="tnum font-semibold text-ink-2">{{ Money::format($openValue, $currency, false) }}</span>
                @endif
            </p>
        </div>

        @can('deals.create')
            <a href="{{ route('deals.create') }}" wire:navigate
               class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.2" />
                <span class="sr-only min-[420px]:not-sr-only">Add Deal</span>
                <span class="min-[420px]:hidden">Add</span>
            </a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-medium text-positive">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mt-4 rounded-xl bg-tint-red px-4 py-3 text-[14px] font-medium text-negative">
            {{ session('error') }}
        </div>
    @endif

    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Search deals…"
                   class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
        </div>

        <label class="flex h-12 shrink-0 cursor-pointer items-center gap-2.5 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-ink-2">
            <input type="checkbox" wire:model.live="showClosed" class="size-4 rounded border-border-strong text-brand focus:ring-brand/30">
            Show closed
        </label>
    </div>

    {{-- The board. Scrolls sideways rather than reflowing: columns that stack
         on a phone stop being a pipeline and become five lists. --}}
    <div class="no-scrollbar -mx-5 mt-5 overflow-x-auto px-5 lg:mx-0 lg:px-0" wire:loading.class="opacity-60">
        <div class="flex min-w-max gap-3 pb-2">
            @foreach ($columns as $stage => $deals)
                @php $accent = $stageAccent[$stage]; @endphp
                <div class="flex w-[272px] shrink-0 flex-col">
                    <div class="flex items-center justify-between gap-2 px-1 pb-2.5">
                        <span class="flex items-center gap-2 text-[13px] font-bold uppercase tracking-wide text-ink-2">
                            <span class="size-2 rounded-full {{ $accent['dot'] }}"></span>
                            {{ Deal::STAGES[$stage] }}
                            <span class="tnum rounded-full bg-surface-2 px-1.5 text-[11.5px] font-semibold text-muted">{{ $deals->count() }}</span>
                        </span>
                        @if (($totals[$stage] ?? 0) > 0)
                            <span class="tnum text-[12px] font-semibold text-faint">{{ Money::format($totals[$stage], $currency, false) }}</span>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col gap-2 rounded-2xl bg-surface-2/70 p-2">
                        @forelse ($deals as $deal)
                            <div wire:key="deal-{{ $deal->id }}" class="card p-3">
                                <a href="{{ route('deals.edit', $deal) }}" wire:navigate class="focusable block">
                                    <p class="text-[14px] font-semibold leading-snug text-ink">{{ $deal->title }}</p>
                                    <p class="mt-1 truncate text-[12.5px] text-muted">{{ $deal->displayName() }}</p>
                                </a>

                                <div class="mt-2.5 flex items-center justify-between gap-2">
                                    <span class="tnum text-[13px] font-bold text-ink">{{ Money::format($deal->value, $currency, false) }}</span>
                                    @if ($deal->expected_close_on)
                                        <span class="text-[11.5px] {{ $deal->isOpen() && $deal->expected_close_on->isPast() ? 'font-semibold text-negative' : 'text-faint' }}">
                                            {{ $deal->expected_close_on->format('j M') }}
                                        </span>
                                    @endif
                                </div>

                                @can('update', $deal)
                                    {{-- A select rather than drag-and-drop: dragging is the
                                         first thing to break on a phone, and this is the
                                         same action with none of that risk. --}}
                                    <select wire:change="move('{{ $deal->id }}', $event.target.value)"
                                            aria-label="Move {{ $deal->title }} to another stage"
                                            class="mt-2.5 h-9 w-full rounded-lg border border-border bg-surface px-2 text-[12.5px] font-medium text-ink-2 focus:border-brand focus:outline-none">
                                        @foreach (Deal::STAGES as $key => $label)
                                            <option value="{{ $key }}" @selected($deal->stage === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>

                                    {{-- The next thing somebody wants after winning:
                                         the invoice. Draft, so the lines can be checked
                                         before it becomes paper. --}}
                                    @if ($deal->stage === 'won')
                                        @if ($deal->document_id)
                                            <a href="{{ route('documents.show', $deal->document_id) }}" wire:navigate
                                               class="focusable mt-2 flex h-9 items-center justify-center gap-1.5 rounded-lg bg-tint-green text-[12.5px] font-semibold text-positive">
                                                <x-icon name="document" class="size-[13px]" stroke-width="2" />
                                                View invoice
                                            </a>
                                        @elsecan('sales.create')
                                            <button type="button" wire:click="invoice('{{ $deal->id }}')"
                                                    wire:loading.attr="disabled" wire:target="invoice('{{ $deal->id }}')"
                                                    class="tap focusable mt-2 flex h-9 w-full items-center justify-center gap-1.5 rounded-lg bg-fill-brand text-[12.5px] font-semibold text-white transition-opacity hover:opacity-90">
                                                <x-icon name="document-plus" class="size-[13px]" stroke-width="2" />
                                                Create invoice
                                            </button>
                                        @endif
                                    @endif
                                @endcan
                            </div>
                        @empty
                            <p class="px-2 py-6 text-center text-[12.5px] text-faint">Nothing here.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @if ($openCount === 0 && $search === '')
        <div class="card mt-5 p-8 text-center">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-tint-blue">
                <x-icon name="trending-up" class="size-[22px] text-accent-blue" stroke-width="1.8" />
            </span>
            <p class="mt-4 text-[15.5px] font-semibold text-ink">No deals yet</p>
            <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                A deal is a sale you are in the middle of making — an enquiry, a quotation sent,
                a customer deciding. Add the first one and it shows up on this board.
            </p>
        </div>
    @endif
</div>
