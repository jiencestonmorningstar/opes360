@php
    use App\Models\SupplierStatementLine;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="min-w-0">
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Supplier reconciliation</h1>
        <p class="mt-1 text-[14.5px] text-muted">What they say you owe, what your books say, and why the two differ.</p>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    <div class="card mt-5 p-5">
        <label class="{{ $labelClass }}" for="r-supplier">Supplier</label>
        <select id="r-supplier" wire:model.live="supplierId" class="{{ $inputClass }} max-w-md">
            <option value="">Choose…</option>
            @foreach ($suppliers as $option)
                <option value="{{ $option->id }}">{{ $option->displayName() }}</option>
            @endforeach
        </select>
    </div>

    @if ($supplier === null)
        <div class="card mt-5 px-4 py-12 text-center">
            <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                <x-icon name="truck" class="size-[24px] text-accent-slate" stroke-width="1.7" />
            </span>
            <p class="mt-4 text-[15.5px] font-semibold text-ink">Pick a supplier</p>
            <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                Then import the statement they sent, and find out which of the two balances is telling the truth.
            </p>
        </div>
    @else
        <div class="mt-4 flex flex-wrap gap-3">
            @can('payables.reconcile')
                <button type="button" wire:click="startImporting"
                        class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    Import their statement
                </button>
            @endcan
            @can('payables.statement-view')
                <button type="button" wire:click="$toggle('showOurRecord')"
                        class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    {{ $showOurRecord ? 'Hide our record' : 'Our record of this account' }}
                </button>
            @endcan
        </div>

        @if ($importing)
            <div class="card mt-4 border border-brand p-5">
                <p class="text-[13px] leading-relaxed text-muted">
                    Whatever their accounting package exports. Column names are matched loosely — date, libellé,
                    montant, or a debit and credit pair all work. Importing the same period twice is safe: identical
                    lines are skipped rather than doubled.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="{{ $labelClass }}" for="i-date">Statement date</label>
                        <input id="i-date" type="date" wire:model="statementDate" class="{{ $inputClass }}">
                        @error('statementDate') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="i-closing">Their closing balance</label>
                        <input id="i-closing" type="number" step="1" inputmode="decimal" wire:model="closingBalance" class="{{ $inputClass }} tnum">
                        @error('closingBalance') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="i-from">Period from</label>
                        <input id="i-from" type="date" wire:model="periodFrom" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="i-to">Period to</label>
                        <input id="i-to" type="date" wire:model="periodTo" class="{{ $inputClass }}">
                    </div>
                </div>

                <input type="file" wire:model="statementFile" accept=".csv,text/csv" aria-label="Supplier statement CSV"
                       class="mt-4 block w-full text-[14px] text-ink-2 file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-4 file:py-2.5 file:text-[13.5px] file:font-semibold file:text-ink-2">
                @error('statementFile') <p class="mt-2 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror

                <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                    <button type="button" wire:click="import"
                            class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                        Import
                    </button>
                    <button type="button" wire:click="$set('importing', false)"
                            class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        @if ($statements->isNotEmpty())
            <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0">
                @foreach ($statements as $option)
                    <button type="button" wire:key="st-{{ $option->id }}" wire:click="selectStatement('{{ $option->id }}')"
                            aria-pressed="{{ $statementId === $option->id ? 'true' : 'false' }}"
                            class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                                   {{ $statementId === $option->id ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                        {{ $option->statement_date?->format('j M Y') }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($ourRecord !== null)
            <div class="card mt-4 p-5">
                <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Our record of this account</h2>
                <p class="mt-1 text-[13px] text-muted">
                    {{ $ourRecord['from']->format('j M Y') }} to {{ $ourRecord['to']->format('j M Y') }}, opening
                    {{ $money($ourRecord['opening_balance']) }}.
                </p>
                <div class="mt-4 overflow-x-auto rounded-xl border border-border">
                    <table class="w-full min-w-[520px] text-[13px]">
                        <thead>
                            <tr class="border-b border-border bg-surface-2">
                                <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Date</th>
                                <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Reference</th>
                                <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Description</th>
                                <th class="px-3 py-2 text-right text-[11px] font-bold uppercase tracking-wide text-faint">Billed</th>
                                <th class="px-3 py-2 text-right text-[11px] font-bold uppercase tracking-wide text-faint">Paid</th>
                                <th class="px-3 py-2 text-right text-[11px] font-bold uppercase tracking-wide text-faint">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ourRecord['lines'] as $line)
                                <tr class="border-b border-border last:border-0">
                                    <td class="px-3 py-2 text-muted">{{ \Illuminate\Support\Carbon::parse($line['date'])->format('j M Y') }}</td>
                                    <td class="px-3 py-2 font-medium text-ink">{{ $line['reference'] }}</td>
                                    <td class="px-3 py-2 text-muted">{{ $line['description'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ $line['credit'] ? $money($line['credit']) : '' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-positive">{{ $line['debit'] ? $money($line['debit']) : '' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold text-ink">{{ $money($line['balance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-[13.5px] font-semibold text-ink">Closing {{ $money($ourRecord['closing_balance']) }}</p>
            </div>
        @endif

        @if ($statement === null)
            <div class="card mt-4 px-4 py-12 text-center">
                <p class="text-[15.5px] font-semibold text-ink">No statement from this supplier yet</p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    Import the one they sent and the difference between their account and yours becomes arithmetic
                    instead of an argument.
                </p>
            </div>
        @else
            {{-- The disagreement, first and largest. It is the whole reason the
                 screen exists, and it is what gets buried everywhere else. --}}
            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="card p-4">
                    <p class="text-[12.5px] font-medium text-muted">They say you owe</p>
                    <p class="tnum mt-1 text-[22px] font-bold tracking-[-0.02em] text-ink">{{ $money($summary['their_balance']) }}</p>
                </div>
                <div class="card p-4">
                    <p class="text-[12.5px] font-medium text-muted">Your books say</p>
                    <p class="tnum mt-1 text-[22px] font-bold tracking-[-0.02em] text-ink">{{ $money($summary['our_balance']) }}</p>
                </div>
                <div class="card p-4 {{ abs($summary['difference']) >= 1.0 ? 'ring-1 ring-negative/40' : '' }}">
                    <p class="text-[12.5px] font-medium text-muted">Difference</p>
                    <p class="tnum mt-1 text-[22px] font-bold tracking-[-0.02em] {{ abs($summary['difference']) >= 1.0 ? 'text-negative' : 'text-positive' }}">
                        {{ $money($summary['difference']) }}
                    </p>
                    <p class="mt-0.5 text-[11.5px] text-faint">
                        {{ $summary['difference'] > 0 ? 'They claim more than you have recorded' : ($summary['difference'] < 0 ? 'You have recorded more than they have billed' : 'The two agree') }}
                    </p>
                </div>
            </div>

            @unless ($summary['statement_self_consistent'])
                <p class="mt-3 rounded-xl bg-tint-amber px-4 py-3 text-[13px] leading-relaxed text-warning">
                    Their own lines add up to {{ $money($statement->lineTotal()) }}, not the
                    {{ $money($statement->closing_balance) }} they claim. That is their arithmetic, not yours — it is
                    left exactly as they sent it, and it is worth raising before anybody hunts for a missing invoice.
                </p>
            @endunless

            {{-- The two directions of the difference, side by side. Either one
                 alone reads as "we are wrong", which is usually not the case. --}}
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="card p-5">
                    <h3 class="text-[15.5px] font-bold text-ink">On their statement only</h3>
                    <p class="mt-1 text-[13px] leading-relaxed text-muted">
                        Charges they have made that your books do not account for. This is the direction that costs
                        money: an unnoticed duplicate charge gets paid.
                    </p>
                    <div class="mt-3 space-y-2">
                        @forelse ($summary['on_their_statement_only'] as $row)
                            <div class="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13.5px] font-medium text-ink">{{ $row['reference'] ?: $row['description'] ?: 'Unreferenced line' }}</p>
                                    <p class="tnum text-[12px] text-muted">
                                        {{ \Illuminate\Support\Carbon::parse($row['line_date'])->format('j M Y') }}
                                        @if ($row['status'] === SupplierStatementLine::STATUS_DISPUTED)
                                            · <span class="font-semibold text-negative">disputed</span>
                                        @endif
                                    </p>
                                </div>
                                <p class="tnum shrink-0 text-[14px] font-bold text-ink">{{ $money($row['amount']) }}</p>
                            </div>
                        @empty
                            <p class="text-[13px] text-muted">Nothing. Every line they sent is accounted for.</p>
                        @endforelse
                    </div>
                </div>

                <div class="card p-5">
                    <h3 class="text-[15.5px] font-bold text-ink">In your books only</h3>
                    <p class="mt-1 text-[13px] leading-relaxed text-muted">
                        Bills you have recorded that appear nowhere on their statement — usually an invoice they have
                        not raised yet, sometimes one already settled on their side.
                    </p>
                    <div class="mt-3 space-y-2">
                        @forelse ($summary['in_our_books_only'] as $row)
                            <div class="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13.5px] font-medium text-ink">{{ $row['reference'] ?: $row['description'] }}</p>
                                    <p class="tnum text-[12px] text-muted">{{ \Illuminate\Support\Carbon::parse($row['issue_date'])->format('j M Y') }}</p>
                                </div>
                                <p class="tnum shrink-0 text-[14px] font-bold text-ink">{{ $money($row['balance']) }}</p>
                            </div>
                        @empty
                            <p class="text-[13px] text-muted">Nothing. Every open bill of yours is on their statement.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <div class="no-scrollbar flex gap-2 overflow-x-auto" role="group" aria-label="Filter statement lines">
                    @foreach (['unmatched' => 'To match', 'matched' => 'Matched', 'disputed' => 'Disputed', 'ignored' => 'Set aside', 'all' => 'All'] as $key => $label)
                        <button type="button" wire:click="$set('filter', '{{ $key }}')"
                                aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                                class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                                       {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @can('payables.reconcile')
                    @if ($summary['unmatched_count'] > 0)
                        <button type="button" wire:click="autoMatch"
                                class="tap focusable flex h-10 items-center justify-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink hover:bg-surface-2">
                            Match the obvious ones
                        </button>
                    @endif
                @endcan
            </div>

            <div class="card mt-4 p-2" wire:loading.class="opacity-60">
                @forelse ($lines as $index => $line)
                    <div wire:key="line-{{ $line->id }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                        <div class="flex items-center gap-3.5">
                            <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ $line->isCharge() ? 'bg-tint-red' : 'bg-tint-green' }}">
                                <x-icon name="{{ $line->isCharge() ? 'receipt' : 'banknotes' }}"
                                        class="size-[16px] {{ $line->isCharge() ? 'text-negative' : 'text-positive' }}" stroke-width="2" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="line-clamp-2 text-[14.5px] font-semibold text-ink">
                                    {{ $line->description ?: $line->reference ?: 'Unreferenced line' }}
                                </p>
                                <p class="tnum truncate text-[12.5px] text-muted">
                                    {{ $line->line_date?->format('j M Y') }}
                                    @if ($line->reference) · {{ $line->reference }} @endif
                                    @if ($line->note) · {{ $line->note }} @endif
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="tnum text-[15px] font-bold text-ink">{{ $money($line->absoluteAmount()) }}</p>
                                @if ($line->isMatched())
                                    <p class="text-[11.5px] font-semibold text-positive">Matched</p>
                                @elseif ($line->status === SupplierStatementLine::STATUS_DISPUTED)
                                    <p class="text-[11.5px] font-semibold text-negative">Disputed</p>
                                @elseif ($line->status === SupplierStatementLine::STATUS_IGNORED)
                                    <p class="text-[11.5px] font-semibold text-faint">Set aside</p>
                                @endif
                            </div>
                        </div>

                        @can('payables.reconcile')
                            <div class="mt-2.5 flex flex-wrap gap-2 pl-[50px]">
                                @if ($line->isMatched())
                                    <button type="button" wire:click="unmatch('{{ $line->id }}')"
                                            class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                                        Unmatch
                                    </button>
                                @else
                                    <button type="button" wire:click="startMatching('{{ $line->id }}')"
                                            class="focusable rounded-lg bg-surface-2 px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                                        Find the bill
                                    </button>
                                    <button type="button" wire:click="startDisputing('{{ $line->id }}')"
                                            class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                                        Dispute it
                                    </button>
                                    @if ($line->status !== SupplierStatementLine::STATUS_IGNORED)
                                        <button type="button" wire:click="ignore('{{ $line->id }}')"
                                                class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                                            Set aside
                                        </button>
                                    @endif
                                @endif
                            </div>
                        @endcan

                        @if ($matching === $line->id)
                            <div class="mt-3 rounded-xl border border-brand bg-tint-blue/50 p-4">
                                <p class="text-[13px] font-semibold text-ink">Bills of yours that could be this line</p>
                                @forelse ($suggestions as $expense)
                                    <div class="mt-2.5 flex items-center gap-3 rounded-lg bg-surface p-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[13.5px] font-medium text-ink">
                                                {{ $expense->reference ?: $expense->description }}
                                            </p>
                                            <p class="tnum text-[12.5px] text-muted">
                                                {{ \Illuminate\Support\Carbon::parse($expense->issue_date)->format('j M Y') }} · {{ $money($expense->total) }}
                                            </p>
                                        </div>
                                        <button type="button" wire:click="match('{{ $line->id }}', '{{ $expense->id }}')"
                                                class="focusable shrink-0 rounded-lg bg-fill-brand px-3.5 py-2 text-[12.5px] font-semibold text-white hover:opacity-90">
                                            This one
                                        </button>
                                    </div>
                                @empty
                                    <p class="mt-2 text-[13px] leading-relaxed text-muted">
                                        Nothing of yours looks like it. Either the bill was never recorded, or they are
                                        charging for something you did not agree to — which is a dispute, not a match.
                                    </p>
                                @endforelse
                            </div>
                        @endif

                        @if ($disputing === $line->id)
                            <div class="mt-3 rounded-xl border border-brand bg-tint-blue/50 p-4">
                                <label class="{{ $labelClass }}" for="dispute-{{ $line->id }}">What is wrong with it?</label>
                                <input id="dispute-{{ $line->id }}" type="text" wire:model="disputeNote" class="{{ $inputClass }}"
                                       placeholder="Charged twice — same delivery note as FA-118">
                                @error('disputeNote') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                                <p class="mt-1.5 text-[12.5px] leading-relaxed text-faint">
                                    The line stays on the statement and in the difference. Nothing in your books changes.
                                </p>
                                <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                                    <button type="button" wire:click="dispute"
                                            class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                                        Mark disputed
                                    </button>
                                    <button type="button" wire:click="$set('disputing', null)"
                                            class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="px-4 py-12 text-center">
                        <p class="text-[15.5px] font-semibold text-ink">
                            {{ $filter === 'unmatched' ? 'Nothing left to match' : 'Nothing here' }}
                        </p>
                        <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                            {{ $filter === 'unmatched'
                                ? 'Every line they sent has been accounted for. Any difference left is explained by your side of the list above.'
                                : 'No lines with that status.' }}
                        </p>
                    </div>
                @endforelse
            </div>
        @endif
    @endif
</div>
