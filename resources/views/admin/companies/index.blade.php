<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Companies · Platform Admin</title>
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
    <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Companies</h1>

    <form method="GET" class="mt-5 flex flex-wrap gap-2.5">
        <input type="search" name="search" value="{{ $search }}" placeholder="Search by name or email…"
               class="h-11 min-w-[220px] flex-1 rounded-xl border border-border bg-surface px-4 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
        <select name="status" class="h-11 rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink">
            <option value="">All statuses</option>
            @foreach (['demo' => 'Demo', 'trial' => 'Trial', 'active' => 'Active'] as $key => $label)
                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="tap focusable h-11 rounded-xl bg-ink px-5 text-[14px] font-semibold text-white">Search</button>
    </form>

    <div class="mt-5 card overflow-hidden p-0">
        <div class="divide-y divide-border">
            @forelse ($companies as $company)
                <a href="{{ route('admin.companies.show', $company) }}" class="focusable flex items-center justify-between gap-3 px-5 py-4 hover:bg-surface-2">
                    <span class="min-w-0">
                        <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $company->name }}</span>
                        <span class="block text-[12.5px] text-muted">{{ $company->email }} · {{ $company->users_count }} {{ \Illuminate\Support\Str::plural('user', $company->users_count) }} · {{ ucfirst($company->plan) }} plan</span>
                    </span>
                    <span class="shrink-0 text-[12px] font-bold uppercase tracking-wide {{ $company->isSuspended() ? 'text-warning' : 'text-faint' }}">
                        {{ $company->isSuspended() ? 'Suspended' : $company->account_type }}
                    </span>
                </a>
            @empty
                <p class="px-5 py-10 text-center text-[14px] text-muted">No companies match.</p>
            @endforelse
        </div>
    </div>

    <div class="mt-5">{{ $companies->links() }}</div>
</main>

</body>
</html>
