<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Dashboard · Platform Admin</title>
    <meta name="robots" content="noindex">

    <script @cspNonce>
        (function () {
            try {
                var stored = localStorage.getItem('opes-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && system));
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full">

@include('admin.partials.nav')

<main class="mx-auto max-w-6xl px-5 py-8">
    <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Platform overview</h1>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['Total businesses', $stats['total']],
            ['Demo', $stats['demo']],
            ['Trial', $stats['trial']],
            ['Active', $stats['active']],
            ['Suspended', $stats['suspended']],
            ['Est. MRR', \App\Support\Money::format($stats['mrr'], 'XAF', false)],
        ] as [$label, $value])
            <div class="card p-4">
                <p class="text-[12px] font-medium text-muted">{{ $label }}</p>
                <p class="tnum mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-8 card p-5">
        <p class="text-[15px] font-bold text-ink">Active businesses by plan</p>
        <div class="mt-4 space-y-3">
            @foreach ($plans as $plan)
                @php $count = $byPlan[$plan]; $max = max(1, max($byPlan)); @endphp
                <div>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-[13.5px] font-semibold capitalize text-ink-2">{{ $plan }}</span>
                        <span class="tnum text-[13px] font-semibold text-muted">{{ $count }}</span>
                    </div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full rounded-full bg-brand" style="width: {{ round($count / $max * 100) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mt-6 card overflow-hidden p-0">
        <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
            <p class="text-[15px] font-bold text-ink">Recently signed up</p>
            <a href="{{ route('admin.companies') }}" class="text-[13px] font-semibold text-brand hover:underline">View all</a>
        </div>
        <div class="divide-y divide-border">
            @foreach ($recentCompanies as $company)
                <a href="{{ route('admin.companies.show', $company) }}" class="focusable flex items-center justify-between gap-3 px-5 py-3.5 hover:bg-surface-2">
                    <span class="min-w-0">
                        <span class="block truncate text-[14px] font-semibold text-ink">{{ $company->name }}</span>
                        <span class="block text-[12.5px] text-muted">{{ $company->email }} · {{ $company->created_at->format('M j, Y') }}</span>
                    </span>
                    <span class="shrink-0 text-[12px] font-bold uppercase tracking-wide {{ $company->isSuspended() ? 'text-warning' : 'text-faint' }}">
                        {{ $company->isSuspended() ? 'Suspended' : $company->account_type }}
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</main>

</body>
</html>
