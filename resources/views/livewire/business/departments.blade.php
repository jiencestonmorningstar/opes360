<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Departments</h1>
            <p class="mt-1 text-[14.5px] text-muted">The list your staff and documents are filed under. Archive one rather than deleting it — its name is on work that has already happened.</p>
        </div>
    </div>

    @include('partials.business-tabs')

    <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] lg:items-start">

        <x-ui.panel :title="$editing ? 'Edit department' : 'Add a department'">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label for="dept-name" class="text-[13px] font-semibold text-ink-2">Name</label>
                    <input id="dept-name" wire:model="name" type="text" autocomplete="off"
                           class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                    @error('name') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="dept-code" class="text-[13px] font-semibold text-ink-2">Code <span class="font-normal text-muted">optional</span></label>
                    <input id="dept-code" wire:model="code" type="text" autocomplete="off"
                           class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                    @error('code') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="dept-parent" class="text-[13px] font-semibold text-ink-2">Sits under</label>
                    <select id="dept-parent" wire:model="parentId"
                            class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                        <option value="">Nothing — a top-level department</option>
                        @foreach ($departments as $option)
                            @if ($option->id !== $editing)
                                <option value="{{ $option->id }}">{{ $option->path() }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('parentId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                            class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                        {{ $editing ? 'Save changes' : 'Add department' }}
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

        <x-ui.panel :title="$showArchived ? 'All departments' : 'Active departments'">
            <x-slot:actions>
                <label class="flex items-center gap-2 text-[13px] font-semibold text-muted">
                    <input type="checkbox" wire:model.live="showArchived" class="focusable rounded border-border">
                    Show archived
                </label>
            </x-slot:actions>

            @forelse ($departments as $department)
                <div wire:key="dept-{{ $department->id }}"
                     class="{{ $loop->first ? '' : 'mt-3' }} flex items-center justify-between gap-4 rounded-xl bg-surface-2 p-4">
                    <div class="min-w-0">
                        <p class="truncate text-[14.5px] font-semibold text-ink">{{ $department->path() }}</p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            {{ $department->employees_count }} {{ Str::plural('person', $department->employees_count) }}
                            @if ($department->code) · {{ $department->code }} @endif
                            @unless ($department->is_active) · Archived @endunless
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <button type="button" wire:click="edit('{{ $department->id }}')"
                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                            Edit
                        </button>

                        @if ($department->is_active)
                            <button type="button" wire:click="archive('{{ $department->id }}')"
                                    class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Archive
                            </button>
                        @else
                            <button type="button" wire:click="restore('{{ $department->id }}')"
                                    class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Restore
                            </button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No departments yet. Add the first one on the left.</p>
            @endforelse
        </x-ui.panel>

    </div>
</div>
