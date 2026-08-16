@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $tab === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Compliance calendar</h1>
            <p class="mt-1 text-[14.5px] text-muted">What the business owes, by when, and the proof it was done.</p>
        </div>

        @can('risks.view')
            <a href="{{ route('risks') }}" wire:navigate
               class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                Risks
            </a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    {{-- Overdue first and on its own, because it is the only figure here that
         means somebody has to do something today. --}}
    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-4">
        <div class="card p-4 {{ $summary['overdue'] > 0 ? 'border-rose-300/70 dark:border-rose-500/30' : '' }}">
            <p class="text-[12.5px] font-medium text-muted">Overdue</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] {{ $summary['overdue'] > 0 ? 'text-rose-600' : 'text-ink' }}">
                {{ $summary['overdue'] }}
            </p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Due soon</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $summary['due_soon'] }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Being prepared</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $summary['in_progress'] }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">On the calendar</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $summary['obligations'] }}</p>
        </div>
    </div>

    @error('filing')
        <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
    @enderror

    <div class="mt-5 flex gap-1 border-b border-border pb-3">
        <button type="button" wire:click="$set('tab', 'due')" class="{{ $tabClass('due') }}">What is due</button>
        <button type="button" wire:click="$set('tab', 'register')" class="{{ $tabClass('register') }}">Obligations</button>
        <button type="button" wire:click="$set('tab', 'filed')" class="{{ $tabClass('filed') }}">Filed</button>
    </div>

    {{-- ────────────────────────────────────────────────── what is due ── --}}
    @if ($tab === 'due')

        <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Overdue</p>
        <div class="mt-2 overflow-hidden rounded-2xl border {{ $overdue->isNotEmpty() ? 'border-rose-300/70 dark:border-rose-500/30' : 'border-border' }}">
            <table class="w-full text-left text-[14.5px]">
                <tbody>
                    @forelse ($overdue as $obligation)
                        <tr class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $obligation->name }}
                                <span class="block text-[13px] font-normal text-muted">
                                    {{ $obligation->categoryLabel() }}@if ($obligation->authority) · {{ $obligation->authority }} @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 font-semibold text-rose-600">
                                {{ $obligation->next_due_on->toFormattedDateString() }}
                                <span class="block text-[13px] font-normal">
                                    {{ $obligation->next_due_on->diffInDays(now()) }} days late
                                </span>
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $obligation->owner?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('compliance.file')
                                    <button type="button" wire:click="startFiling('{{ $obligation->id }}')"
                                            class="tap focusable rounded-full bg-fill-brand px-3.5 py-1.5 text-[13.5px] font-semibold text-white">
                                        File it
                                    </button>
                                @endcan
                            </td>
                        </tr>
                        @if ($filingObligation === $obligation->id)
                            <tr class="border-b border-border bg-fill-2 last:border-b-0">
                                <td colspan="4" class="px-4 py-4">
                                    @include('livewire.compliance.filing-form', ['obligation' => $obligation])
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td class="px-4 py-8 text-center text-muted">Nothing has been missed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Due soon</p>
        <div class="mt-2 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <tbody>
                    @forelse ($dueSoon as $obligation)
                        <tr class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $obligation->name }}
                                <span class="block text-[13px] font-normal text-muted">{{ $obligation->categoryLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $obligation->next_due_on->toFormattedDateString() }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $obligation->owner?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('compliance.file')
                                    <button type="button" wire:click="startFiling('{{ $obligation->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        File it
                                    </button>
                                @endcan
                            </td>
                        </tr>
                        @if ($filingObligation === $obligation->id)
                            <tr class="border-b border-border bg-fill-2 last:border-b-0">
                                <td colspan="4" class="px-4 py-4">
                                    @include('livewire.compliance.filing-form', ['obligation' => $obligation])
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td class="px-4 py-8 text-center text-muted">Nothing coming up.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($inProgress->isNotEmpty())
            <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Being prepared</p>
            <ul class="mt-2 space-y-1.5 text-[14px] text-ink-2">
                @foreach ($inProgress as $open)
                    <li>
                        {{ $open->obligation?->name ?? '—' }} —
                        due {{ $open->due_on->toFormattedDateString() }}
                        <span class="text-muted">({{ $open->statusLabel() }})</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    {{-- ────────────────────────────────────────────────── obligations ── --}}
    @if ($tab === 'register')
        @can('compliance.manage')
            <div class="mt-5">
                @if (! $adding)
                    <button type="button" wire:click="startAdding"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Add an obligation
                    </button>
                @else
                    <div class="rounded-2xl border border-border p-4">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label class="{{ $labelClass }}">What is it</label>
                                <input type="text" wire:model="name" placeholder="TVA declaration" class="{{ $inputClass }}">
                                @error('name') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Kind</label>
                                <select wire:model="category" class="{{ $inputClass }}">
                                    @foreach ($categories as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Who demands it</label>
                                <input type="text" wire:model="authority" placeholder="DGI, CNPS, the bank…" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Next one due</label>
                                <input type="date" wire:model="nextDueOn" class="{{ $inputClass }}">
                                @error('nextDueOn') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Repeats every</label>
                                <input type="number" wire:model="intervalMonths" placeholder="months — blank for a one-off" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Warn me</label>
                                <input type="number" wire:model="leadDays" class="{{ $inputClass }}">
                                <p class="mt-1 text-[12.5px] text-muted">Days before the deadline.</p>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Whose job it is</label>
                                <select wire:model="obligationOwnerId" class="{{ $inputClass }}">
                                    <option value="">Nobody in particular</option>
                                    @foreach ($people as $person)
                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- The one setting on this form that is genuinely hard, and
                             invisible for a whole cycle when it is wrong. Both options
                             are spelled out in the words a business would use. --}}
                        <fieldset class="mt-4 rounded-xl border border-border p-4">
                            <legend class="px-1 text-[13px] font-semibold text-ink-2">When is the one after that due?</legend>

                            <label class="flex items-start gap-3 text-[14px] text-ink-2">
                                <input type="radio" value="due" wire:model="scheduleBasis" class="mt-1">
                                <span>
                                    <span class="font-semibold text-ink">A fixed date the authority sets.</span>
                                    Filing late does not buy more time — the quarter after next is still the quarter
                                    after next. Choose this for tax returns and declarations.
                                </span>
                            </label>

                            <label class="mt-3 flex items-start gap-3 text-[14px] text-ink-2">
                                <input type="radio" value="completion" wire:model="scheduleBasis" class="mt-1">
                                <span>
                                    <span class="font-semibold text-ink">Counted from the day it is done.</span>
                                    A licence renewed in March runs a year from March, whatever month it was meant to be
                                    renewed in. Choose this for renewals, policies and certificates.
                                </span>
                            </label>
                        </fieldset>

                        <label class="mt-4 flex items-center gap-2 text-[14px] text-ink-2">
                            <input type="checkbox" wire:model="requiresApproval">
                            Somebody has to sign this off before it counts as filed
                        </label>

                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="addObligation"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Add it
                            </button>
                            <button type="button" wire:click="cancel"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @endcan

        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Obligation</th>
                        <th class="px-4 py-3">Next due</th>
                        <th class="px-4 py-3">Cadence</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($obligations as $obligation)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $obligation->name }}
                                <span class="block text-[13px] font-normal text-muted">
                                    {{ $obligation->categoryLabel() }}@if (! $obligation->is_active) · no longer active @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 {{ $obligation->isOverdue() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                                {{ $obligation->next_due_on?->toFormattedDateString() ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-ink-2">
                                @if ($obligation->repeats())
                                    Every {{ $obligation->interval_months }} months
                                    <span class="block text-[13px] text-muted">{{ $bases[$obligation->schedule_basis] ?? '' }}</span>
                                @else
                                    One-off
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="toggleHistory('{{ $obligation->id }}')"
                                        class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                    History
                                </button>
                            </td>
                        </tr>

                        @if ($showingHistory === $obligation->id)
                            <tr class="border-t border-border bg-fill-2">
                                <td colspan="4" class="px-4 py-4">
                                    @if ($obligation->filings->isEmpty())
                                        <p class="text-[14px] text-muted">Never filed through here.</p>
                                    @else
                                        <ul class="space-y-1.5 text-[13.5px] text-ink-2">
                                            @foreach ($obligation->filings as $past)
                                                <li>
                                                    Due {{ $past->due_on->toFormattedDateString() }} —
                                                    {{ $past->statusLabel() }}
                                                    @if ($past->completed_on)
                                                        on {{ $past->completed_on->toFormattedDateString() }}
                                                    @endif
                                                    @if ($past->reference)
                                                        <span class="text-muted">· {{ $past->reference }}</span>
                                                    @endif
                                                    @if ($past->wasLate())
                                                        <span class="font-semibold text-rose-600">· late</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-muted">
                                Nothing on the calendar yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ──────────────────────────────────────────────────────── filed ── --}}
    @if ($tab === 'filed')
        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Obligation</th>
                        <th class="px-4 py-3">Period</th>
                        <th class="px-4 py-3">Due</th>
                        <th class="px-4 py-3">Filed</th>
                        <th class="px-4 py-3">Reference</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentlyFiled as $done)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">{{ $done->obligation?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $done->period_label ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $done->due_on->toFormattedDateString() }}</td>
                            <td class="px-4 py-3 {{ $done->wasLate() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                                {{ $done->completed_on?->toFormattedDateString() ?? '—' }}
                                @if ($done->wasLate()) <span class="block text-[13px]">late</span> @endif
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $done->reference ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-muted">Nothing filed yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
