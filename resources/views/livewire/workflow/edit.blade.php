@php
    // Plain-English operator labels. The list itself comes from
    // WorkflowConditions so the form can only offer what the engine evaluates.
    $operatorLabels = [
        '>' => 'is more than',
        '>=' => 'is at least',
        '<' => 'is less than',
        '<=' => 'is at most',
        '=' => 'is',
        '!=' => 'is not',
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-3xl">

        <a href="{{ route('workflows') }}" wire:navigate
           class="focusable -ml-1 inline-flex items-center gap-1.5 rounded-lg p-1 text-[14px] font-semibold text-muted hover:text-ink">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            Approval paths
        </a>

        <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $workflow->name }}</h1>
        <p class="mt-1 text-[14.5px] text-muted">
            Approves {{ $subjectLabel }}.
            @if ($workflow->is_default) This is the path they go through by default. @endif
        </p>

        @foreach ($notices as $notice)
            <div class="mt-5 rounded-xl px-4 py-3 text-[14px] font-semibold {{ $loop->first ? 'bg-tint-green text-positive' : 'bg-surface-2 text-warning' }}"
                 wire:key="notice-{{ $loop->index }}">{{ $notice }}</div>
        @endforeach

        @error('steps')
            <div class="mt-5 rounded-xl bg-tint-red px-4 py-3 text-[14px] font-semibold text-negative">{{ $message }}</div>
        @enderror

        @if ($inFlight > 0)
            <div class="mt-5 rounded-xl bg-tint-blue px-4 py-3 text-[13.5px] leading-relaxed text-brand">
                <span class="font-semibold">{{ $inFlight }} {{ Str::plural('approval', $inFlight) }} still running on this path.</span>
                If you change the steps, those keep the rules they started with and finish as they were going to.
                Only what is submitted from now on uses the new steps.
            </div>
        @endif

        {{-- ── What it is ───────────────────────────────────────────── --}}
        <x-ui.panel title="What this path is" class="mt-5">
            <form wire:submit="saveDetails" class="space-y-4">
                <div>
                    <label for="wf-name" class="text-[13px] font-semibold text-ink-2">Name</label>
                    <input id="wf-name" wire:model="name" type="text" @disabled(! $canManage)
                           class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink disabled:opacity-60">
                    @error('name') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="wf-subject" class="text-[13px] font-semibold text-ink-2">What it approves</label>
                    <select id="wf-subject" wire:model="subjectType" @disabled(! $canManage)
                            class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink disabled:opacity-60">
                        @foreach ($subjectOptions as $class => $label)
                            <option value="{{ $class }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('subjectType') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="wf-description" class="text-[13px] font-semibold text-ink-2">Note <span class="font-normal text-muted">optional — why this path exists</span></label>
                    <textarea id="wf-description" wire:model="description" rows="2" @disabled(! $canManage)
                              class="focusable mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-[14px] text-ink disabled:opacity-60"></textarea>
                </div>

                @can('workflows.manage')
                    <div class="flex flex-wrap gap-2">
                        <button type="submit"
                                class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            Save
                        </button>
                        <button type="button" wire:click="toggleActive"
                                class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            {{ $workflow->is_active ? 'Switch off' : 'Switch on' }}
                        </button>
                        @unless ($workflow->is_default)
                            <button type="button" wire:click="makeDefault"
                                    class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                Use by default
                            </button>
                        @endunless
                    </div>
                @endcan
            </form>
        </x-ui.panel>

        {{-- ── The steps ────────────────────────────────────────────── --}}
        <div class="mt-7 flex items-center justify-between gap-4">
            <h2 class="text-[17px] font-bold text-ink">The steps, in order</h2>
            @can('workflows.manage')
                @unless ($editingStep)
                    <button type="button" wire:click="addStep"
                            class="tap focusable flex h-9 items-center rounded-full bg-fill-brand px-4 text-[13px] font-semibold text-white hover:opacity-90">
                        Add a step
                    </button>
                @endunless
            @endcan
        </div>

        <div class="card mt-3 divide-y divide-border p-0">
            @forelse ($steps as $step)
                <div wire:key="step-{{ $step->id }}" class="flex items-start gap-3 px-4 py-3.5">
                    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-2 text-[12.5px] font-bold text-ink-2">
                        {{ $step->position }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-[14.5px] font-semibold text-ink">{{ $step->name }}</p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            {{ $types[$step->type] ?? $step->type }}
                            · {{ $approverModes[$step->approver_mode] ?? $step->approver_mode }}@if ($step->approver_mode === 'role' && $step->approver_role)
                                : {{ collect($roles)->firstWhere('slug', $step->approver_role)['name'] ?? $step->approver_role }}@elseif ($step->approver_mode === 'department')
                                : {{ $step->approverDepartment?->name ?? 'none chosen' }}@elseif ($step->approver_mode === 'user')
                                : {{ $step->approverUser?->name ?? 'nobody' }}@endif
                            · {{ $step->quorum === 'any' ? 'one approval is enough' : ($step->quorum === 'all' ? 'everyone asked must approve' : $step->quorum.' approvals needed') }}
                            @if ($step->due_days) · due in {{ $step->due_days }} {{ Str::plural('day', $step->due_days) }} @endif
                        </p>

                        @if (! empty($step->conditions))
                            <p class="mt-1 text-[12.5px] text-muted">
                                Only when
                                @foreach ($step->conditions as $condition)
                                    <span class="font-semibold text-ink-2">{{ $condition['field'] ?? '?' }}
                                        {{ $operatorLabels[$condition['operator'] ?? ''] ?? ($condition['operator'] ?? '?') }}
                                        {{ $condition['value'] ?? '?' }}</span>@if (! $loop->last), and @endif
                                @endforeach
                            </p>
                        @endif

                        @if ($stepWarnings[$step->id] ?? null)
                            <p class="mt-1.5 rounded-lg bg-surface-2 px-2.5 py-1.5 text-[12.5px] font-semibold text-warning">
                                {{ $stepWarnings[$step->id] }}
                            </p>
                        @endif
                    </div>

                    @can('workflows.manage')
                        <div class="flex shrink-0 items-center gap-1.5">
                            <button type="button" wire:click="moveUp('{{ $step->id }}')" @disabled($loop->first)
                                    class="focusable flex size-9 items-center justify-center rounded-full border border-border bg-surface text-ink-2 hover:bg-surface-2 disabled:opacity-40"
                                    aria-label="Move earlier">↑</button>
                            <button type="button" wire:click="moveDown('{{ $step->id }}')" @disabled($loop->last)
                                    class="focusable flex size-9 items-center justify-center rounded-full border border-border bg-surface text-ink-2 hover:bg-surface-2 disabled:opacity-40"
                                    aria-label="Move later">↓</button>
                            <button type="button" wire:click="editStep('{{ $step->id }}')"
                                    class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">Edit</button>
                            <button type="button" wire:click="removeStep('{{ $step->id }}')"
                                    wire:confirm="Remove this step?"
                                    class="focusable flex h-9 items-center rounded-full px-3 text-[13px] font-semibold text-negative hover:underline">Remove</button>
                        </div>
                    @endcan
                </div>
            @empty
                <p class="px-4 py-8 text-center text-[13.5px] leading-relaxed text-muted">
                    No steps yet. A path with no steps approves everything the moment it starts, so it stays
                    switched off until you add one.
                </p>
            @endforelse
        </div>

        {{-- ── The step form ────────────────────────────────────────── --}}
        @if ($editingStep)
            <x-ui.panel :title="$stepId ? 'Edit this step' : 'Add a step'" class="mt-4">
                <form wire:submit="saveStep" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="st-name" class="text-[13px] font-semibold text-ink-2">What is this step called?</label>
                            <input id="st-name" wire:model="stepName" type="text" autocomplete="off" placeholder="Finance checks the figures"
                                   class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                            @error('stepName') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="st-type" class="text-[13px] font-semibold text-ink-2">What kind of step</label>
                            <select id="st-type" wire:model="stepType"
                                    class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                @foreach ($types as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- The whole argument of this screen, said out loud. --}}
                    <div>
                        <label for="st-mode" class="text-[13px] font-semibold text-ink-2">Who has to act</label>
                        <select id="st-mode" wire:model.live="approverMode"
                                class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                            @foreach ($approverModes as $key => $label)
                                <option value="{{ $key }}">{{ $label }}{{ $key === 'user' ? ' — avoid unless you mean it' : '' }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-[12.5px] leading-relaxed text-muted">
                            Name a <span class="font-semibold text-ink-2">role</span>, a
                            <span class="font-semibold text-ink-2">department</span> or a relationship to the record
                            wherever you can. Whoever holds it is worked out at the moment the step is reached, so the
                            path keeps working when people change jobs. Naming
                            <span class="font-semibold text-ink-2">one person</span> is wrong the day they leave, and
                            nobody finds out until something has sat unapproved for a week.
                        </p>
                    </div>

                    @if ($approverMode === 'role')
                        <div>
                            <label for="st-role" class="text-[13px] font-semibold text-ink-2">Which role</label>
                            <select id="st-role" wire:model.live="approverRole"
                                    class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                <option value="">Choose a role…</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role['slug'] }}">{{ $role['name'] }}</option>
                                @endforeach
                            </select>
                            @error('approverRole') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($approverMode === 'department')
                        <div>
                            <label for="st-dept" class="text-[13px] font-semibold text-ink-2">Which department <span class="font-normal text-muted">— its manager is asked</span></label>
                            <select id="st-dept" wire:model.live="approverDepartmentId"
                                    class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                <option value="">Choose a department…</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}{{ $department->manager_id ? '' : ' (no manager yet)' }}</option>
                                @endforeach
                            </select>
                            @error('approverDepartmentId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($approverMode === 'user')
                        <div>
                            <label for="st-user" class="text-[13px] font-semibold text-ink-2">Which person</label>
                            <select id="st-user" wire:model="approverUserId"
                                    class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                <option value="">Choose somebody…</option>
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-[12.5px] font-semibold text-warning">
                                This step will stop working the day they leave, and it will stop silently.
                            </p>
                            @error('approverUserId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="st-quorum" class="text-[13px] font-semibold text-ink-2">How many have to approve</label>
                            <select id="st-quorum" wire:model.live="quorumMode"
                                    class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                <option value="any">Any one of them</option>
                                <option value="all">Everybody asked</option>
                                <option value="count">A set number</option>
                            </select>
                            @if ($quorumMode === 'count')
                                <input type="number" min="1" max="20" wire:model="quorumCount"
                                       class="focusable mt-2 h-11 w-28 rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                            @endif
                        </div>

                        <div>
                            <label for="st-due" class="text-[13px] font-semibold text-ink-2">Due within <span class="font-normal text-muted">optional</span></label>
                            <div class="mt-1 flex items-center gap-2">
                                <input id="st-due" type="number" min="1" max="365" wire:model="dueDays"
                                       class="focusable h-11 w-28 rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                <span class="text-[14px] text-muted">days</span>
                            </div>
                            @error('dueDays') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Conditions. Data, never code: a field, one of six
                         operators, and a value. Anything the engine cannot
                         evaluate fails closed and the step is skipped, which is
                         why the form offers only what it understands. --}}
                    <div>
                        <p class="text-[13px] font-semibold text-ink-2">Only use this step when…</p>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-muted">
                            Leave empty and the step always applies. Every line has to be true, otherwise the step is
                            skipped altogether — it is not stalled on.
                        </p>

                        <div class="mt-2 space-y-2">
                            @foreach ($conditions as $index => $condition)
                                <div class="flex flex-wrap items-center gap-2" wire:key="cond-{{ $index }}">
                                    <select wire:model="conditions.{{ $index }}.field"
                                            class="focusable h-10 min-w-[9rem] flex-1 rounded-xl border border-border bg-surface px-2.5 text-[13.5px] text-ink">
                                        <option value="">Choose a field…</option>
                                        @foreach ($fields as $field)
                                            <option value="{{ $field }}">{{ $field }}</option>
                                        @endforeach
                                    </select>

                                    <select wire:model="conditions.{{ $index }}.operator"
                                            class="focusable h-10 rounded-xl border border-border bg-surface px-2.5 text-[13.5px] text-ink">
                                        @foreach ($operators as $operator)
                                            <option value="{{ $operator }}">{{ $operatorLabels[$operator] ?? $operator }}</option>
                                        @endforeach
                                    </select>

                                    <input type="text" wire:model="conditions.{{ $index }}.value" placeholder="10000000"
                                           class="focusable h-10 w-32 rounded-xl border border-border bg-surface px-2.5 text-[13.5px] text-ink">

                                    <button type="button" wire:click="removeCondition({{ $index }})"
                                            class="focusable h-10 rounded-full px-3 text-[13px] font-semibold text-negative hover:underline">Remove</button>
                                </div>
                                @error('conditions.'.$index.'.value') <p class="text-[13px] text-negative">{{ $message }}</p> @enderror
                                @error('conditions.'.$index.'.field') <p class="text-[13px] text-negative">{{ $message }}</p> @enderror
                            @endforeach
                        </div>

                        @if (empty($fields))
                            <p class="mt-2 text-[12.5px] text-muted">No fields can be read for this kind of record, so no condition can be written.</p>
                        @else
                            <button type="button" wire:click="addCondition"
                                    class="focusable mt-2 flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Add a condition
                            </button>
                        @endif
                    </div>

                    <div class="flex gap-2">
                        <button type="submit"
                                class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            {{ $stepId ? 'Save step' : 'Add step' }}
                        </button>
                        <button type="button" wire:click="cancelStep"
                                class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            Cancel
                        </button>
                    </div>
                </form>
            </x-ui.panel>
        @endif

        <p class="mt-8 text-[12.5px] leading-relaxed text-faint">
            A step nobody can fill does not pass — the approval stops there and waits, without telling anybody.
            That is deliberate: an approval that approves itself because its approver left is worse than a stuck one,
            because the stuck one gets noticed.
        </p>
    </div>
</div>
