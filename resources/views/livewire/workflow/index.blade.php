<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-4xl">

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Approval paths</h1>
                <p class="mt-1 max-w-2xl text-[14.5px] leading-relaxed text-muted">
                    Who has to say yes, and in what order, before something goes ahead. Changing a path here is
                    the same act as authorising the spend, so only an owner or an administrator can.
                </p>
            </div>

            @can('workflows.manage')
                <button type="button" wire:click="startCreating"
                        class="tap focusable flex h-10 shrink-0 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                    New path
                </button>
            @endcan
        </div>

        @error('workflow')
            <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[14px] font-semibold text-negative">{{ $message }}</div>
        @enderror

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">{{ session('status') }}</div>
        @endif

        @if ($creating)
            <x-ui.panel title="A new approval path" class="mt-5">
                <form wire:submit="create" class="space-y-4">
                    <div>
                        <label for="wf-name" class="text-[13px] font-semibold text-ink-2">What is it called?</label>
                        <input id="wf-name" wire:model="name" type="text" autocomplete="off" placeholder="Purchases over five million"
                               class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                        @error('name') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="wf-subject" class="text-[13px] font-semibold text-ink-2">What does it approve?</label>
                        <select id="wf-subject" wire:model.live="subjectType"
                                class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                            @foreach ($subjectOptions as $class => $label)
                                <option value="{{ $class }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @if ($hint = ($subjects[$subjectType]['hint'] ?? null))
                            <p class="mt-1 text-[12.5px] text-muted">{{ $hint }}</p>
                        @endif
                        @error('subjectType') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <p class="rounded-xl bg-surface-2 px-3.5 py-3 text-[13px] leading-relaxed text-muted">
                        It starts switched off and with no steps. A path with no steps approves everything the moment
                        it starts, so you write the steps first and turn it on afterwards.
                    </p>

                    <div class="flex gap-2">
                        <button type="submit"
                                class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            Create and add steps
                        </button>
                        <button type="button" wire:click="cancel"
                                class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            Cancel
                        </button>
                    </div>
                </form>
            </x-ui.panel>
        @endif

        @forelse ($groups as $subjectType => $workflows)
            <div class="mt-7" wire:key="group-{{ md5($subjectType) }}">
                <h2 class="text-[17px] font-bold text-ink">{{ $subjects[$subjectType]['label'] ?? class_basename($subjectType) }}</h2>
                @if ($hint = ($subjects[$subjectType]['hint'] ?? null))
                    <p class="mt-0.5 text-[13px] text-muted">{{ $hint }}</p>
                @endif

                <div class="card mt-3 divide-y divide-border p-0">
                    @foreach ($workflows as $workflow)
                        <div wire:key="wf-{{ $workflow->id }}" class="px-4 py-3.5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-[14.5px] font-semibold text-ink">
                                        <a href="{{ route('workflows.edit', $workflow) }}" wire:navigate class="hover:underline">{{ $workflow->name }}</a>

                                        @if ($workflow->is_default)
                                            <span class="rounded-full bg-tint-blue px-2 py-0.5 text-[11.5px] font-semibold text-brand">Used by default</span>
                                        @endif

                                        <span class="rounded-full px-2 py-0.5 text-[11.5px] font-semibold {{ $workflow->is_active ? 'bg-tint-green text-positive' : 'bg-surface-2 text-muted' }}">
                                            {{ $workflow->is_active ? 'On' : 'Off' }}
                                        </span>
                                    </p>

                                    <p class="mt-0.5 text-[12.5px] text-muted">
                                        {{ $workflow->steps_count }} {{ Str::plural('step', $workflow->steps_count) }}
                                        @if (($inFlight[$workflow->id] ?? 0) > 0)
                                            · {{ $inFlight[$workflow->id] }} waiting on somebody right now
                                        @endif
                                    </p>

                                    @if ($workflow->steps_count === 0)
                                        <p class="mt-1 text-[12.5px] font-semibold text-warning">
                                            No steps yet — this would approve everything sent to it.
                                        </p>
                                    @endif

                                    @foreach (($warnings[$workflow->id] ?? []) as $warning)
                                        <p class="mt-1 text-[12.5px] font-semibold text-warning">{{ $warning }}</p>
                                    @endforeach
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <a href="{{ route('workflows.edit', $workflow) }}" wire:navigate
                                       class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                        @can('workflows.manage') Edit @else View @endcan
                                    </a>

                                    @can('workflows.manage')
                                        @unless ($workflow->is_default)
                                            <button type="button" wire:click="makeDefault('{{ $workflow->id }}')"
                                                    class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                                Use by default
                                            </button>
                                        @endunless

                                        <button type="button" wire:click="toggleActive('{{ $workflow->id }}')"
                                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                            {{ $workflow->is_active ? 'Switch off' : 'Switch on' }}
                                        </button>

                                        <button type="button" wire:click="duplicate('{{ $workflow->id }}')"
                                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                            Duplicate
                                        </button>

                                        <button type="button" wire:click="delete('{{ $workflow->id }}')"
                                                wire:confirm="Remove this approval path? Anything already waiting on it will still finish under the rules it started with."
                                                class="focusable flex h-9 items-center rounded-full px-3 text-[13px] font-semibold text-negative hover:underline">
                                            Remove
                                        </button>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="card mt-6 p-8 text-center">
                <p class="text-[15px] font-semibold text-ink">No approval paths yet.</p>
                <p class="mx-auto mt-1 max-w-md text-[13.5px] leading-relaxed text-muted">
                    Until there is one, nothing can be submitted for approval — every submission is refused with
                    "no approval path is defined".
                </p>
            </div>
        @endforelse

        <p class="mt-8 text-[12.5px] leading-relaxed text-faint">
            Name a role or a department rather than a person wherever you can. A path that names somebody by name
            is wrong the day they leave, and the only symptom is a record sitting untouched.
        </p>
    </div>
</div>
