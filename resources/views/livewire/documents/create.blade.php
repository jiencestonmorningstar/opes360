@php
    use App\Support\Accent;
    use App\Support\Money;

    $totals = $this->totals();
@endphp

<div class="px-5 pb-32 lg:px-6 lg:pt-6 lg:pb-8">

    {{-- Header --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('sales', ['type' => $type]) }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink"
           aria-label="Back to sales">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <div>
            <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
                New {{ $docType->label() }}
            </h1>
            <p class="mt-0.5 text-[13.5px] text-muted">The number is assigned when you issue it.</p>
        </div>
    </div>

    <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">

            {{-- Customer picker --}}
            <x-ui.panel title="Customer">
                @if ($selectedContact)
                    @php $accent = $selectedContact->accent(); @endphp
                    <div class="flex items-center gap-3.5">
                        <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[13px] font-bold {{ Accent::text($accent) }}">
                            {{ $selectedContact->initials() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-semibold text-ink">{{ $selectedContact->displayName() }}</p>
                            <p class="truncate text-[13px] text-muted">{{ $selectedContact->email ?? data_get($selectedContact->phones, 0, '') }}</p>
                        </div>
                        <button type="button" wire:click="clearContact"
                                class="focusable shrink-0 text-[13.5px] font-semibold text-brand hover:underline">
                            Change
                        </button>
                    </div>
                @else
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
                        <input type="search" wire:model.live.debounce.250ms="contactSearch"
                               placeholder="Search customers…"
                               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </div>

                    @if ($contactResults->isNotEmpty())
                        <div class="mt-2 overflow-hidden rounded-xl border border-border">
                            @foreach ($contactResults as $result)
                                <button type="button" wire:click="selectContact('{{ $result->id }}')" wire:key="c-{{ $result->id }}"
                                        class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-2 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                                    <span class="flex size-[34px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($result->accent()) }} text-[11.5px] font-bold {{ Accent::text($result->accent()) }}">
                                        {{ $result->initials() }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-[14px] font-medium text-ink">{{ $result->displayName() }}</span>
                                        <span class="block truncate text-[12.5px] text-muted">{{ $result->email }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($contactSearch)) > 0)
                        <p class="mt-3 text-[13.5px] text-muted">No customers match "{{ $contactSearch }}".</p>
                    @endif
                @endif

                @error('contactId')
                    <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p>
                @enderror
            </x-ui.panel>

            {{-- Line items --}}
            <x-ui.panel title="Items">
                <div class="space-y-4">
                    @foreach ($lines as $index => $line)
                        <div wire:key="line-{{ $index }}"
                             class="rounded-xl border border-border p-3.5 {{ $errors->has('lines.'.$index.'.*') ? 'border-warning/60' : '' }}">
                            <div class="flex items-start gap-2">
                                <input type="text" wire:model.live.debounce.400ms="lines.{{ $index }}.description"
                                       placeholder="What are you charging for?"
                                       class="h-11 min-w-0 flex-1 rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                <button type="button" wire:click="removeLine({{ $index }})"
                                        class="tap focusable flex shrink-0 items-center justify-center rounded-lg text-faint hover:text-warning"
                                        aria-label="Remove item">
                                    <x-icon name="plus" class="size-[20px] rotate-45" stroke-width="2.2" />
                                </button>
                            </div>

                            <div class="mt-2.5 flex items-center gap-2">
                                <label class="min-w-0 flex-1">
                                    <span class="mb-1 block text-[11.5px] font-medium uppercase tracking-wide text-faint">Qty</span>
                                    <input type="number" step="any" min="0" inputmode="decimal"
                                           wire:model.live.debounce.400ms="lines.{{ $index }}.quantity"
                                           class="tnum h-11 w-full rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                </label>
                                <label class="min-w-0 flex-[1.4]">
                                    <span class="mb-1 block text-[11.5px] font-medium uppercase tracking-wide text-faint">Unit price</span>
                                    <input type="number" step="any" min="0" inputmode="decimal"
                                           wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price"
                                           placeholder="0.00"
                                           class="tnum h-11 w-full rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                </label>
                                <div class="min-w-0 flex-[1.2] text-right">
                                    <span class="mb-1 block text-[11.5px] font-medium uppercase tracking-wide text-faint">Total</span>
                                    <span class="tnum block h-11 content-center text-[15px] font-semibold text-ink">
                                        {{ Money::format((float) ($line['quantity'] ?: 0) * (float) ($line['unit_price'] ?: 0), $currency) }}
                                    </span>
                                </div>
                            </div>

                            @foreach (['description', 'quantity', 'unit_price'] as $field)
                                @error('lines.'.$index.'.'.$field)
                                    <p class="mt-2 text-[12.5px] font-medium text-warning">{{ $message }}</p>
                                @enderror
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <button type="button" wire:click="addLine"
                        class="focusable mt-4 flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-border-strong text-[14px] font-semibold text-brand hover:bg-tint-blue">
                    <x-icon name="plus" class="size-[17px]" stroke-width="2.4" />
                    Add Item
                </button>
            </x-ui.panel>

            {{-- Dates + notes --}}
            <x-ui.panel title="Details">
                <div class="grid grid-cols-2 gap-3">
                    <label>
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Issue date</span>
                        <input type="date" wire:model="issueDate"
                               class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </label>
                    <label>
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Due date</span>
                        <input type="date" wire:model="dueDate"
                               class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </label>
                </div>
                @error('dueDate')
                    <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p>
                @enderror

                <label class="mt-4 block">
                    <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Notes <span class="font-normal text-faint">(optional)</span></span>
                    <textarea wire:model="notes" rows="3" placeholder="Payment terms, thank-you note…"
                              class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                </label>
            </x-ui.panel>
        </div>

        {{-- Summary + actions (sticky bar on mobile, side panel on desktop) --}}
        <div>
            <div class="card fixed inset-x-0 bottom-0 z-30 rounded-b-none border-x-0 border-b-0 p-5 lg:static lg:z-auto lg:rounded-[var(--radius-card)] lg:border"
                 style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <div class="flex items-center justify-between lg:mb-4">
                    <span class="text-[14px] font-medium text-muted">Total</span>
                    <span class="tnum text-[22px] font-bold tracking-[-0.02em] text-ink">
                        {{ Money::format($totals['total'], $currency) }}
                    </span>
                </div>

                <div class="mt-3.5 flex gap-3 lg:mt-0 lg:flex-col">
                    <button type="button" wire:click="saveDraft" wire:loading.attr="disabled"
                            class="focusable h-12 flex-1 rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                        Save Draft
                    </button>
                    <button type="button" wire:click="saveAndIssue" wire:loading.attr="disabled"
                            class="focusable h-12 flex-[1.4] rounded-xl bg-brand text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90 lg:flex-1">
                        <span wire:loading.remove wire:target="saveAndIssue">Issue {{ $docType->label() }}</span>
                        <span wire:loading wire:target="saveAndIssue">Issuing…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
