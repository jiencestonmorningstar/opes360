@php
    use App\Support\CollectionsQueue;
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Collections</h1>
            <p class="mt-1 text-[14.5px] text-muted">Who to chase, in the order worth chasing them.</p>
        </div>

        @can('reports.export')
            @if ($summary['accounts'] > 0)
                <button type="button" wire:click="export"
                        class="tap focusable flex shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink transition-colors hover:bg-surface-2">
                    <x-icon name="document" class="size-[17px]" stroke-width="2" />
                    <span class="sr-only min-[420px]:not-sr-only">CSV</span>
                </button>
            @endif
        @endcan
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            'Accounts' => $summary['accounts'],
            'Overdue' => Money::format($summary['overdue_total'], $currency),
            'Net exposure' => Money::format($summary['net_exposure'], $currency),
            'Broken promises' => $summary['broken_promises'],
        ] as $label => $value)
            <div class="card px-4 py-3">
                <span class="block text-[11.5px] font-bold uppercase tracking-wide text-faint">{{ $label }}</span>
                <span class="mt-0.5 block text-[17px] font-bold tabular-nums text-ink">{{ $value }}</span>
            </div>
        @endforeach
    </div>

    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Filter">
        @foreach (['all' => 'Everyone', 'broken' => 'Broken promises', 'untouched' => 'Never contacted', 'over_90' => 'Over 90 days'] as $key => $label)
            <button type="button" wire:click="setFilter('{{ $key }}')"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2">
        @forelse ($rows as $index => $row)
            <div wire:key="c-{{ $row['party_id'] }}" class="{{ $index > 0 ? 'border-t border-border' : '' }}">
                <button type="button" wire:click="toggleParty('{{ $row['party_id'] }}')"
                        aria-expanded="{{ $openParty === $row['party_id'] ? 'true' : 'false' }}"
                        class="focusable flex w-full items-center gap-3.5 rounded-lg px-3 py-3 text-left transition-colors hover:bg-surface-2">

                    @php
                        [$tint, $ink, $icon] = match ($row['flag']) {
                            CollectionsQueue::FLAG_BROKEN_PROMISE => ['bg-tint-red', 'text-negative', 'alert'],
                            CollectionsQueue::FLAG_PROMISED => ['bg-tint-green', 'text-positive', 'check-circle'],
                            default => $row['oldest_days'] > 90
                                ? ['bg-tint-red', 'text-negative', 'clock']
                                : ['bg-tint-orange', 'text-warning', 'clock'],
                        };
                    @endphp

                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ $tint }}">
                        <x-icon name="{{ $icon }}" class="size-[19px] {{ $ink }}" stroke-width="1.9" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-semibold text-ink">{{ $row['party'] }}</span>
                        <span class="block truncate text-[13px] text-muted">
                            oldest {{ $row['oldest_days'] }} {{ Str::plural('day', $row['oldest_days']) }} late
                            @if ($row['flag'] === CollectionsQueue::FLAG_BROKEN_PROMISE)
                                · <span class="font-semibold text-negative">promised {{ $row['promised_at']->toFormattedDateString() }}</span>
                            @elseif ($row['flag'] === CollectionsQueue::FLAG_PROMISED)
                                · <span class="font-semibold text-positive">paying {{ $row['promised_at']->toFormattedDateString() }}</span>
                            @elseif ($row['days_since_contact'] === null)
                                · never contacted
                            @else
                                · last spoken {{ $row['days_since_contact'] }}d ago
                            @endif
                        </span>
                    </span>

                    <span class="shrink-0 text-right">
                        <span class="block text-[15px] font-bold tabular-nums text-ink">{{ Money::format($row['overdue_total'], $currency) }}</span>
                        @if ($row['credit_available'] > 0)
                            <span class="block text-[12px] tabular-nums text-positive">less {{ Money::format($row['credit_available'], $currency) }} credit</span>
                        @endif
                    </span>

                    <x-icon name="chevron-down" class="size-[17px] shrink-0 text-faint transition-transform {{ $openParty === $row['party_id'] ? 'rotate-180' : '' }}" />
                </button>

                @if ($openParty === $row['party_id'])
                    <div class="space-y-3 px-3 pb-3">
                        <div class="flex flex-wrap items-center gap-3 text-[13px] text-muted">
                            @if ($row['email']) <span>{{ $row['email'] }}</span> @endif
                            @if ($row['phone']) <span>{{ $row['phone'] }}</span> @endif
                            @if ($row['last_reminder_at'])
                                <span>Reminder at {{ $row['last_reminder_step'] }} days sent {{ $row['last_reminder_at']->toFormattedDateString() }}</span>
                            @endif
                        </div>

                        @if ($row['last_activity'])
                            <div class="rounded-xl border border-border px-3 py-2.5 text-[13px]">
                                <span class="font-semibold text-ink">{{ $row['last_activity']->label() }}</span>
                                <span class="text-faint">· {{ $row['last_activity']->happened_at->toFormattedDateString() }}</span>
                                <p class="mt-0.5 text-ink-2">{{ $row['last_activity']->body }}</p>
                            </div>
                        @endif

                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full min-w-[440px] text-[13px]">
                                <thead>
                                    <tr class="border-b border-border bg-surface-2">
                                        <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Document</th>
                                        <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Due</th>
                                        <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Age</th>
                                        <th class="px-3 py-2 text-right text-[11px] font-bold uppercase tracking-wide text-faint">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($row['documents'] as $item)
                                        <tr class="border-b border-border last:border-0">
                                            <td class="px-3 py-2 font-medium text-ink">{{ $item['number'] }}</td>
                                            <td class="px-3 py-2 text-muted">{{ $item['due_date']?->toDateString() }}</td>
                                            <td class="px-3 py-2 {{ $item['days_overdue'] > 90 ? 'font-semibold text-negative' : 'text-muted' }}">
                                                {{ $item['days_overdue'] > 0 ? $item['days_overdue'].'d late' : 'not due' }}
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums text-ink">{{ Money::format($item['balance'], $currency) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($logFor === $row['party_id'])
                            <div class="space-y-3 rounded-xl border border-border p-3">
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <label class="block">
                                        <span class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-faint">What happened</span>
                                        <select wire:model.live="kind" class="focusable h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                            @foreach ($kinds as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    @if ($kind === \App\Models\CollectionActivity::KIND_PROMISE)
                                        <label class="block">
                                            <span class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-faint">Promised for</span>
                                            <input type="date" wire:model="promisedAt" class="focusable h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink" />
                                            @error('promisedAt') <span class="mt-1 block text-[12.5px] text-negative">{{ $message }}</span> @enderror
                                        </label>

                                        <label class="block">
                                            <span class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-faint">Amount</span>
                                            <input type="number" step="0.01" wire:model="promisedAmount" class="focusable h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink" />
                                        </label>
                                    @endif
                                </div>

                                <label class="block">
                                    <span class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-faint">Note</span>
                                    <textarea wire:model="body" rows="2" class="focusable w-full rounded-xl border border-border bg-surface px-3 py-2 text-[14.5px] text-ink"></textarea>
                                    @error('body') <span class="mt-1 block text-[12.5px] text-negative">{{ $message }}</span> @enderror
                                </label>

                                <div class="flex gap-2">
                                    <button type="button" wire:click="saveLog"
                                            class="tap focusable rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white">Save</button>
                                    <button type="button" wire:click="closeLog"
                                            class="tap focusable rounded-full border border-border px-5 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                                </div>
                            </div>
                        @else
                            <button type="button" wire:click="openLog('{{ $row['party_id'] }}')"
                                    class="tap focusable rounded-full border border-border px-5 text-[14.5px] font-semibold text-ink transition-colors hover:bg-surface-2">
                                Log a call or a promise
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="px-5 py-12 text-center">
                <p class="text-[14.5px] text-muted">Nothing overdue. Nobody to chase.</p>
            </div>
        @endforelse
    </div>
</div>
