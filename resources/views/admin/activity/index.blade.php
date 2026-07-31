<x-layouts.admin title="Activity" active="activity">
    <div class="px-5 py-8 lg:px-8">
        <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Admin activity</h1>
        <p class="mt-1 text-[14px] text-muted">Every write any platform admin has made, across every business.</p>

        <form method="GET" class="mt-5 flex flex-wrap gap-2.5">
            <label for="admin" class="sr-only">Filter by admin</label>
            <select id="admin" name="admin" class="h-11 rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink" onchange="this.form.submit()">
                <option value="">All admins</option>
                @foreach ($admins as $adminOption)
                    <option value="{{ $adminOption->id }}" @selected($selectedAdmin == $adminOption->id)>{{ $adminOption->name }}</option>
                @endforeach
            </select>
            <label for="action" class="sr-only">Filter by action</label>
            <select id="action" name="action" class="h-11 rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink" onchange="this.form.submit()">
                <option value="">All actions</option>
                @foreach ($actions as $actionOption)
                    <option value="{{ $actionOption }}" @selected($selectedAction === $actionOption)>{{ str_replace('_', ' ', $actionOption) }}</option>
                @endforeach
            </select>
            @if ($selectedAdmin || $selectedAction)
                <a href="{{ route('admin.activity') }}" class="focusable flex h-11 items-center px-2 text-[13px] font-semibold text-muted hover:text-ink-2">Clear</a>
            @endif
        </form>

        <div class="mt-5 card overflow-hidden p-0">
            <div class="divide-y divide-border">
                @forelse ($activity as $entry)
                    @php
                        $company = $entry->subject_type === \App\Models\Company::class
                            ? $companies->get($entry->subject_id)
                            : null;
                    @endphp
                    <div class="px-5 py-3.5">
                        <p class="text-[13.5px] text-ink-2">
                            <span class="font-semibold text-ink">{{ $entry->admin?->name ?? 'Unknown admin' }}</span>
                            {{ str_replace('_', ' ', $entry->action) }}
                            @if ($company)
                                <a href="{{ route('admin.companies.show', $company) }}" class="font-semibold text-brand hover:underline">{{ $company->name }}</a>
                            @endif
                            @if (! empty($entry->meta['user_email']))
                                <span class="text-muted">({{ $entry->meta['user_email'] }})</span>
                            @elseif (! empty($entry->meta['email']))
                                <span class="font-semibold text-ink">{{ $entry->meta['email'] }}</span>
                            @endif
                        </p>
                        <p class="mt-0.5 text-[12px] text-faint">
                            {{ $entry->created_at->format('M j, Y g:ia') }}
                            @if ($entry->ip_address)
                                · {{ $entry->ip_address }}
                            @endif
                        </p>
                        @if (! empty($entry->meta['reason']))
                            <p class="mt-1 text-[12.5px] text-muted">"{{ $entry->meta['reason'] }}"</p>
                        @endif
                    </div>
                @empty
                    <div class="px-5 py-14 text-center">
                        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-surface-2">
                            <x-icon name="clock" class="size-[22px] text-faint" stroke-width="1.8" />
                        </span>
                        <p class="mt-3 text-[14px] text-muted">Nothing logged yet.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="mt-5">{{ $activity->links() }}</div>
    </div>
</x-layouts.admin>
