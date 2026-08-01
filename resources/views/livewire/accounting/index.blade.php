@php
    use App\Support\Money;

    $currency = $company?->currency ?? 'XAF';
    $amount = fn ($value) => Money::format((float) $value, $currency, false);
    $inputClass = 'h-11 w-full rounded-lg border border-border bg-surface px-3 text-[14px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $th = 'px-4 py-3 text-[12px] font-bold uppercase tracking-wide text-muted';
    $td = 'px-4 py-2.5 text-[13.5px] text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Accounting</h1>
            <p class="mt-1 text-[14.5px] text-muted">Your SYSCOHADA books, kept as you invoice and take payment.</p>
        </div>
        @can('accounting.export')
            <button type="button" wire:click="exportBalance"
                    class="focusable flex h-11 items-center rounded-lg bg-surface-2 px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                Export balance
            </button>
        @endcan
    </div>

    @if (session('accountingStatus'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
            {{ session('accountingStatus') }}
        </div>
    @endif

    {{-- No chart means the business has not started keeping books. Offering the
         starter chart here is the shortest path from "nothing" to "something an
         accountant can work with". --}}
    @if ($accounts->isEmpty())
        <div class="card mt-5 p-6 text-center">
            <p class="text-[16px] font-bold text-ink">No chart of accounts yet</p>
            <p class="mx-auto mt-1.5 max-w-md text-[13.5px] text-muted">
                Add the standard SYSCOHADA accounts to start keeping books. Your accountant can
                rename them and add their own subdivisions afterwards.
            </p>
            <button type="button" wire:click="seedChart"
                    class="focusable mx-auto mt-5 flex h-12 items-center justify-center rounded-xl bg-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                Set up the chart
            </button>
        </div>
    @else

        {{-- Period. One picker for every tab, because a date range that lives in
             five places is a date range that disagrees with itself. --}}
        <div class="card mt-5 flex flex-wrap items-end gap-3 p-4">
            <label class="min-w-[140px] flex-1">
                <span class="mb-1 block text-[12px] font-semibold uppercase tracking-wide text-faint">From</span>
                <input type="date" wire:model.live="from" class="{{ $inputClass }}">
            </label>
            <label class="min-w-[140px] flex-1">
                <span class="mb-1 block text-[12px] font-semibold uppercase tracking-wide text-faint">To</span>
                <input type="date" wire:model.live="to" class="{{ $inputClass }}">
            </label>
        </div>

        <nav class="mt-5 flex flex-wrap gap-1.5" aria-label="Statements">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @if ($tab === $key) aria-current="page" @endif
                        class="focusable rounded-lg px-3.5 py-2 text-[13px] font-semibold transition-colors
                               {{ $tab === $key ? 'bg-brand text-white' : 'bg-surface-2 text-ink-2 hover:bg-tint-blue hover:text-brand' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        {{-- Balance générale --}}
        @if ($tab === 'balance')
            <div class="card mt-5 overflow-hidden p-0">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left">
                        <thead>
                            <tr class="border-b border-border bg-surface-2">
                                <th class="{{ $th }}">Compte</th>
                                <th class="{{ $th }}">Libellé</th>
                                <th class="{{ $th }} text-right">Débit</th>
                                <th class="{{ $th }} text-right">Crédit</th>
                                <th class="{{ $th }} text-right">Solde</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($balance['rows'] as $row)
                                <tr>
                                    <td class="{{ $td }} tnum font-semibold text-ink">{{ $row['account']->number }}</td>
                                    <td class="{{ $td }}">{{ $row['account']->name }}</td>
                                    <td class="{{ $td }} tnum text-right">{{ $amount($row['debit']) }}</td>
                                    <td class="{{ $td }} tnum text-right">{{ $amount($row['credit']) }}</td>
                                    <td class="{{ $td }} tnum text-right font-semibold text-ink">{{ $amount($row['balance']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-12 text-center text-[13.5px] text-muted">No movement in this period.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($balance['rows']->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-ink bg-surface-2">
                                    <td class="{{ $td }} font-bold text-ink" colspan="2">TOTAUX</td>
                                    <td class="{{ $td }} tnum text-right font-bold text-ink">{{ $amount($balance['total_debit']) }}</td>
                                    <td class="{{ $td }} tnum text-right font-bold text-ink">{{ $amount($balance['total_credit']) }}</td>
                                    <td class="{{ $td }}"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            {{-- If this ever shows, an unbalanced entry reached the books and
                 every statement built on them is wrong. Say so loudly. --}}
            @unless ($balance['balanced'])
                <p class="mt-3 rounded-xl bg-tint-orange px-4 py-2.5 text-[13px] font-semibold text-warning">
                    The books do not balance — debits and credits differ. Contact support before filing anything from this.
                </p>
            @endunless
        @endif

        {{-- Grand livre --}}
        @if ($tab === 'ledger')
            <div class="mt-5">
                <label class="block max-w-sm">
                    <span class="mb-1 block text-[12px] font-semibold uppercase tracking-wide text-faint">Account</span>
                    <select wire:model.live="account" class="{{ $inputClass }}">
                        @foreach ($accounts as $option)
                            <option value="{{ $option->id }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if ($ledger)
                <div class="card mt-4 overflow-hidden p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px] text-left">
                            <thead>
                                <tr class="border-b border-border bg-surface-2">
                                    <th class="{{ $th }}">Date</th>
                                    <th class="{{ $th }}">Journal</th>
                                    <th class="{{ $th }}">Libellé</th>
                                    <th class="{{ $th }} text-right">Débit</th>
                                    <th class="{{ $th }} text-right">Crédit</th>
                                    <th class="{{ $th }} text-right">Solde</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr class="bg-surface-2">
                                    <td class="{{ $td }} font-semibold text-ink" colspan="5">Solde d'ouverture</td>
                                    <td class="{{ $td }} tnum text-right font-semibold text-ink">{{ $amount($ledger['opening']) }}</td>
                                </tr>
                                @forelse ($ledger['lines'] as $row)
                                    <tr>
                                        <td class="{{ $td }} whitespace-nowrap">{{ $row['entry']->entry_date?->format('d M Y') }}</td>
                                        <td class="{{ $td }}">{{ $row['entry']->journal }}</td>
                                        <td class="{{ $td }}">{{ $row['line']->narration ?: $row['entry']->narration }}</td>
                                        <td class="{{ $td }} tnum text-right">{{ $row['debit'] ? $amount($row['debit']) : '—' }}</td>
                                        <td class="{{ $td }} tnum text-right">{{ $row['credit'] ? $amount($row['credit']) : '—' }}</td>
                                        <td class="{{ $td }} tnum text-right font-semibold text-ink">{{ $amount($row['balance']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-10 text-center text-[13.5px] text-muted">No movement on this account in the period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif

        {{-- Journal --}}
        @if ($tab === 'journal')
            <div class="mt-5">
                <label class="block max-w-xs">
                    <span class="mb-1 block text-[12px] font-semibold uppercase tracking-wide text-faint">Journal</span>
                    <select wire:model.live="journal" class="{{ $inputClass }}">
                        <option value="">All journals</option>
                        @foreach ($journals as $code => $name)
                            <option value="{{ $code }}">{{ $code }} — {{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($entries as $entry)
                    <div class="card p-4" wire:key="entry-{{ $entry->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-[14px] font-bold text-ink">
                                {{ $entry->journal }} · {{ $entry->reference ?: 'Sans référence' }}
                            </p>
                            <p class="text-[12.5px] text-muted">{{ $entry->entry_date?->format('d M Y') }}</p>
                        </div>
                        @if ($entry->narration)
                            <p class="mt-0.5 text-[13px] text-muted">{{ $entry->narration }}</p>
                        @endif
                        <div class="mt-3 space-y-1">
                            @foreach ($entry->lines as $line)
                                <div class="flex items-baseline justify-between gap-3 text-[13px]">
                                    <span class="min-w-0 flex-1 truncate text-ink-2">
                                        <span class="tnum font-semibold text-ink">{{ $line->account->number }}</span>
                                        {{ $line->account->name }}
                                    </span>
                                    <span class="tnum w-28 text-right {{ (float) $line->debit ? 'text-ink' : 'text-faint' }}">
                                        {{ (float) $line->debit ? $amount($line->debit) : '—' }}
                                    </span>
                                    <span class="tnum w-28 text-right {{ (float) $line->credit ? 'text-ink' : 'text-faint' }}">
                                        {{ (float) $line->credit ? $amount($line->credit) : '—' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="card p-10 text-center">
                        <p class="text-[14px] font-semibold text-ink">Nothing posted in this period</p>
                        <p class="mt-1 text-[13px] text-muted">Entries appear here as you issue documents and take payments.</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- Compte de résultat --}}
        @if ($tab === 'income')
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                @foreach ([['Produits', $income['produits'], $income['total_produits']], ['Charges', $income['charges'], $income['total_charges']]] as [$heading, $rows, $total])
                    <x-ui.panel :title="$heading" body-class="-mx-1.5">
                        @forelse ($rows as $row)
                            <div class="flex items-baseline justify-between gap-3 px-1.5 py-2 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                                <span class="min-w-0 flex-1 truncate text-[13.5px] text-ink-2">
                                    <span class="tnum font-semibold text-ink">{{ $row['account']->number }}</span>
                                    {{ $row['account']->name }}
                                </span>
                                <span class="tnum text-[14px] font-semibold text-ink">{{ $amount($row['balance']) }}</span>
                            </div>
                        @empty
                            <p class="px-1.5 py-6 text-center text-[13px] text-muted">None in this period.</p>
                        @endforelse
                        <div class="mt-2 flex items-baseline justify-between gap-3 border-t-2 border-ink px-1.5 pt-2.5">
                            <span class="text-[13.5px] font-bold text-ink">Total</span>
                            <span class="tnum text-[15px] font-bold text-ink">{{ $amount($total) }}</span>
                        </div>
                    </x-ui.panel>
                @endforeach
            </div>

            <div class="card mt-4 flex items-center justify-between p-5">
                <div>
                    <p class="text-[15px] font-bold text-ink">Résultat de la période</p>
                    <p class="mt-0.5 text-[12.5px] text-muted">Produits moins charges.</p>
                </div>
                <p class="tnum text-[22px] font-bold {{ $income['resultat'] >= 0 ? 'text-positive' : 'text-negative' }}">
                    {{ $amount($income['resultat']) }}
                </p>
            </div>
        @endif

        {{-- Bilan --}}
        @if ($tab === 'sheet')
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                @foreach ([['Actif', $sheet['actif'], $sheet['total_actif'], false], ['Passif', $sheet['passif'], $sheet['total_passif'], true]] as [$heading, $rows, $total, $withResult])
                    <x-ui.panel :title="$heading" body-class="-mx-1.5">
                        @forelse ($rows as $row)
                            <div class="flex items-baseline justify-between gap-3 px-1.5 py-2 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                                <span class="min-w-0 flex-1 truncate text-[13.5px] text-ink-2">
                                    <span class="tnum font-semibold text-ink">{{ $row['account']->number }}</span>
                                    {{ $row['account']->name }}
                                </span>
                                <span class="tnum text-[14px] font-semibold text-ink">{{ $amount($row['balance']) }}</span>
                            </div>
                        @empty
                            <p class="px-1.5 py-6 text-center text-[13px] text-muted">Nothing on this side yet.</p>
                        @endforelse

                        @if ($withResult)
                            <div class="flex items-baseline justify-between gap-3 border-t border-border px-1.5 py-2">
                                <span class="text-[13.5px] text-ink-2">Résultat de la période</span>
                                <span class="tnum text-[14px] font-semibold text-ink">{{ $amount($sheet['resultat']) }}</span>
                            </div>
                        @endif

                        <div class="mt-2 flex items-baseline justify-between gap-3 border-t-2 border-ink px-1.5 pt-2.5">
                            <span class="text-[13.5px] font-bold text-ink">Total</span>
                            <span class="tnum text-[15px] font-bold text-ink">{{ $amount($total) }}</span>
                        </div>
                    </x-ui.panel>
                @endforeach
            </div>

            <p class="mt-4 text-[12.5px] text-muted">
                Grouped by SYSCOHADA class. This is a summary of your position, not the official
                filing form — that has a fixed line structure your accountant will map these figures onto.
            </p>
        @endif

        {{-- Plan comptable --}}
        @if ($tab === 'chart')
            @can('accounting.manage')
                <x-ui.panel title="Add an account" class="mt-5">
                    <div class="grid gap-3 min-[640px]:grid-cols-[130px_1fr_170px_auto]">
                        <label>
                            <span class="mb-1 block text-[12px] font-semibold uppercase tracking-wide text-faint">Number</span>
                            <input type="text" inputmode="numeric" wire:model="newNumber" placeholder="4111"
                                   class="{{ $inputClass }} tnum">
                        </label>
                        <label>
                            <span class="mb-1 block text-[12px] font-semibold uppercase tracking-wide text-faint">Name</span>
                            <input type="text" wire:model="newName" placeholder="Clients — boutique"
                                   class="{{ $inputClass }}">
                        </label>
                        <label>
                            <span class="mb-1 block text-[12px] font-semibold uppercase tracking-wide text-faint">Increases by</span>
                            <select wire:model="newSide" class="{{ $inputClass }}">
                                <option value="">Automatic</option>
                                <option value="debit">Debit</option>
                                <option value="credit">Credit</option>
                            </select>
                        </label>
                        <div class="flex items-end">
                            <button type="button" wire:click="addAccount"
                                    class="focusable h-11 rounded-lg bg-brand px-4 text-[13.5px] font-semibold text-white hover:opacity-90">
                                Add
                            </button>
                        </div>
                    </div>
                    @error('newNumber') <p class="mt-2 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    @error('newName') <p class="mt-2 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    <p class="mt-2 text-[12px] text-muted">
                        The side is derived from the parent account or the class — override it only when you
                        know better, as with 4191 avances reçues.
                    </p>
                </x-ui.panel>

                @if ($suggested->isNotEmpty())
                    <div class="mt-4">
                        <p class="text-[12.5px] font-semibold text-ink-2">Subdivisions the plan lists, one click to add:</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($suggested as $number => $name)
                                <button type="button" wire:click="addSuggested('{{ $number }}')" wire:key="sugg-{{ $number }}"
                                        class="focusable rounded-lg bg-surface-2 px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                                    <span class="tnum">{{ $number }}</span> {{ $name }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan

            <div class="card mt-5 overflow-hidden p-0">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left">
                        <thead>
                            <tr class="border-b border-border bg-surface-2">
                                <th class="{{ $th }}">Compte</th>
                                <th class="{{ $th }}">Libellé</th>
                                <th class="{{ $th }}">Classe</th>
                                <th class="{{ $th }}">Sens</th>
                                <th class="{{ $th }} text-right">Solde</th>
                                @can('accounting.manage')<th class="{{ $th }}"></th>@endcan
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($chartRows as $row)
                                <tr wire:key="acct-{{ $row['account']->id }}" class="{{ $row['account']->is_active ? '' : 'opacity-50' }}">
                                    <td class="{{ $td }} tnum font-semibold text-ink">{{ $row['account']->number }}</td>
                                    <td class="{{ $td }}">
                                        @if ($editingId === $row['account']->id)
                                            <span class="flex items-center gap-2">
                                                <input type="text" wire:model="editingName" wire:keydown.enter="saveRename"
                                                       class="h-9 w-full max-w-xs rounded-lg border border-border bg-surface px-2.5 text-[13.5px] text-ink focus:border-brand focus:outline-none">
                                                <button type="button" wire:click="saveRename" class="focusable text-[12.5px] font-semibold text-brand hover:underline">Save</button>
                                                <button type="button" wire:click="cancelRename" class="focusable text-[12.5px] font-semibold text-muted hover:underline">Cancel</button>
                                            </span>
                                            @error('editingName') <p class="mt-1 text-[12px] font-medium text-warning">{{ $message }}</p> @enderror
                                        @else
                                            {{ $row['account']->name }}
                                            @unless ($row['account']->is_active)
                                                <span class="ml-1.5 rounded bg-surface-2 px-1.5 py-0.5 text-[11px] font-semibold text-muted">inactive</span>
                                            @endunless
                                        @endif
                                    </td>
                                    <td class="{{ $td }}">{{ $row['account']->class }}</td>
                                    <td class="{{ $td }}">{{ $row['account']->isDebitNormal() ? 'Débit' : 'Crédit' }}</td>
                                    <td class="{{ $td }} tnum text-right {{ $row['hasMovement'] ? 'font-semibold text-ink' : 'text-faint' }}">
                                        {{ $row['hasMovement'] ? $amount($row['balance']) : '—' }}
                                    </td>
                                    @can('accounting.manage')
                                        <td class="{{ $td }} whitespace-nowrap text-right">
                                            <button type="button" wire:click="startRename('{{ $row['account']->id }}')"
                                                    class="focusable text-[12.5px] font-semibold text-brand hover:underline">Rename</button>
                                            <button type="button" wire:click="toggleActive('{{ $row['account']->id }}')"
                                                    class="focusable ml-2.5 text-[12.5px] font-semibold text-muted hover:underline">
                                                {{ $row['account']->is_active ? 'Deactivate' : 'Reactivate' }}
                                            </button>
                                            @unless ($row['hasMovement'])
                                                <button type="button" wire:click="deleteAccount('{{ $row['account']->id }}')"
                                                        wire:confirm="Remove account {{ $row['account']->number }} from the chart?"
                                                        class="focusable ml-2.5 text-[12.5px] font-semibold text-negative hover:underline">Delete</button>
                                            @endunless
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-4 text-[12.5px] text-muted">
                An account with entries can be renamed or deactivated but never deleted — the books
                reference it, and a balance that cites a vanished account explains nothing.
            </p>
        @endif
    @endif
</div>
