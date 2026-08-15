@php
    use App\Support\Money;

    $isReceivable = $side === 'receivable';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Aging</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $isReceivable ? 'What customers owe you, by how late it is.' : 'What you owe suppliers, by how late it is.' }}
            </p>
        </div>

        @can('reports.export')
            @if ($report['total'] > 0)
                <button type="button" wire:click="export"
                        class="tap focusable flex shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink transition-colors hover:bg-surface-2">
                    <x-icon name="document" class="size-[17px]" stroke-width="2" />
                    <span class="sr-only min-[420px]:not-sr-only">CSV</span>
                </button>
            @endif
        @endcan
    </div>

    {{-- Side switch --}}
    <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Which side">
        @foreach (['receivable' => 'Owed to you', 'payable' => 'You owe'] as $key => $label)
            <button type="button" wire:click="setSide('{{ $key }}')"
                    aria-pressed="{{ $side === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $side === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Bucket summary. Scrolls sideways on a phone rather than squashing five
         columns of money into 360px. --}}
    <div class="card mt-4 overflow-x-auto">
        <table class="w-full min-w-[560px] text-[13.5px]">
            <thead>
                <tr class="border-b border-border">
                    @foreach ($buckets as $key => $label)
                        <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">{{ $label }}</th>
                    @endforeach
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    @foreach ($buckets as $key => $label)
                        @php $value = $report['totals'][$key]; @endphp
                        <td class="px-4 py-3.5 tabular-nums {{ $key === 'over_90' && $value > 0 ? 'font-bold text-negative' : 'font-semibold text-ink' }}">
                            {{ $value > 0 ? Money::format($value, $currency) : '—' }}
                        </td>
                    @endforeach
                    <td class="px-4 py-3.5 text-right font-bold tabular-nums text-ink">{{ Money::format($report['total'], $currency) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Per-party breakdown --}}
    <div class="card mt-4 p-2">
        @forelse ($report['rows'] as $index => $row)
            <div wire:key="p-{{ $row['party_id'] }}" class="{{ $index > 0 ? 'border-t border-border' : '' }}">
                <button type="button" wire:click="toggleParty('{{ $row['party_id'] }}')"
                        aria-expanded="{{ $openParty === $row['party_id'] ? 'true' : 'false' }}"
                        class="focusable flex w-full items-center gap-3.5 rounded-lg px-3 py-3 text-left transition-colors hover:bg-surface-2">

                    @php
                        // Colour by the worst bucket the party is in, not by size.
                        // A large debt that is current is fine; a small one at
                        // 120 days is the one somebody has to act on.
                        $oldest = $row['oldest_days'];
                        [$tint, $ink] = match (true) {
                            $oldest > 90 => ['bg-tint-red', 'text-negative'],
                            $oldest > 30 => ['bg-tint-orange', 'text-warning'],
                            $oldest > 0 => ['bg-tint-orange', 'text-warning'],
                            default => ['bg-tint-green', 'text-positive'],
                        };
                    @endphp

                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ $tint }}">
                        <x-icon name="{{ $oldest > 0 ? 'clock' : 'check-circle' }}" class="size-[19px] {{ $ink }}" stroke-width="1.9" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-semibold text-ink">{{ $row['party'] }}</span>
                        <span class="block truncate text-[13px] text-muted">
                            {{ count($row['items']) }} {{ Str::plural('document', count($row['items'])) }}
                            @if ($oldest > 0)
                                · oldest {{ $oldest }} {{ Str::plural('day', $oldest) }} late
                            @else
                                · nothing overdue
                            @endif
                        </span>
                    </span>

                    <span class="shrink-0 text-right">
                        <span class="block text-[15px] font-bold tabular-nums text-ink">{{ Money::format($row['total'], $currency) }}</span>
                    </span>

                    <x-icon name="chevron-down" class="size-[17px] shrink-0 text-faint transition-transform {{ $openParty === $row['party_id'] ? 'rotate-180' : '' }}" />
                </button>

                @if ($openParty === $row['party_id'])
                    <div class="px-3 pb-3">
                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full min-w-[440px] text-[13px]">
                                <thead>
                                    <tr class="border-b border-border bg-surface-2">
                                        <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Document</th>
                                        <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Due</th>
                                        <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-faint">Age</th>
                                        <th class="px-3 py-2 text-right text-[11px] font-bold uppercase tracking-wide text-faint">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($row['items'] as $i => $item)
                                        <tr class="{{ $i > 0 ? 'border-t border-border' : '' }}">
                                            <td class="px-3 py-2.5 font-semibold text-ink">{{ $item['number'] }}</td>
                                            <td class="px-3 py-2.5 text-muted">{{ $item['due_date']?->format('j M Y') }}</td>
                                            <td class="px-3 py-2.5">
                                                @if ($item['days_overdue'] > 0)
                                                    <span class="rounded-full px-2 py-0.5 text-[11.5px] font-bold
                                                                 {{ $item['days_overdue'] > 90 ? 'bg-tint-red text-negative' : 'bg-tint-orange text-warning' }}">
                                                        {{ $item['days_overdue'] }}d late
                                                    </span>
                                                @else
                                                    <span class="text-[12.5px] text-faint">not due</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-ink">{{ Money::format($item['amount'], $currency) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center px-6 py-14 text-center">
                <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-green">
                    <x-icon name="check-circle" class="size-7 text-positive" />
                </span>
                <p class="mt-4 text-[16px] font-semibold text-ink">
                    {{ $isReceivable ? 'Nobody owes you anything' : 'You owe nothing' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-sm text-[13.5px] leading-relaxed text-muted">
                    {{ $isReceivable
                        ? 'Every issued invoice has been paid in full.'
                        : 'Every recorded bill has been settled.' }}
                </p>
            </div>
        @endforelse
    </div>
</div>
