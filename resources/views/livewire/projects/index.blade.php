<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Projects</h1>
            <p class="mt-1 text-[14.5px] text-muted">Chargeable and internal work, with time and cost against a budget.</p>
        </div>

        @can('create', \App\Models\Project::class)
            <button type="button" wire:click="$toggle('creating')"
                    class="focusable flex h-11 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14px] font-semibold text-white hover:opacity-90">
                <x-icon name="plus" class="size-[16px]" />
                New project
            </button>
        @endcan
    </div>

    @if ($creating)
        <div class="mt-4">
            <x-ui.panel title="New project">
                <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="p-name" class="text-[13px] font-semibold text-ink-2">Name</label>
                        <input id="p-name" wire:model="name" type="text"
                               class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                        @error('name') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="p-code" class="text-[13px] font-semibold text-ink-2">Code <span class="font-normal text-muted">optional</span></label>
                        <input id="p-code" wire:model="code" type="text"
                               class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                    </div>

                    <div>
                        <label for="p-budget" class="text-[13px] font-semibold text-ink-2">Budget <span class="font-normal text-muted">optional</span></label>
                        <input id="p-budget" wire:model="budget" type="number" step="0.01" min="0"
                               class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                    </div>

                    <div class="sm:col-span-2 flex items-center gap-2">
                        <input id="p-billable" wire:model="isBillable" type="checkbox" class="focusable rounded border-border">
                        <label for="p-billable" class="text-[13.5px] text-ink-2">Billable to a client</label>
                    </div>

                    <div class="sm:col-span-2 flex gap-2">
                        <button type="submit" class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            Create project
                        </button>
                        <button type="button" wire:click="$set('creating', false)"
                                class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            Cancel
                        </button>
                    </div>
                </form>
            </x-ui.panel>
        </div>
    @endif

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <div class="min-w-56 flex-1">
            <label for="proj-search" class="sr-only">Search projects</label>
            <input id="proj-search" wire:model.live.debounce.300ms="search" type="search"
                   placeholder="Search by name or code…"
                   class="focusable h-11 w-full rounded-full border border-border bg-surface px-4 text-[14.5px] text-ink">
        </div>

        <div class="no-scrollbar flex gap-2 overflow-x-auto">
            @foreach (['' => 'All'] + \App\Models\Project::STATUSES as $value => $label)
                <button type="button" wire:click="$set('status', '{{ $value }}')"
                        class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                               {{ $status === $value ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-5">
        <x-ui.panel>
            @forelse ($projects as $project)
                @php $state = $project->state(); @endphp

                <div wire:key="proj-{{ $project->id }}"
                   class="{{ $loop->first ? '' : 'mt-3' }} rounded-xl bg-surface-2 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[14.5px] font-semibold text-ink">
                                {{ $project->name }}
                                @if ($project->code) <span class="text-muted">· {{ $project->code }}</span> @endif
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                {{ $project->contact?->name ?? 'No client — internal' }}
                                @if ($project->manager) · {{ $project->manager->firstName() }} @endif
                                @unless ($project->is_billable) · Not billable @endunless
                            </p>
                        </div>

                        <x-ui.status-badge class="shrink-0" :label="$state['label']" :tone="$state['tone']" />
                    </div>

                    @if ($project->budget)
                        <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-surface-3">
                            @php
                                $spent = $project->costToDate();
                                $pct = min(100, $project->budget > 0 ? ($spent / $project->budget) * 100 : 0);
                            @endphp
                            <div class="h-full rounded-full {{ $pct >= 100 ? 'bg-negative' : 'bg-fill-brand' }}"
                                 style="width: {{ $pct }}%"></div>
                        </div>
                        <p class="mt-1 text-[12px] text-muted">
                            {{ number_format($spent) }} of {{ number_format($project->budget) }} {{ $project->company->currency }}
                        </p>
                    @endif

                    @can('update', $project)
                        @if ($project->status !== 'cancelled')
                            <button type="button" wire:click="archive('{{ $project->id }}')"
                                    wire:confirm="Cancel this project?"
                                    class="focusable mt-3 flex h-8 items-center rounded-full border border-border bg-surface px-3 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                Cancel project
                            </button>
                        @endif
                    @endcan
                </div>
            @empty
                <p class="py-8 text-center text-[13.5px] text-muted">
                    @if ($search !== '' || $status !== '')
                        Nothing matches. <button type="button" wire:click="$set('search', ''); $set('status', '')" class="underline">Clear filters</button>
                    @else
                        No projects yet.
                    @endif
                </p>
            @endforelse

            @if ($projects->hasPages())
                <div class="mt-4">{{ $projects->links() }}</div>
            @endif
        </x-ui.panel>
    </div>
</div>
