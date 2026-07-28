@php
    use App\Support\Accent;
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Reports</h1>
            <p class="mt-1 text-[14.5px] text-muted">{{ $from->format('M j') }} – {{ $to->format('M j, Y') }}</p>
        </div>

        <button type="button" wire:click="exportCsv"
                class="tap focusable flex shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14px] font-semibold text-ink-2 hover:bg-surface-2">
            <x-icon name="chart-bar" class="size-[17px] text-muted" />
            Export CSV
        </button>
    </div>

    {{-- Range switcher --}}
    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0">
        @foreach (['week' => 'This Week', 'month' => 'This Month', 'quarter' => 'Quarter', 'year' => 'This Year'] as $key => $label)
            <button type="button" wire:click="setRange('{{ $key }}')"
                    class="focusable h-10 shrink-0 rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $range === $key ? 'bg-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Headline metrics --}}
    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Revenue', Money::format($metrics['revenue'], $currency), 'trending-up', 'blue'],
            ['Collected', Money::format($metrics['collected'], $currency), 'wallet', 'green'],
            ['Outstanding', Money::format($metrics['outstanding'], $currency), 'clock', 'orange'],
            ['Invoices', $metrics['count'].' · avg '.Money::compact($metrics['average'], $currency), 'document', 'purple'],
        ] as [$label, $value, $icon, $accent])
            <div class="card flex items-start gap-3 p-4">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl {{ Accent::solid($accent) }} text-white">
                    <x-icon :name="$icon" class="size-[21px]" />
                </span>
                <div class="min-w-0">
                    <p class="text-[12.5px] font-medium text-muted">{{ $label }}</p>
                    <p class="tnum truncate text-[18px] font-bold tracking-[-0.02em] text-ink">{{ $value }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-ui.panel title="Sales Trend">
            <x-ui.bar-chart :series="$series" height="h-[140px]" />
        </x-ui.panel>

        <x-ui.panel title="Payment Methods">
            @forelse ($methodBreakdown as $row)
                <div class="py-2 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                    <div class="flex items-center justify-between text-[13.5px]">
                        <span class="font-medium text-ink">{{ $row['label'] }}</span>
                        <span class="tnum font-semibold text-ink-2">{{ Money::format($row['amount'], $currency) }} · {{ $row['share'] }}%</span>
                    </div>
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full rounded-full bg-brand" style="width: {{ max(2, $row['share']) }}%"></div>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No payments in this period.</p>
            @endforelse
        </x-ui.panel>
    </div>

    <x-ui.panel title="Top Customers" class="mt-4" body-class="-mx-1.5">
        @forelse ($topCustomers as $row)
            @php $accent = $row['contact']->accent(); @endphp
            <a href="{{ route('customers.show', $row['contact']) }}"
               class="focusable flex items-center gap-3 rounded-lg px-1.5 py-2.5 hover:bg-surface-2 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[12.5px] font-bold {{ Accent::text($accent) }}">
                    {{ $row['contact']->initials() }}
                </span>
                <span class="min-w-0 flex-1 truncate text-[14.5px] font-medium text-ink">{{ $row['contact']->displayName() }}</span>
                <span class="tnum shrink-0 text-[14.5px] font-bold text-brand">{{ Money::format($row['revenue'], $currency) }}</span>
            </a>
        @empty
            <p class="px-1.5 py-6 text-center text-[13.5px] text-muted">No sales in this period.</p>
        @endforelse
    </x-ui.panel>
</div>
