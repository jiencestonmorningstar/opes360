<x-layouts.admin :title="$definition['label']" active="records">
    <div class="px-5 py-8 lg:px-8">

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">{{ $definition['label'] }}</h1>
                <p class="mt-1 text-[14px] text-muted">
                    @if ($company)
                        In <a href="{{ route('admin.companies.show', $company) }}" class="font-semibold text-brand hover:underline">{{ $company->name }}</a>.
                    @else
                        Across every business on the platform.
                    @endif
                    {{ number_format($rows->total()) }} record{{ $rows->total() === 1 ? '' : 's' }}.
                </p>
            </div>

            <a href="{{ route('admin.records.export', array_filter(['resource' => $key, 'company' => $company?->slug, 'q' => $search ?: null])) }}"
               class="focusable flex h-11 items-center rounded-xl bg-surface-2 px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                Export CSV
            </a>
        </div>

        {{-- Resource switcher. Every tenant-owned table is reachable from every
             other one, so an admin chasing a support question is never more
             than one click from the table that answers it. --}}
        <nav class="mt-5 flex flex-wrap gap-1.5" aria-label="Record types">
            @foreach ($resources as $resourceKey => $resource)
                <a href="{{ route('admin.records', array_filter(['resource' => $resourceKey, 'company' => $company?->slug])) }}"
                   @if ($resourceKey === $key) aria-current="page" @endif
                   class="focusable rounded-lg px-3 py-1.5 text-[13px] font-semibold transition-colors
                          {{ $resourceKey === $key ? 'bg-brand text-white' : 'bg-surface-2 text-ink-2 hover:bg-tint-blue hover:text-brand' }}">
                    {{ $resource['label'] }}
                </a>
            @endforeach
        </nav>

        @if ($definition['search'] ?? null)
            <form method="GET" class="mt-4 flex gap-2.5">
                @if ($company)<input type="hidden" name="company" value="{{ $company->slug }}">@endif
                <label for="q" class="sr-only">Search {{ strtolower($definition['label']) }}</label>
                <input id="q" type="search" name="q" value="{{ $search }}"
                       placeholder="Search {{ strtolower($definition['label']) }}…"
                       class="h-11 w-full max-w-sm rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                <button type="submit" class="focusable h-11 rounded-xl bg-brand px-4 text-[13.5px] font-semibold text-white hover:opacity-90">Search</button>
                @if ($search)
                    <a href="{{ route('admin.records', array_filter(['resource' => $key, 'company' => $company?->slug])) }}"
                       class="focusable flex h-11 items-center px-2 text-[13px] font-semibold text-muted hover:text-ink-2">Clear</a>
                @endif
            </form>
        @endif

        <div class="card mt-5 overflow-hidden p-0">
            {{-- Wide tables scroll in their own container rather than pushing
                 the page sideways. --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left">
                    <thead>
                        <tr class="border-b border-border bg-surface-2">
                            @if (! $company)
                                <th class="px-4 py-3 text-[12px] font-bold uppercase tracking-wide text-muted">Business</th>
                            @endif
                            @foreach (array_keys($definition['columns']) as $heading)
                                <th class="px-4 py-3 text-[12px] font-bold uppercase tracking-wide text-muted">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($rows as $row)
                            <tr class="{{ $row->deleted_at ?? null ? 'opacity-60' : '' }}">
                                @if (! $company)
                                    <td class="whitespace-nowrap px-4 py-3 text-[13px] text-muted">
                                        {{ $row->company?->name ?? '—' }}
                                    </td>
                                @endif
                                @foreach ($definition['columns'] as $render)
                                    <td class="px-4 py-3 text-[13.5px] text-ink-2">
                                        {{ $render($row) ?: '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($definition['columns']) + (! $company ? 1 : 0) }}" class="px-4 py-12 text-center">
                                    <p class="text-[14px] font-semibold text-ink">Nothing here</p>
                                    <p class="mt-1 text-[13px] text-muted">
                                        {{ $search ? 'No records match that search.' : 'No records of this kind yet.' }}
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($rows->hasPages())
            <div class="mt-5">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.admin>
