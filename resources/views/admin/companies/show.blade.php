<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $company->name }} · Platform Admin</title>
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

<main class="mx-auto max-w-4xl px-5 py-8">
    <a href="{{ route('admin.companies') }}" class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        All companies
    </a>

    @if (session('status'))
        <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    <div class="mt-4 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">{{ $company->name }}</h1>
            <p class="mt-1 text-[13.5px] text-muted">{{ $company->email }} · created {{ $company->created_at->format('M j, Y') }}</p>
        </div>
        <span class="shrink-0 rounded-full px-3 py-1.5 text-[12px] font-bold uppercase tracking-wide {{ $company->isSuspended() ? 'bg-tint-orange text-warning' : 'bg-tint-green text-positive' }}">
            {{ $company->isSuspended() ? 'Suspended' : $company->account_type }}
        </span>
    </div>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
        <div class="card p-5">
            <p class="text-[15px] font-bold text-ink">Plan</p>
            <form method="POST" action="{{ route('admin.companies.plan', $company) }}" class="mt-3 flex gap-2">
                @csrf
                <select name="plan" class="h-11 flex-1 rounded-lg border border-border bg-surface px-3 text-[14px] text-ink">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan }}" @selected($company->plan === $plan)>{{ ucfirst($plan) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="tap focusable h-11 shrink-0 rounded-lg bg-ink px-4 text-[13.5px] font-semibold text-white">Save</button>
            </form>
            <p class="mt-2 text-[12px] text-faint">Only enforced while the account is Active — demo and trial always see every module.</p>
        </div>

        <div class="card p-5">
            <p class="text-[15px] font-bold text-ink">Access</p>
            <form method="POST" action="{{ route($company->isSuspended() ? 'admin.companies.activate' : 'admin.companies.suspend', $company) }}" class="mt-3">
                @csrf
                <button type="submit"
                        class="tap focusable flex h-11 w-full items-center justify-center rounded-lg text-[13.5px] font-semibold {{ $company->isSuspended() ? 'bg-brand text-white' : 'border border-warning/40 bg-tint-orange text-warning' }}">
                    {{ $company->isSuspended() ? 'Reactivate this business' : 'Suspend this business' }}
                </button>
            </form>
            <p class="mt-2 text-[12px] text-faint">Suspending locks every user out immediately; nothing is deleted.</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-3 gap-4">
        @foreach ([
            ['Users', $company->users_count],
            ['Documents issued', $documentCount],
            ['Currency', $company->currency],
        ] as [$label, $value])
            <div class="card p-4">
                <p class="text-[12px] font-medium text-muted">{{ $label }}</p>
                <p class="tnum mt-1 text-[18px] font-bold text-ink">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 card overflow-hidden p-0">
        <p class="border-b border-border px-5 py-3.5 text-[15px] font-bold text-ink">Members</p>
        <div class="divide-y divide-border">
            @foreach ($members as $member)
                <div class="flex items-center justify-between gap-3 px-5 py-3">
                    <span class="min-w-0">
                        <span class="block truncate text-[14px] font-semibold text-ink">{{ $member->name }}</span>
                        <span class="block text-[12.5px] text-muted">{{ $member->email }} · {{ $member->pivot->job_title ?? '—' }}</span>
                    </span>
                    <span class="shrink-0 text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $member->pivot->status }}</span>
                </div>
            @endforeach
        </div>
    </div>

    @if ($recentActivity->isNotEmpty())
        <div class="mt-6 card overflow-hidden p-0">
            <p class="border-b border-border px-5 py-3.5 text-[15px] font-bold text-ink">Admin activity on this account</p>
            <div class="divide-y divide-border">
                @foreach ($recentActivity as $entry)
                    <div class="px-5 py-3 text-[13.5px] text-ink-2">
                        <span class="font-semibold">{{ $entry->admin?->name ?? 'Unknown admin' }}</span>
                        {{ str_replace('_', ' ', $entry->action) }}
                        <span class="text-faint">· {{ $entry->created_at->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</main>

</body>
</html>
