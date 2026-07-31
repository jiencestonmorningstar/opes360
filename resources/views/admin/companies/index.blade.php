<x-layouts.admin title="Companies" active="companies">
    <div class="px-5 py-8 lg:px-8">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Companies</h1>
                <p class="mt-1 text-[14px] text-muted">{{ $companies->total() }} {{ \Illuminate\Support\Str::plural('business', $companies->total()) }} on the platform.</p>
            </div>
            <a href="{{ route('admin.companies.export', request()->query()) }}"
               class="tap focusable flex h-10 shrink-0 items-center gap-2 rounded-lg border border-border px-3.5 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                <x-icon name="document" class="size-[16px]" stroke-width="1.8" />
                Export CSV
            </a>
        </div>

        <form method="GET" class="mt-5 flex flex-wrap gap-2.5">
            <div class="relative min-w-[220px] flex-1">
                <label for="search" class="sr-only">Search by name or email</label>
                <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-[18px] -translate-y-1/2 text-faint" stroke-width="2" />
                <input id="search" type="search" name="search" value="{{ $search }}" placeholder="Search by name or email…"
                       class="h-11 w-full rounded-xl border border-border bg-surface pl-10 pr-4 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
            </div>
            <label for="status" class="sr-only">Filter by status</label>
            <select id="status" name="status" class="h-11 rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink">
                <option value="">All statuses</option>
                @foreach (['demo' => 'Demo', 'trial' => 'Trial', 'active' => 'Active', 'deleted' => 'Deleted'] as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="sort" class="sr-only">Sort by</label>
            <select id="sort" name="sort" class="h-11 rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink">
                @foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => 'Name', 'plan' => 'Plan'] as $key => $label)
                    <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="tap focusable h-11 rounded-xl bg-ink px-5 text-[14px] font-semibold text-white">Search</button>
        </form>

        <div class="mt-5 card overflow-hidden p-0">
            <div class="divide-y divide-border">
                @forelse ($companies as $company)
                    <a href="{{ route('admin.companies.show', $company) }}" class="focusable flex items-center gap-3.5 px-5 py-4 hover:bg-surface-2">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-2 text-[13.5px] font-semibold text-ink-2">
                            {{ strtoupper(substr($company->name, 0, 2)) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $company->name }}</span>
                            <span class="block text-[12.5px] text-muted">{{ $company->email }} · {{ $company->users_count }} {{ \Illuminate\Support\Str::plural('user', $company->users_count) }}</span>
                        </span>
                        <span class="hidden shrink-0 rounded-full bg-surface-2 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-ink-2 sm:inline-block">
                            {{ $company->plan }}
                        </span>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide {{ $company->trashed() ? 'bg-surface-2 text-faint' : ($company->isSuspended() ? 'bg-tint-orange text-warning' : 'bg-surface-2 text-faint') }}">
                            {{ $company->trashed() ? 'Deleted' : ($company->isSuspended() ? 'Suspended' : $company->account_type) }}
                        </span>
                        <x-icon name="chevron-right" class="hidden size-[18px] shrink-0 text-faint sm:block" stroke-width="2.2" />
                    </a>
                @empty
                    <div class="px-5 py-14 text-center">
                        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-surface-2">
                            <x-icon name="search" class="size-[22px] text-faint" stroke-width="1.8" />
                        </span>
                        <p class="mt-3 text-[14px] text-muted">No companies match.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="mt-5">{{ $companies->links() }}</div>
    </div>
</x-layouts.admin>
