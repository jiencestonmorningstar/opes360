@php
    use App\Models\Expense;
    use App\Support\Accent;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Expenses</h1>
            <p class="mt-1 text-[14.5px] text-muted">Supplier bills and what you spend day to day.</p>
        </div>

        @can('expenses.create')
            <button type="button" wire:click="startRecording"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="hidden min-[420px]:inline">Record</span>
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    {{-- Two numbers, because they answer different questions: what did it cost
         us, and what have we not yet handed over. --}}
    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-2">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Spent {{ $period === 'all' ? 'in total' : 'this '.$period }}</p>
            <p class="tnum mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $money($spent) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Still owed to suppliers</p>
            <p class="tnum mt-1 text-[20px] font-bold tracking-[-0.02em] {{ $owing > 0 ? 'text-warning' : 'text-ink' }}">{{ $money($owing) }}</p>
        </div>
    </div>

    {{-- Record --}}
    @if ($recording)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Record an expense</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Leave the due date empty for something paid on the spot. Fill it in and this becomes a
                supplier bill you owe.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}" for="exp-description">What was it for?</label>
                    <input id="exp-description" type="text" wire:model="description" class="{{ $inputClass }}" placeholder="Carburant — livraison Bonabéri">
                    @error('description') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="exp-category">Category</label>
                    <select id="exp-category" wire:model="category" class="{{ $inputClass }}">
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="exp-supplier">Supplier <span class="font-normal text-faint">(optional)</span></label>
                    <select id="exp-supplier" wire:model="supplierId" class="{{ $inputClass }}">
                        <option value="">None</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->displayName() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="exp-amount">Amount before tax</label>
                    <input id="exp-amount" type="number" step="0.01" min="0" inputmode="decimal" wire:model="amount" class="{{ $inputClass }} tnum">
                    @error('amount') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="exp-vat">TVA</label>
                    <select id="exp-vat" wire:model="vatRate" class="{{ $inputClass }}">
                        <option value="0">No TVA</option>
                        <option value="0.1925">19.25%</option>
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="exp-issued">Date</label>
                    <input id="exp-issued" type="date" wire:model="issueDate" class="{{ $inputClass }}">
                    @error('issueDate') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="exp-due">Due date <span class="font-normal text-faint">(makes it a bill)</span></label>
                    <input id="exp-due" type="date" wire:model.live="dueDate" class="{{ $inputClass }}">
                    @error('dueDate') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                @if (! $dueDate)
                    <div>
                        <label class="{{ $labelClass }}" for="exp-method">Paid by</label>
                        <select id="exp-method" wire:model="paymentMethod" class="{{ $inputClass }}">
                            @foreach (Expense::METHODS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="{{ $labelClass }}" for="exp-reference">Their invoice no. <span class="font-normal text-faint">(optional)</span></label>
                    <input id="exp-reference" type="text" wire:model="reference" class="{{ $inputClass }}" placeholder="FA-8871">
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Record expense
                </button>
                <button type="button" wire:click="cancel"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <div class="relative mt-5">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.300ms="search" aria-label="Search expenses"
               placeholder="Search by description or reference…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="tablist">
        @foreach (['all' => 'All', 'owing' => 'Owing', 'overdue' => 'Overdue', 'paid' => 'Settled'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')" role="tab"
                    aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach

        <span class="mx-1 w-px shrink-0 bg-border"></span>

        @foreach (['month' => 'This month', 'quarter' => 'Quarter', 'year' => 'Year', 'all' => 'All time'] as $key => $label)
            <button type="button" wire:click="$set('period', '{{ $key }}')"
                    aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $period === $key ? 'bg-tint-blue text-brand' : 'text-muted hover:text-ink-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($expenses as $index => $expense)
            @php $accent = Accent::forKey($expense->category); @endphp
            <div wire:key="{{ $expense->id }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <div class="flex items-center gap-3.5">
                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }}">
                        <x-icon name="banknotes" class="size-[18px] {{ Accent::text($accent) }}" stroke-width="1.9" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[15px] font-semibold text-ink">{{ $expense->description }}</p>
                        <p class="truncate text-[13px] text-muted">
                            {{ $expense->categoryLabel() }}
                            @if ($expense->supplier) · {{ $expense->supplier->displayName() }} @endif
                            · {{ $expense->issue_date?->format('j M') }}
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="tnum text-[15px] font-bold text-ink">{{ $money($expense->total) }}</p>
                        @if ($expense->isPaid())
                            <p class="text-[11.5px] font-semibold text-positive">Settled</p>
                        @elseif ($expense->isOverdue())
                            <p class="text-[11.5px] font-semibold text-negative">Overdue</p>
                        @else
                            <p class="tnum text-[11.5px] font-semibold text-warning">{{ $money($expense->balance()) }} owing</p>
                        @endif
                    </div>
                </div>

                @if (! $expense->isPaid())
                    <div class="mt-2.5 flex flex-wrap gap-2 pl-[54px]">
                        @can('expenses.pay')
                            <button type="button" wire:click="startSettling('{{ $expense->id }}')"
                                    class="focusable rounded-lg bg-surface-2 px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                                Record payment
                            </button>
                        @endcan
                        @can('expenses.void')
                            <button type="button" wire:click="void('{{ $expense->id }}')"
                                    wire:confirm="Void this expense and reverse its journal entry?"
                                    class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-negative hover:bg-tint-red">
                                Void
                            </button>
                        @endcan
                    </div>
                @endif

                @if ($settling === $expense->id)
                    <div class="mt-3 rounded-xl border border-brand bg-tint-blue/50 p-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="{{ $labelClass }}" for="pay-amount-{{ $expense->id }}">Amount</label>
                                <input id="pay-amount-{{ $expense->id }}" type="number" step="0.01" min="0" inputmode="decimal"
                                       wire:model="payAmount" class="{{ $inputClass }} tnum">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}" for="pay-method-{{ $expense->id }}">Method</label>
                                <select id="pay-method-{{ $expense->id }}" wire:model="payMethod" class="{{ $inputClass }}">
                                    @foreach (Expense::METHODS as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}" for="pay-ref-{{ $expense->id }}">Reference</label>
                                <input id="pay-ref-{{ $expense->id }}" type="text" wire:model="payReference" class="{{ $inputClass }}">
                            </div>
                        </div>
                        @error('payAmount') <p class="mt-2 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror

                        <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                            <button type="button" wire:click="pay"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                                Record payment
                            </button>
                            <button type="button" wire:click="cancel"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-12 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="banknotes" class="size-[24px] text-accent-slate" stroke-width="1.7" />
                </span>
                <p class="mt-4 text-[15.5px] font-semibold text-ink">
                    {{ $search !== '' || $filter !== 'all' ? 'Nothing matches that.' : 'Nothing recorded yet' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-xs text-[13.5px] leading-relaxed text-muted">
                    {{ $search !== '' || $filter !== 'all'
                        ? 'Try a different search, or clear the filter.'
                        : 'Record what the business spends and it lands in the books alongside what it earns — which is what makes a profit figure mean anything.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($expenses->hasPages())
        <div class="mt-5">{{ $expenses->links() }}</div>
    @endif
</div>
