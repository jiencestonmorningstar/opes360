@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $link = fn ($contract) => route('contracts.show', $contract);

    $money = fn ($amount, $ccy) => $amount === null ? '—' : number_format((float) $amount).' '.$ccy;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Contracts</h1>
            <p class="mt-1 text-[14.5px] text-muted">What you are bound by, and the dates that decide whether it carries on.</p>
        </div>

        @can('contracts.manage')
            <button type="button" wire:click="startRaising"
                    class="tap focusable shrink-0 rounded-full bg-fill-brand px-5 py-2.5 text-[14.5px] font-semibold text-white">
                Raise a contract
            </button>
        @endcan
    </div>

    {{-- ──────────────────────────────────────────────────────── the alarm ── --}}
    {{--
        Not a row in a table. Every contract here is committing the business to
        another term and another year of spend that no approval, budget or
        purchase order will ever be asked about — the one failure this whole
        module exists to stop, so it is allowed to shout.
    --}}
    @if ($renewingAtRisk->isNotEmpty())
        <div class="mt-5 rounded-2xl border-2 border-rose-400 bg-rose-50 p-5 dark:border-rose-500/50 dark:bg-rose-500/10">
            <p class="text-[16px] font-bold text-rose-800 dark:text-rose-200">
                {{ $renewingAtRisk->count() }}
                {{ Str::plural('contract', $renewingAtRisk->count()) }}
                {{ $renewingAtRisk->count() === 1 ? 'has' : 'have' }} renewed themselves, or are about to
            </p>
            <p class="mt-1 text-[14px] text-rose-700 dark:text-rose-300">
                The last day to serve notice has gone and {{ $renewingAtRisk->count() === 1 ? 'it renews' : 'they renew' }}
                automatically. Nobody has to do anything for this to cost money.
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($renewingAtRisk as $contract)
                    <li wire:key="risk-{{ $contract->id }}"
                        class="rounded-xl bg-white/70 px-4 py-3 dark:bg-black/20">
                        <p class="text-[15px] font-semibold text-rose-900 dark:text-rose-100">
                            @if ($link($contract))
                                <a href="{{ $link($contract) }}" wire:navigate class="underline decoration-rose-300 underline-offset-2">{{ $contract->title }}</a>
                            @else
                                {{ $contract->title }}
                            @endif
                        </p>
                        <p class="mt-0.5 text-[13.5px] text-rose-700 dark:text-rose-300">
                            {{ $contract->counterparty?->displayName() ?? 'No counterparty' }} ·
                            notice was due {{ $contract->notice_by->toFormattedDateString() }},
                            {{ abs($contract->daysToNotice()) }} {{ Str::plural('day', abs($contract->daysToNotice())) }} ago ·
                            runs to {{ $contract->ends_on?->toFormattedDateString() ?? 'no end date' }}
                            @if ($contract->renewal_term_months)
                                , then another {{ $contract->renewal_term_months }} months
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ────────────────────────────────────────────── the two other lists ── --}}
    <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <x-ui.panel title="Notice deadline coming">
            <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                You can still act on these. The last day to serve notice is inside the next month.
            </p>

            @forelse ($lapsing as $contract)
                <div wire:key="lapse-{{ $contract->id }}"
                     class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">
                        @if ($link($contract))
                            <a href="{{ $link($contract) }}" wire:navigate class="hover:underline">{{ $contract->title }}</a>
                        @else
                            {{ $contract->title }}
                        @endif
                    </p>
                    <p class="mt-0.5 text-[13px] text-warning">
                        @php $days = $contract->daysToNotice(); @endphp
                        {{ $days === 0 ? 'Notice is due today' : $days.' '.Str::plural('day', $days).' left to give notice' }}
                        <span class="text-muted">· {{ $contract->counterparty?->displayName() ?? 'No counterparty' }}</span>
                    </p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">Nothing needs a decision this month.</p>
            @endforelse
        </x-ui.panel>

        <x-ui.panel title="Notice deadline gone">
            <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                Too late to serve notice, but these end by themselves. Worth knowing, not worth panicking about.
            </p>

            @forelse ($missed as $contract)
                <div wire:key="missed-{{ $contract->id }}"
                     class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">
                        @if ($link($contract))
                            <a href="{{ $link($contract) }}" wire:navigate class="hover:underline">{{ $contract->title }}</a>
                        @else
                            {{ $contract->title }}
                        @endif
                    </p>
                    <p class="mt-0.5 text-[13px] text-muted">
                        Notice was due {{ $contract->notice_by->toFormattedDateString() }} ·
                        ends {{ $contract->ends_on?->toFormattedDateString() ?? 'no end date' }}
                    </p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No deadlines have been missed.</p>
            @endforelse
        </x-ui.panel>
    </div>

    @if ($summary['expiring'] > 0 || $summary['overdue_obligations'] > 0)
        <p class="mt-4 text-[13.5px] text-muted">
            @if ($summary['expiring'] > 0)
                {{ $summary['expiring'] }} {{ Str::plural('contract', $summary['expiring']) }} ending within two months.
            @endif
            @if ($summary['overdue_obligations'] > 0)
                {{ $summary['overdue_obligations'] }} {{ Str::plural('promise', $summary['overdue_obligations']) }} past their date.
            @endif
        </p>
    @endif

    {{-- ───────────────────────────────────────────────────── raise a new ── --}}
    @if ($raising)
        <div class="mt-5 rounded-2xl border border-border p-4">
            @error('raising')
                <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
            @enderror

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">What is it</label>
                    <input type="text" wire:model="title" placeholder="Office cleaning" class="{{ $inputClass }}">
                    @error('title') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">With</label>
                    <select wire:model="contactId" class="{{ $inputClass }}">
                        <option value="">Nobody yet</option>
                        @foreach ($counterparties as $party)
                            <option value="{{ $party->id }}">{{ $party->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Which way round</label>
                    <select wire:model="direction" class="{{ $inputClass }}">
                        <option value="inbound">We buy under it</option>
                        <option value="outbound">We sell under it</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Type</label>
                    <select wire:model="type" class="{{ $inputClass }}">
                        @foreach (\App\Models\Contract::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Value</label>
                    <input type="number" step="0.01" wire:model="value" placeholder="{{ $currency }}" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Starts</label>
                    <input type="date" wire:model="startsOn" class="{{ $inputClass }}">
                    @error('startsOn') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Ends</label>
                    <input type="date" wire:model="endsOn" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Renewal</label>
                    <select wire:model.live="renewalType" class="{{ $inputClass }}">
                        @foreach (\App\Models\Contract::RENEWAL_TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Renews for</label>
                    <input type="number" wire:model="renewalTermMonths" placeholder="months" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Notice period</label>
                    <input type="number" wire:model="noticePeriodDays" placeholder="days" class="{{ $inputClass }}">
                    @if ($renewalType === 'auto')
                        <p class="mt-1 text-[13px] text-warning">
                            Required — without it nobody can be warned before this renews itself.
                        </p>
                    @endif
                </div>
            </div>

            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="raise"
                        class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                    Raise it
                </button>
                <button type="button" wire:click="$set('raising', false)"
                        class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ────────────────────────────────────────────────────── the register ── --}}
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <div class="min-w-56 flex-1">
            <label for="contract-search" class="sr-only">Search contracts</label>
            <input id="contract-search" wire:model.live.debounce.300ms="search" type="search"
                   placeholder="Search contracts…"
                   class="focusable h-11 w-full rounded-full border border-border bg-surface px-4 text-[14.5px] text-ink">
        </div>

        <div class="no-scrollbar flex gap-2 overflow-x-auto">
            @foreach (['' => 'All', 'draft' => 'Draft', 'active' => 'Active', 'expired' => 'Expired', 'terminated' => 'Terminated'] as $value => $label)
                <button type="button" wire:click="$set('status', '{{ $value }}')"
                        class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                               {{ $status === $value ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @error('register')
        <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
    @enderror

    <div class="mt-4 overflow-hidden rounded-2xl border border-border">
        <table class="w-full text-left text-[14.5px]">
            <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                <tr>
                    <th class="px-4 py-3">Contract</th>
                    <th class="px-4 py-3">With</th>
                    <th class="px-4 py-3">Value</th>
                    <th class="px-4 py-3">Ends</th>
                    <th class="px-4 py-3">Notice by</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contracts as $contract)
                    @php $state = $contract->state(); @endphp
                    <tr wire:key="row-{{ $contract->id }}" class="border-t border-border">
                        <td class="px-4 py-3 font-semibold text-ink">
                            @if ($link($contract))
                                <a href="{{ $link($contract) }}" wire:navigate class="hover:underline">{{ $contract->title }}</a>
                            @else
                                {{ $contract->title }}
                            @endif
                            <span class="mt-0.5 block text-[13px] font-normal text-muted">
                                {{ $contract->typeLabel() }} · {{ $contract->renewalLabel() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-ink-2">{{ $contract->counterparty?->displayName() ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $money($contract->value, $contract->currency ?? $currency) }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $contract->ends_on?->toFormattedDateString() ?? 'Open-ended' }}</td>
                        <td class="px-4 py-3 {{ $contract->noticeMissed() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                            {{ $contract->notice_by?->toFormattedDateString() ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />

                            @can('contracts.manage')
                                @if ($contract->status === 'draft')
                                    <button type="button" wire:click="submit('{{ $contract->id }}')"
                                            class="tap focusable ml-2 rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        Submit for approval
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-muted">
                            @if ($search !== '' || $status !== '')
                                Nothing matches.
                            @else
                                No contracts on the register yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($contracts->hasPages())
        <div class="mt-4">{{ $contracts->links() }}</div>
    @endif
</div>
