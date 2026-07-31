<x-layouts.admin title="Dashboard" active="dashboard">
    <div class="px-5 py-8 lg:px-8">
        <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Platform overview</h1>
        <p class="mt-1 text-[14px] text-muted">Every business on {{ config('opes.brand.name') }}, at a glance.</p>

        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                ['label' => 'Total businesses', 'value' => $stats['total'], 'icon' => 'briefcase', 'tint' => 'bg-tint-blue', 'text' => 'text-accent-blue'],
                ['label' => 'Demo', 'value' => $stats['demo'], 'icon' => 'spark', 'tint' => 'bg-tint-purple', 'text' => 'text-accent-purple'],
                ['label' => 'Trial', 'value' => $stats['trial'], 'icon' => 'clock', 'tint' => 'bg-tint-orange', 'text' => 'text-warning'],
                ['label' => 'Active', 'value' => $stats['active'], 'icon' => 'check-circle', 'tint' => 'bg-tint-green', 'text' => 'text-positive'],
                ['label' => 'Suspended', 'value' => $stats['suspended'], 'icon' => 'alert', 'tint' => 'bg-tint-orange', 'text' => 'text-warning'],
                ['label' => 'Est. MRR', 'value' => \App\Support\Money::format($stats['mrr'], 'XAF', false), 'icon' => 'banknotes', 'tint' => 'bg-tint-blue', 'text' => 'text-accent-blue'],
            ] as $tile)
                <div class="card p-4">
                    <span class="flex size-9 items-center justify-center rounded-lg {{ $tile['tint'] }}">
                        <x-icon :name="$tile['icon']" class="size-[18px] {{ $tile['text'] }}" stroke-width="1.9" />
                    </span>
                    <p class="mt-3 text-[12px] font-medium text-muted">{{ $tile['label'] }}</p>
                    <p class="tnum mt-0.5 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $tile['value'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-6 card p-5">
            <p class="text-[15px] font-bold text-ink">New businesses per week</p>
            <p class="mt-0.5 text-[12.5px] text-muted">Last 12 weeks, including demo and trial signups.</p>
            <x-ui.bar-chart
                class="mt-5"
                :series="collect($weeklySignups)->map(fn ($week, $i) => [
                    'label' => $i % 3 === 0 ? $week['label'] : '',
                    'value' => $week['count'],
                    'highlight' => $i === count($weeklySignups) - 1,
                ])->all()" />
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-[1.3fr_1fr]">
            <div class="card overflow-hidden p-0">
                <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
                    <p class="text-[15px] font-bold text-ink">Recently signed up</p>
                    <a href="{{ route('admin.companies') }}" class="text-[13px] font-semibold text-brand hover:underline">View all</a>
                </div>
                <div class="divide-y divide-border">
                    @forelse ($recentCompanies as $company)
                        <a href="{{ route('admin.companies.show', $company) }}" class="focusable flex items-center gap-3 px-5 py-3.5 hover:bg-surface-2">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-surface-2 text-[13px] font-semibold text-ink-2">
                                {{ strtoupper(substr($company->name, 0, 2)) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-semibold text-ink">{{ $company->name }}</span>
                                <span class="block text-[12.5px] text-muted">{{ $company->email }} · {{ $company->created_at->format('M j, Y') }}</span>
                            </span>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide {{ $company->isSuspended() ? 'bg-tint-orange text-warning' : 'bg-surface-2 text-faint' }}">
                                {{ $company->isSuspended() ? 'Suspended' : $company->account_type }}
                            </span>
                        </a>
                    @empty
                        <p class="px-5 py-10 text-center text-[14px] text-muted">No businesses yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="card p-5">
                <p class="text-[15px] font-bold text-ink">Active businesses by plan</p>
                <p class="mt-0.5 text-[12.5px] text-muted">Demo and trial accounts aren't plan-gated, so only active ones count here.</p>
                <div class="mt-5 space-y-4">
                    @foreach ($plans as $plan)
                        @php $count = $byPlan[$plan]; $max = max(1, max($byPlan)); @endphp
                        <div>
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-[13.5px] font-semibold capitalize text-ink-2">{{ $plan }}</span>
                                <span class="tnum text-[13px] font-semibold text-muted">{{ $count }}</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-2">
                                <div class="h-full rounded-full bg-ink transition-all" style="width: {{ round($count / $max * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-layouts.admin>
