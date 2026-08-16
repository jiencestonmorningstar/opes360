@php
    use App\Models\PaymentRun;
    use App\Models\PaymentRunItem;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $statusTint = [
        PaymentRun::STATUS_DRAFT => ['bg-tint-slate', 'text-accent-slate'],
        PaymentRun::STATUS_APPROVED => ['bg-tint-amber', 'text-warning'],
        PaymentRun::STATUS_EXECUTED => ['bg-tint-green', 'text-positive'],
        PaymentRun::STATUS_CANCELLED => ['bg-surface-2', 'text-muted'],
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="min-w-0">
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Payment runs</h1>
        <p class="mt-1 text-[14.5px] text-muted">Built, approved, released — three acts, deliberately not one.</p>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Filter runs">
        @foreach (['open' => 'Open', 'executed' => 'Paid', 'all' => 'All'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2">
        @forelse ($runs as $index => $option)
            @php [$tint, $ink] = $statusTint[$option->status] ?? ['bg-surface-2', 'text-muted']; @endphp
            <button type="button" wire:key="run-{{ $option->id }}" wire:click="selectRun('{{ $option->id }}')"
                    aria-pressed="{{ $runId === $option->id ? 'true' : 'false' }}"
                    class="focusable flex w-full items-center gap-3.5 rounded-xl px-3 py-3 text-left transition-colors
                           {{ $index > 0 ? 'border-t border-border' : '' }} {{ $runId === $option->id ? 'bg-surface-2' : 'hover:bg-surface-2' }}">
                <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ $tint }}">
                    <x-icon name="banknotes" class="size-[16px] {{ $ink }}" stroke-width="2" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[14.5px] font-semibold text-ink">
                        {{ $option->reference ?: 'Run of '.$option->scheduled_for?->format('j M Y') }}
                    </span>
                    <span class="tnum block truncate text-[12.5px] text-muted">
                        {{ $option->scheduled_for?->format('j M Y') }} ·
                        {{ $option->items->where('status', '!=', PaymentRunItem::STATUS_SKIPPED)->count() }} bills
                    </span>
                </span>
                <span class="shrink-0 text-right">
                    <span class="tnum block text-[15px] font-bold text-ink">{{ $money($option->total()) }}</span>
                    <span class="block text-[11.5px] font-semibold {{ $ink }}">{{ $option->statusLabel() }}</span>
                </span>
            </button>
        @empty
            <div class="px-4 py-12 text-center">
                <p class="text-[15.5px] font-semibold text-ink">No runs here</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    A run starts life on the payment schedule: set the cash and the reserve, then build one.
                </p>
            </div>
        @endforelse
    </div>

    @if ($run !== null)
        @php
            $pending = $run->items->where('status', PaymentRunItem::STATUS_PENDING);
            $payable = $run->items->where('status', '!=', PaymentRunItem::STATUS_SKIPPED);
            [$tint, $ink] = $statusTint[$run->status] ?? ['bg-surface-2', 'text-muted'];
        @endphp

        <div class="card mt-5 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-[19px] font-bold tracking-[-0.02em] text-ink">
                        {{ $run->reference ?: 'Run of '.$run->scheduled_for?->format('j M Y') }}
                    </h2>
                    <p class="mt-1 text-[13.5px] text-muted">
                        Paying {{ $run->scheduled_for?->format('j M Y') }}
                        @if ($run->creator) · built by {{ $run->creator->name }} @endif
                        @if ($run->approver) · approved by {{ $run->approver->name }} {{ $run->approved_at?->format('j M') }} @endif
                    </p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1.5 text-[12.5px] font-bold {{ $tint }} {{ $ink }}">{{ $run->statusLabel() }}</span>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="card p-4">
                    <p class="text-[12.5px] font-medium text-muted">This run pays</p>
                    <p class="tnum mt-1 text-[18px] font-bold text-ink">{{ $money($run->total()) }}</p>
                </div>
                <div class="card p-4">
                    <p class="text-[12.5px] font-medium text-muted">Bills on it</p>
                    <p class="tnum mt-1 text-[18px] font-bold text-ink">{{ $payable->count() }}</p>
                </div>
                <div class="card p-4">
                    <p class="text-[12.5px] font-medium text-muted">Cash it was built against</p>
                    <p class="tnum mt-1 text-[18px] font-bold text-ink">{{ $money($run->cash_available) }}</p>
                </div>
                <div class="card p-4 {{ $run->isOverCash() ? 'ring-1 ring-negative/30' : '' }}">
                    <p class="text-[12.5px] font-medium text-muted">Left over</p>
                    <p class="tnum mt-1 text-[18px] font-bold {{ $run->isOverCash() ? 'text-negative' : 'text-ink' }}">
                        {{ $money($run->headroom()) }}
                    </p>
                </div>
            </div>

            @if ($run->isOverCash())
                <p class="mt-4 rounded-xl bg-tint-amber px-4 py-3 text-[13px] leading-relaxed text-warning">
                    This run spends more than the cash it was built against. Allowed — the business may know about
                    money the forecast does not — but somebody should know it before the money leaves.
                </p>
            @endif

            {{-- What execute() reported. Lines settled by hand between approval
                 and release are skipped with a reason rather than failing the
                 batch; that is a finding, not an error. --}}
            @if ($result !== null)
                <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] text-positive">
                    {{ $result['paid'] }} {{ Str::plural('bill', $result['paid']) }} paid, {{ $money($result['total']) }} in total.
                </div>
                @if (! empty($result['failed']))
                    <div class="mt-2 rounded-xl bg-tint-amber px-4 py-3 text-[13px] leading-relaxed text-warning">
                        {{ count($result['failed']) }} {{ Str::plural('line', count($result['failed'])) }} was left alone:
                        already settled or voided between approval and release. Nothing was paid twice.
                    </div>
                @endif
            @endif
        </div>

        {{-- Approval. Its own block, with its own explanation, and nowhere near
             the button that moves money. --}}
        @if ($run->status === PaymentRun::STATUS_DRAFT)
            @can('payables.approve')
                <div class="card mt-4 border border-border p-5">
                    <h3 class="text-[15.5px] font-bold text-ink">Approve this run</h3>
                    <p class="mt-1 max-w-2xl text-[13.5px] leading-relaxed text-muted">
                        Signing off {{ $money($run->total()) }} across {{ $payable->count() }}
                        {{ Str::plural('bill', $payable->count()) }}. Approving does not pay anybody; it fixes the run
                        so it stops changing underneath whoever releases it.
                    </p>
                    <button type="button" wire:click="approve"
                            class="tap focusable mt-4 flex h-12 items-center justify-center rounded-xl border-2 border-brand bg-surface px-6 text-[15px] font-semibold text-brand hover:bg-tint-blue">
                        Approve
                    </button>
                </div>
            @else
                <p class="mt-4 rounded-xl bg-surface-2 px-4 py-3 text-[13px] leading-relaxed text-muted">
                    This run is waiting on somebody who may approve payment runs. Building one and approving it are
                    deliberately different permissions.
                </p>
            @endcan
        @endif

        {{-- Release. Only reachable once approved, and only behind a step that
             spells out what leaves the bank. --}}
        @if ($run->status === PaymentRun::STATUS_APPROVED)
            @can('payables.execute')
                <div class="card mt-4 border-2 border-negative/40 p-5">
                    <h3 class="text-[15.5px] font-bold text-negative">Release the money</h3>
                    <p class="mt-1 max-w-2xl text-[13.5px] leading-relaxed text-muted">
                        This settles every pending bill on the run and writes the payments into the books. There is no
                        undo — reversing it means reversing each payment by hand.
                    </p>

                    @if ($confirmingExecute === $run->id)
                        <div class="mt-4 rounded-xl bg-tint-red px-4 py-4">
                            <p class="text-[15px] font-bold leading-relaxed text-negative">
                                Pay {{ $money($run->total()) }} to {{ $pending->pluck('expense.supplier_id')->filter()->unique()->count() }}
                                {{ Str::plural('supplier', $pending->pluck('expense.supplier_id')->filter()->unique()->count()) }},
                                across {{ $pending->count() }} {{ Str::plural('bill', $pending->count()) }}, dated
                                {{ $run->scheduled_for?->format('j M Y') }}?
                            </p>
                            <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                                <button type="button" wire:click="execute"
                                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-negative px-6 text-[15px] font-semibold text-white hover:opacity-90">
                                    Yes — pay {{ $money($run->total()) }}
                                </button>
                                <button type="button" wire:click="$set('confirmingExecute', null)"
                                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                                    Not yet
                                </button>
                            </div>
                        </div>
                    @else
                        <button type="button" wire:click="startExecuting"
                                class="tap focusable mt-4 flex h-12 items-center justify-center rounded-xl border-2 border-negative px-6 text-[15px] font-semibold text-negative hover:bg-tint-red">
                            Release {{ $money($run->total()) }}…
                        </button>
                    @endif
                </div>
            @endcan
        @endif

        <div class="card mt-4 p-2">
            @foreach ($run->items as $index => $item)
                <div wire:key="item-{{ $item->id }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                    <div class="flex items-center gap-3.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[14.5px] font-semibold text-ink">
                                {{ $item->expense?->supplier?->displayName() ?? 'Supplier' }}
                            </p>
                            <p class="tnum truncate text-[12.5px] text-muted">
                                {{ $item->expense?->reference ?: $item->expense?->description }}
                                @if ($item->note) · {{ $item->note }} @endif
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="tnum text-[15px] font-bold {{ $item->status === PaymentRunItem::STATUS_SKIPPED ? 'text-faint line-through' : 'text-ink' }}">
                                {{ $money($item->amount) }}
                            </p>
                            @if ($item->status === PaymentRunItem::STATUS_PAID)
                                <p class="text-[11.5px] font-semibold text-positive">Paid</p>
                            @elseif ($item->status === PaymentRunItem::STATUS_SKIPPED)
                                <p class="text-[11.5px] font-semibold text-faint">Struck out</p>
                            @endif
                        </div>
                    </div>

                    @can('payables.manage')
                        @if ($run->isEditable() && $item->status === PaymentRunItem::STATUS_PENDING)
                            <div class="mt-2.5 flex flex-wrap gap-2">
                                <button type="button" wire:click="startSkipping('{{ $item->id }}')"
                                        class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                                    Do not pay this one
                                </button>
                            </div>
                        @endif

                        @if ($skipping === $item->id)
                            <div class="mt-3 rounded-xl border border-border p-4">
                                <label class="{{ $labelClass }}" for="skip-{{ $item->id }}">Why not?</label>
                                <input id="skip-{{ $item->id }}" type="text" wire:model="skipNote" class="{{ $inputClass }}"
                                       placeholder="Goods short delivered — disputed">
                                @error('skipNote') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                                <p class="mt-1.5 text-[12.5px] text-faint">
                                    The line stays on the run. "Why was this supplier not paid" is the question a run gets asked.
                                </p>
                                <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                                    <button type="button" wire:click="skip"
                                            class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                                        Strike it out
                                    </button>
                                    <button type="button" wire:click="$set('skipping', null)"
                                            class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endcan
                </div>
            @endforeach
        </div>

        @can('payables.manage')
            @if ($run->isEditable())
                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" wire:click="startAdding"
                            class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                        Add a bill the plan missed
                    </button>
                    <button type="button" wire:click="cancel"
                            class="tap focusable flex h-11 items-center justify-center rounded-xl px-5 text-[14.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                        Cancel this run
                    </button>
                </div>

                @if ($adding)
                    <div class="card mt-4 border border-brand p-5">
                        <div class="grid gap-4 sm:grid-cols-3 sm:items-end">
                            <div class="sm:col-span-2">
                                <label class="{{ $labelClass }}" for="a-bill">Bill</label>
                                <select id="a-bill" wire:model="addExpenseId" class="{{ $inputClass }}">
                                    <option value="">Choose…</option>
                                    @foreach ($addable as $bill)
                                        <option value="{{ $bill->id }}">
                                            {{ $bill->supplier?->displayName() }} — {{ $bill->reference ?: $bill->description }}
                                            ({{ $money($bill->balance()) }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('addExpenseId') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}" for="a-amount">Amount</label>
                                <input id="a-amount" type="number" step="1" inputmode="decimal" wire:model="addAmount"
                                       class="{{ $inputClass }} tnum" placeholder="whole balance">
                                @error('addAmount') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-5 flex flex-col gap-3 sm:flex-row-reverse">
                            <button type="button" wire:click="addBill"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                                Add to run
                            </button>
                            <button type="button" wire:click="$set('adding', false)"
                                    class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            @endif
        @endcan
    @endif
</div>
