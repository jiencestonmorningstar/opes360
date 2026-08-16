<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Positions</h1>
            <p class="mt-1 text-[14.5px] text-muted">The posts your business keeps, not the people in them. Archive one rather than deleting it — its title is on appraisals and staff files that already exist.</p>
        </div>
    </div>

    @include('partials.business-tabs')

    <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] lg:items-start">

        @can('positions.manage')
            <x-ui.panel :title="$editing ? 'Edit position' : 'Add a position'">
                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label for="pos-title" class="text-[13px] font-semibold text-ink-2">Title</label>
                        <input id="pos-title" wire:model="title" type="text" autocomplete="off"
                               class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                        @error('title') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="pos-code" class="text-[13px] font-semibold text-ink-2">Code <span class="font-normal text-muted">optional</span></label>
                        <input id="pos-code" wire:model="code" type="text" autocomplete="off"
                               class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                        @error('code') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="pos-grade" class="text-[13px] font-semibold text-ink-2">Grade <span class="font-normal text-muted">optional</span></label>
                        {{-- Free text: grades follow the convention collective of the trade, which no list here could enumerate. --}}
                        <input id="pos-grade" wire:model="grade" type="text" autocomplete="off" placeholder="e.g. Catégorie 5, échelon B"
                               class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink placeholder:text-faint">
                        @error('grade') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="pos-department" class="text-[13px] font-semibold text-ink-2">Department</label>
                        <select id="pos-department" wire:model="departmentId"
                                class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                            <option value="">No department in particular</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                        @error('departmentId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex gap-2">
                        <button type="submit"
                                class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            {{ $editing ? 'Save changes' : 'Add position' }}
                        </button>

                        @if ($editing)
                            <button type="button" wire:click="cancel"
                                    class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                Cancel
                            </button>
                        @endif
                    </div>
                </form>
            </x-ui.panel>
        @endcan

        <x-ui.panel :title="$showArchived ? 'All positions' : 'Active positions'">
            <x-slot:actions>
                <label class="flex items-center gap-2 text-[13px] font-semibold text-muted">
                    <input type="checkbox" wire:model.live="showArchived" class="focusable rounded border-border">
                    Show archived
                </label>
            </x-slot:actions>

            @forelse ($positions as $position)
                <div wire:key="pos-{{ $position->id }}"
                     class="{{ $loop->first ? '' : 'mt-3' }} flex items-center justify-between gap-4 rounded-xl bg-surface-2 p-4">
                    <div class="min-w-0">
                        <p class="truncate text-[14.5px] font-semibold text-ink">{{ $position->label() }}</p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            {{ $position->active_headcount }} {{ Str::plural('person', $position->active_headcount) }} in post
                            @if ($position->code) · {{ $position->code }} @endif
                            @if ($position->grade) · {{ $position->grade }} @endif
                            @unless ($position->is_active) · Archived @endunless
                        </p>
                    </div>

                    @can('positions.manage')
                        <div class="flex shrink-0 gap-2">
                            <button type="button" wire:click="edit('{{ $position->id }}')"
                                    class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Edit
                            </button>

                            @if ($position->is_active)
                                <button type="button" wire:click="archive('{{ $position->id }}')"
                                        class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                    Archive
                                </button>
                            @else
                                <button type="button" wire:click="restore('{{ $position->id }}')"
                                        class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                    Restore
                                </button>
                            @endif
                        </div>
                    @endcan
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No positions yet. Add the first one on the left.</p>
            @endforelse
        </x-ui.panel>

    </div>
</div>
