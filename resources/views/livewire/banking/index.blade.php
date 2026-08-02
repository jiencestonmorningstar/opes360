@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Banking</h1>
            <p class="mt-1 text-[14.5px] text-muted">Lay the statement beside the books and see what does not agree.</p>
        </div>

        @can('banking.manage')
            <button type="button" wire:click="startAddingAccount"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="sr-only min-[420px]:not-sr-only">Account</span>
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ session('error') }}</div>
    @endif

    @if ($addingAccount)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Add a bank account</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Each account needs its own ledger account. Two banks pointing at the same one would mean each
                reconciliation sees the other's movements — which reconciles neither.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="b-name">Name it</label>
                    <input id="b-name" type="text" wire:model="accountName" class="{{ $inputClass }}" placeholder="Compte courant">
                    @error('accountName') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="b-bank">Bank</label>
                    <input id="b-bank" type="text" wire:model="bankName" class="{{ $inputClass }}" placeholder="UBA Cameroun">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="b-number">Account number / RIB</label>
                    <input id="b-number" type="text" wire:model="accountNumber" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="b-ledger">Ledger account</label>
                    <select id="b-ledger" wire:model="ledgerAccountId" class="{{ $inputClass }}">
                        <option value="">Choose…</option>
                        @foreach ($ledgerAccounts as $ledger)
                            <option value="{{ $ledger->id }}">{{ $ledger->number }} — {{ $ledger->name }}</option>
                        @endforeach
                    </select>
                    @error('ledgerAccountId') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="saveAccount"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Add account
                </button>
                <button type="button" wire:click="$set('addingAccount', false)"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    @if ($accounts->count() > 1)
        <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0">
            @foreach ($accounts as $option)
                <button type="button" wire:click="$set('accountId', '{{ $option->id }}')"
                        aria-pressed="{{ $accountId === $option->id ? 'true' : 'false' }}"
                        class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                               {{ $accountId === $option->id ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                    {{ $option->name }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($bankAccount === null)
        <div class="card mt-5 px-4 py-12 text-center">
            <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                <x-icon name="credit-card" class="size-[24px] text-accent-slate" stroke-width="1.7" />
            </span>
            <p class="mt-4 text-[15.5px] font-semibold text-ink">No bank account set up yet</p>
            <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                Add one and you can import a statement, match it against the books, and find out which of the two
                balances is telling the truth.
            </p>
        </div>
    @else
        {{-- The arithmetic, not a verdict. The difference line is the one that
             matters: if it is not zero, something is genuinely wrong. --}}
        <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-[12.5px] font-medium text-muted">Books say</p>
                <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($summary['book_balance']) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-[12.5px] font-medium text-muted">Bank says</p>
                <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $money($summary['statement_balance']) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-[12.5px] font-medium text-muted">Not yet matched</p>
                <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] {{ $summary['unmatched_count'] > 0 ? 'text-warning' : 'text-ink' }}">
                    {{ $summary['unmatched_count'] }}
                </p>
            </div>
            <div class="card p-4 {{ abs($summary['difference']) >= 0.005 ? 'ring-1 ring-negative/30' : '' }}">
                <p class="text-[12.5px] font-medium text-muted">Unexplained</p>
                <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] {{ abs($summary['difference']) >= 0.005 ? 'text-negative' : 'text-positive' }}">
                    {{ $money($summary['difference']) }}
                </p>
            </div>
        </div>

        @if ($summary['unmatched_book'] != 0.0)
            <p class="mt-3 rounded-xl bg-tint-amber px-4 py-3 text-[13px] leading-relaxed text-warning">
                {{ $money(abs($summary['unmatched_book'])) }} is in your books but has not appeared on the statement —
                usually a cheque that has not cleared.
            </p>
        @endif

        <div class="card mt-5 p-5">
            <div class="grid gap-4 sm:grid-cols-3 sm:items-end">
                <div>
                    <label class="{{ $labelClass }}" for="s-balance">Closing balance on the statement</label>
                    <input id="s-balance" type="number" step="0.01" inputmode="decimal" wire:model="statementBalance" class="{{ $inputClass }} tnum">
                    @error('statementBalance') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="s-date">As at</label>
                    <input id="s-date" type="date" wire:model="statementDate" class="{{ $inputClass }}">
                </div>
                @can('banking.reconcile')
                    <button type="button" wire:click="saveStatementBalance"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                        Save
                    </button>
                @endcan
            </div>

            {{-- The line under everything already settled. Without one a
                 business years into trading can never reconcile: every
                 movement it ever posted counts as not yet on the statement. --}}
            <div class="mt-5 border-t border-border pt-5">
                <p class="text-[13px] font-semibold text-ink-2">Where the reconciliation starts</p>
                <p class="mt-1 text-[12.5px] leading-relaxed text-muted">
                    The date everything was last agreed up to, and the balance you both agreed on. Leave it blank if
                    this account is new and every movement is still open.
                </p>
                <div class="mt-3 grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div>
                        <label class="{{ $labelClass }}" for="o-balance">Agreed balance</label>
                        <input id="o-balance" type="number" step="0.01" inputmode="decimal" wire:model="openingBalance" class="{{ $inputClass }} tnum">
                        @error('openingBalance') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="o-date">Agreed up to</label>
                        <input id="o-date" type="date" wire:model="openedOn" class="{{ $inputClass }}">
                        @error('openedOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    @can('banking.reconcile')
                        <button type="button" wire:click="saveOpeningPosition"
                                class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                            Save starting point
                        </button>
                    @endcan
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-3 border-t border-border pt-5">
                @can('banking.import')
                    <button type="button" wire:click="$toggle('importing')"
                            class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                        Import a CSV
                    </button>
                    <button type="button" wire:click="startAddingLine"
                            class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                        Add a line by hand
                    </button>
                @endcan
            </div>

            @if ($importing)
                <div class="mt-4 rounded-xl border border-brand bg-tint-blue/50 p-4">
                    <p class="text-[13px] leading-relaxed text-muted">
                        Whatever your bank exports. Column names are matched loosely — date, libellé, montant, or a
                        debit and credit pair all work. Importing a period twice is safe: lines already there are
                        skipped.
                    </p>
                    <input type="file" wire:model="statementFile" accept=".csv,text/csv"
                           aria-label="Statement CSV"
                           class="mt-3 block w-full text-[14px] text-ink-2 file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-4 file:py-2.5 file:text-[13.5px] file:font-semibold file:text-ink-2">
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

            @if ($addingLine)
                <div class="mt-4 rounded-xl border border-brand bg-tint-blue/50 p-4">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div>
                            <label class="{{ $labelClass }}" for="l-date">Date</label>
                            <input id="l-date" type="date" wire:model="lineDate" class="{{ $inputClass }}">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="{{ $labelClass }}" for="l-desc">Description</label>
                            <input id="l-desc" type="text" wire:model="lineDescription" class="{{ $inputClass }}" placeholder="Frais de tenue de compte">
                            @error('lineDescription') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="l-amount">Amount</label>
                            <input id="l-amount" type="number" step="0.01" inputmode="decimal" wire:model="lineAmount" class="{{ $inputClass }} tnum" placeholder="-5000">
                            @error('lineAmount') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="mt-2 text-[12.5px] text-faint">Negative for money out, positive for money in.</p>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                        <button type="button" wire:click="saveLine"
                                class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                            Add line
                        </button>
                        <button type="button" wire:click="$set('addingLine', false)"
                                class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>

        <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Filter statement lines">
            @foreach (['unmatched' => 'To match', 'matched' => 'Matched', 'ignored' => 'Set aside', 'all' => 'All'] as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')"
                        aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                        class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                               {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="card mt-4 p-2" wire:loading.class="opacity-60">
            @forelse ($lines as $index => $line)
                <div wire:key="{{ $line->id }}" class="rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                    <div class="flex items-center gap-3.5">
                        <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ $line->isCredit() ? 'bg-tint-green' : 'bg-tint-red' }}">
                            <x-icon name="{{ $line->isCredit() ? 'arrow-up' : 'banknotes' }}"
                                    class="size-[16px] {{ $line->isCredit() ? 'text-positive' : 'text-negative' }}"
                                    stroke-width="2" />
                        </span>

                        <div class="min-w-0 flex-1">
                            {{-- Wrapped rather than truncated: a bank's own
                                 wording is how anyone recognises the movement,
                                 and "Chèque n° …" identifies nothing. --}}
                            <p class="line-clamp-2 text-[14.5px] font-semibold text-ink">{{ $line->description }}</p>
                            <p class="tnum truncate text-[12.5px] text-muted">
                                {{ $line->value_date?->format('j M Y') }}
                                @if ($line->reference) · {{ $line->reference }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="tnum text-[15px] font-bold {{ $line->isCredit() ? 'text-positive' : 'text-ink' }}">
                                {{ $line->isCredit() ? '+' : '−' }}{{ $money($line->absoluteAmount()) }}
                            </p>
                            @if ($line->isMatched())
                                <p class="text-[11.5px] font-semibold text-positive">Matched</p>
                            @elseif ($line->isIgnored())
                                <p class="text-[11.5px] font-semibold text-faint">Set aside</p>
                            @endif
                        </div>
                    </div>

                    @can('banking.reconcile')
                        <div class="mt-2.5 flex flex-wrap gap-2 pl-[50px]">
                            @if ($line->isMatched())
                                <button type="button" wire:click="unmatch('{{ $line->id }}')"
                                        class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                                    Unmatch
                                </button>
                            @else
                                <button type="button" wire:click="startMatching('{{ $line->id }}')"
                                        class="focusable rounded-lg bg-surface-2 px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                                    Find a match
                                </button>
                                <button type="button" wire:click="startRecording('{{ $line->id }}')"
                                        class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                                    Record it
                                </button>
                                @unless ($line->isIgnored())
                                    <button type="button" wire:click="ignore('{{ $line->id }}')"
                                            class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                                        Set aside
                                    </button>
                                @endunless
                            @endif
                        </div>
                    @endcan

                    @if ($matching === $line->id)
                        <div class="mt-3 rounded-xl border border-brand bg-tint-blue/50 p-4">
                            <p class="text-[13px] font-semibold text-ink">Entries of the same amount, around that date</p>
                            @forelse ($suggestions as $book)
                                <div class="mt-2.5 flex items-center gap-3 rounded-lg bg-surface p-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13.5px] font-medium text-ink">
                                            {{ $book->entry?->narration ?: $book->narration ?: 'Journal entry' }}
                                        </p>
                                        <p class="tnum text-[12.5px] text-muted">
                                            {{ $book->entry?->entry_date?->format('j M Y') }} · {{ $book->entry?->journal }}
                                        </p>
                                    </div>
                                    <button type="button" wire:click="match('{{ $line->id }}', '{{ $book->id }}')"
                                            class="focusable shrink-0 rounded-lg bg-fill-brand px-3.5 py-2 text-[12.5px] font-semibold text-white hover:opacity-90">
                                        Match
                                    </button>
                                </div>
                            @empty
                                <p class="mt-2 text-[13px] leading-relaxed text-muted">
                                    Nothing in the books matches this. It is probably something that has not been
                                    recorded yet — a bank charge, interest, a standing order. Use "Record it".
                                </p>
                            @endforelse
                        </div>
                    @endif

                    @if ($recording === $line->id)
                        <div class="mt-3 rounded-xl border border-brand bg-tint-blue/50 p-4">
                            <p class="text-[13px] leading-relaxed text-muted">
                                Creates the journal entry the business never made, and matches this line to it.
                                Which account should the other side go to?
                            </p>
                            <div class="mt-3 max-w-[280px]">
                                <label class="{{ $labelClass }}" for="r-account-{{ $line->id }}">Account number</label>
                                <input id="r-account-{{ $line->id }}" type="text" wire:model="counterAccount" class="{{ $inputClass }} tnum">
                                @error('counterAccount') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                                <p class="mt-1.5 text-[12.5px] text-faint">631 is bank charges, 771 is interest received.</p>
                            </div>

                            <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                                <button type="button" wire:click="recordIntoBooks"
                                        class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                                    Record and match
                                </button>
                                <button type="button" wire:click="$set('recording', null)"
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
                            ? 'Every line on the statement has been accounted for.'
                            : 'Import a statement to get started.' }}
                    </p>
                </div>
            @endforelse
        </div>
    @endif
</div>
