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

    @error('projects')
        <p class="mt-3 rounded-xl bg-negative/10 px-4 py-2.5 text-[13.5px] text-negative">{{ $message }}</p>
    @enderror

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

                    <div class="mt-3 flex flex-wrap gap-2">
                        @can('update', $project)
                            @if ($project->status === 'planning')
                                <button type="button" wire:click="transition('{{ $project->id }}', 'active')"
                                        class="focusable flex h-8 items-center rounded-full bg-fill-brand px-3 text-[12.5px] font-semibold text-white hover:opacity-90">
                                    Start
                                </button>
                            @endif
                            @if ($project->status === 'active')
                                <button type="button" wire:click="transition('{{ $project->id }}', 'on_hold')"
                                        class="focusable flex h-8 items-center rounded-full border border-border bg-surface px-3 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                    Put on hold
                                </button>
                            @endif
                            @if ($project->status === 'on_hold')
                                <button type="button" wire:click="transition('{{ $project->id }}', 'active')"
                                        class="focusable flex h-8 items-center rounded-full bg-fill-brand px-3 text-[12.5px] font-semibold text-white hover:opacity-90">
                                    Resume
                                </button>
                            @endif
                            @if ($project->isOpen())
                                <button type="button" wire:click="transition('{{ $project->id }}', 'completed')"
                                        wire:confirm="Mark this project completed?"
                                        class="focusable flex h-8 items-center rounded-full border border-border bg-surface px-3 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                    Complete
                                </button>
                                <button type="button" wire:click="transition('{{ $project->id }}', 'cancelled')"
                                        wire:confirm="Cancel this project?"
                                        class="focusable flex h-8 items-center rounded-full border border-border bg-surface px-3 text-[12.5px] font-semibold text-negative hover:bg-surface-2">
                                    Cancel project
                                </button>
                            @endif
                        @endcan

                        <button type="button" wire:click="toggleOpen('{{ $project->id }}')"
                                class="focusable flex h-8 items-center gap-1 rounded-full border border-border bg-surface px-3 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            {{ $open === $project->id ? 'Hide tasks' : 'Tasks & milestones' }}
                        </button>
                    </div>

                    @if ($open === $project->id && $openProject)
                        <div class="mt-3 rounded-xl border border-border bg-surface p-4">
                            @error('projects')
                                <p class="mb-3 rounded-lg bg-negative/10 px-3 py-2 text-[13px] text-negative">{{ $message }}</p>
                            @enderror

                            {{-- Milestones --}}
                            <p class="text-[13px] font-semibold text-ink-2">Milestones</p>
                            <div class="mt-2 grid gap-1.5">
                                @forelse ($openProject->milestones as $milestone)
                                    <div wire:key="ms-{{ $milestone->id }}" class="flex items-center justify-between rounded-lg bg-surface-2 px-3 py-2">
                                        <span class="text-[13px] {{ $milestone->isComplete() ? 'text-muted line-through decoration-border' : ($milestone->isOverdue() ? 'font-medium text-negative' : 'font-medium text-ink-2') }}">
                                            {{ $milestone->name }}
                                            @if ($milestone->due_on) <span class="font-normal text-muted">· {{ $milestone->due_on->format('d M') }}</span> @endif
                                        </span>
                                        @can('update', $project)
                                            <button type="button" wire:click="completeMilestone('{{ $milestone->id }}')"
                                                    class="focusable text-[12px] font-semibold text-brand hover:underline">
                                                {{ $milestone->isComplete() ? 'Reopen' : 'Done' }}
                                            </button>
                                        @endcan
                                    </div>
                                @empty
                                    <p class="text-[12.5px] text-muted">No milestones yet.</p>
                                @endforelse
                            </div>

                            @can('update', $project)
                                <form wire:submit="addMilestone" class="mt-2 flex flex-wrap items-end gap-2">
                                    <input wire:model="milestoneName" type="text" placeholder="New milestone"
                                           class="focusable h-9 min-w-44 flex-1 rounded-lg border border-border bg-surface px-3 text-[13px] text-ink">
                                    <input wire:model="milestoneDueOn" type="date"
                                           class="focusable h-9 rounded-lg border border-border bg-surface px-2 text-[13px] text-ink">
                                    <button type="submit" class="focusable h-9 rounded-full border border-border bg-surface px-3.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">Add</button>
                                </form>
                                @error('milestoneName') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror
                            @endcan

                            {{-- Tasks --}}
                            <p class="mt-4 text-[13px] font-semibold text-ink-2">Tasks</p>
                            <div class="mt-2 grid gap-1.5">
                                @forelse ($openProject->tasks as $task)
                                    <div wire:key="task-{{ $task->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-2 px-3 py-2">
                                        <span class="min-w-0 text-[13px] {{ $task->status === 'done' ? 'text-muted line-through decoration-border' : ($task->isOverdue() ? 'font-medium text-negative' : 'font-medium text-ink-2') }}">
                                            {{ $task->title }}
                                            @if ($task->milestone) <span class="font-normal text-muted">· {{ $task->milestone->name }}</span> @endif
                                            @if ($task->due_on) <span class="font-normal text-muted">· {{ $task->due_on->format('d M') }}</span> @endif
                                        </span>
                                        @can('update', $project)
                                            <select wire:change="moveTask('{{ $task->id }}', $event.target.value)"
                                                    class="focusable h-8 rounded-lg border border-border bg-surface px-2 text-[12.5px] text-ink-2"
                                                    aria-label="Task status">
                                                @foreach ($taskStatuses as $value => $label)
                                                    <option value="{{ $value }}" @selected($task->status === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="text-[12px] text-muted">{{ $taskStatuses[$task->status] ?? $task->status }}</span>
                                        @endcan
                                    </div>
                                @empty
                                    <p class="text-[12.5px] text-muted">No tasks yet.</p>
                                @endforelse
                            </div>

                            @can('update', $project)
                                <form wire:submit="addTask" class="mt-2 flex flex-wrap items-end gap-2">
                                    <input wire:model="taskTitle" type="text" placeholder="New task"
                                           class="focusable h-9 min-w-44 flex-1 rounded-lg border border-border bg-surface px-3 text-[13px] text-ink">
                                    <input wire:model="taskDueOn" type="date"
                                           class="focusable h-9 rounded-lg border border-border bg-surface px-2 text-[13px] text-ink">
                                    @if ($openProject->milestones->isNotEmpty())
                                        <select wire:model="taskMilestoneId"
                                                class="focusable h-9 rounded-lg border border-border bg-surface px-2 text-[13px] text-ink-2"
                                                aria-label="Milestone">
                                            <option value="">No milestone</option>
                                            @foreach ($openProject->milestones as $milestone)
                                                <option value="{{ $milestone->id }}">{{ $milestone->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <button type="submit" class="focusable h-9 rounded-full border border-border bg-surface px-3.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">Add</button>
                                </form>
                                @error('taskTitle') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror
                            @endcan
                        </div>
                    @endif
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
