<x-layouts.admin title="Activity" active="activity">
    <div class="px-5 py-8 lg:px-8">
        <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Admin activity</h1>
        <p class="mt-1 text-[14px] text-muted">Every write any platform admin has made, across every business.</p>

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
