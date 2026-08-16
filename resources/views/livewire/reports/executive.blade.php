@php
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    @if ($company === null)
        <div class="flex flex-col items-center px-6 py-14 text-center">
            <p class="text-[16px] font-semibold text-ink">No company selected</p>
        </div>
    @else

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Executive</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $from->format('j M') }} – {{ $to->format('j M Y') }}, against the period before.
            </p>
        </div>
    </div>

    {{-- Range switch --}}
    <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Period">
        @foreach (['week' => 'This week', 'month' => 'This month', 'quarter' => 'This quarter', 'year' => 'This year'] as $key => $label)
            <button type="button" wire:click="setRange('{{ $key }}')"
                    aria-pressed="{{ $range === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $range === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- KPI cards: the period's flows with prior-period deltas --}}
    @php
        $cards = [
            ['key' => 'revenue', 'label' => 'Revenue', 'money' => true],
            ['key' => 'collected', 'label' => 'Collected', 'money' => true],
            ['key' => 'expenses', 'label' => 'Expenses', 'money' => true, 'inverse' => true],
            ['key' => 'net_result', 'label' => 'Net result', 'money' => true],
            ['key' => 'invoices', 'label' => 'Invoices issued', 'money' => false],
            ['key' => 'average_days_to_payment', 'label' => 'Days to payment', 'money' => false, 'inverse' => true],
        ];
    @endphp

    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
        @foreach ($cards as $card)
            @php
                $value = $kpis['period'][$card['key']];
                $delta = $kpis['deltas'][$card['key']] ?? null;
                // For expenses and days-to-payment, down is good.
                $good = $delta !== null && (($card['inverse'] ?? false) ? $delta < 0 : $delta > 0);
            @endphp
            <div class="card p-4">
                <p class="text-[11.5px] font-bold uppercase tracking-wide text-faint">{{ $card['label'] }}</p>
                <p class="mt-1.5 text-[20px] font-bold tabular-nums text-ink">
                    @if ($value === null)
                        —
                    @elseif ($card['money'])
                        {{ Money::format($value, $currency) }}
                    @else
                        {{ $value }}
                    @endif
                </p>
                @if ($delta !== null)
                    <p class="mt-1 text-[12.5px] font-semibold {{ $good ? 'text-positive' : 'text-negative' }}">
                        {{ $delta > 0 ? '+' : '' }}{{ $delta }}% <span class="font-normal text-muted">vs prior period</span>
                    </p>
                @else
                    <p class="mt-1 text-[12.5px] text-faint">No prior figure</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Balances as of right now — these never reset with the period --}}
    <div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-[11.5px] font-bold uppercase tracking-wide text-faint">Cash position</p>
            <p class="mt-1.5 text-[18px] font-bold tabular-nums {{ $kpis['now']['cash_position'] < 0 ? 'text-negative' : 'text-ink' }}">
                {{ Money::format($kpis['now']['cash_position'], $currency) }}
            </p>
        </div>
        <div class="card p-4">
            <p class="text-[11.5px] font-bold uppercase tracking-wide text-faint">Receivables</p>
            <p class="mt-1.5 text-[18px] font-bold tabular-nums text-ink">{{ Money::format($kpis['now']['receivables_outstanding'], $currency) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[11.5px] font-bold uppercase tracking-wide text-faint">Of which overdue</p>
            <p class="mt-1.5 text-[18px] font-bold tabular-nums {{ $kpis['now']['receivables_overdue'] > 0 ? 'text-warning' : 'text-ink' }}">
                {{ Money::format($kpis['now']['receivables_overdue'], $currency) }}
            </p>
        </div>
        <div class="card p-4">
            <p class="text-[11.5px] font-bold uppercase tracking-wide text-faint">Payables due soon</p>
            <p class="mt-1.5 text-[18px] font-bold tabular-nums text-ink">{{ Money::format($kpis['now']['payables_due_soon'], $currency) }}</p>
        </div>
    </div>

    {{-- Alarms: what needs eyes today, each linking to its own screen --}}
    <h2 class="mt-7 text-[17px] font-bold text-ink">Needs eyes today</h2>
    <div class="card mt-3 p-2">
        @foreach ($alarms as $i => $alarm)
            <a href="{{ $alarm['route'] }}" wire:navigate
               class="focusable flex items-center gap-3.5 rounded-lg px-3 py-3 transition-colors hover:bg-surface-2 {{ $i > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full {{ $alarm['alarming'] ? 'bg-tint-red' : 'bg-tint-green' }}">
                    <x-icon name="{{ $alarm['alarming'] ? 'alert' : 'check-circle' }}"
                            class="size-[19px] {{ $alarm['alarming'] ? 'text-negative' : 'text-positive' }}" stroke-width="1.9" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink">{{ $alarm['label'] }}</span>
                    <span class="block truncate text-[13px] text-muted">{{ $alarm['caption'] }}</span>
                </span>
                <span class="shrink-0 text-[15px] font-bold tabular-nums {{ $alarm['alarming'] ? 'text-negative' : 'text-ink' }}">
                    {{ $alarm['money'] ? Money::format($alarm['value'], $currency) : $alarm['value'] }}
                </span>
                <x-icon name="chevron-down" class="size-[17px] shrink-0 -rotate-90 text-faint" />
            </a>
        @endforeach
    </div>

    {{-- Revenue by customer --}}
    <h2 class="mt-7 text-[17px] font-bold text-ink">Top customers this period</h2>
    <div class="card mt-3 overflow-x-auto">
        <table class="w-full min-w-[520px] text-[13.5px]">
            <thead>
                <tr class="border-b border-border">
                    <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Customer</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Invoices</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Revenue</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $i => $row)
                    <tr class="{{ $i > 0 ? 'border-t border-border' : '' }}">
                        <td class="px-4 py-3 font-semibold text-ink">{{ $row['contact']->displayName() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-muted">{{ $row['invoices'] }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-ink">{{ Money::format($row['revenue'], $currency) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-warning' : 'text-muted' }}">
                            {{ $row['outstanding'] > 0 ? Money::format($row['outstanding'], $currency) : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-[13.5px] text-muted">No invoices in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Project budget consumption --}}
    @if ($projects->isNotEmpty())
        <h2 class="mt-7 text-[17px] font-bold text-ink">Project budgets</h2>
        <div class="card mt-3 overflow-x-auto">
            <table class="w-full min-w-[520px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Project</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Budget</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Cost to date</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Consumed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projects as $i => $row)
                        <tr class="{{ $i > 0 ? 'border-t border-border' : '' }}">
                            <td class="px-4 py-3">
                                <span class="block font-semibold text-ink">{{ $row['project']->name }}</span>
                                @if ($row['project']->contact)
                                    <span class="block text-[12.5px] text-muted">{{ $row['project']->contact->displayName() }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-muted">{{ Money::format($row['budget'], $currency) }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-ink">{{ Money::format($row['cost'], $currency) }}</td>
                            <td class="px-4 py-3 text-right font-bold tabular-nums
                                       {{ ($row['consumed_share'] ?? 0) > 100 ? 'text-negative' : (($row['consumed_share'] ?? 0) > 80 ? 'text-warning' : 'text-ink') }}">
                                {{ $row['consumed_share'] !== null ? $row['consumed_share'].'%' : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @endif
</div>
