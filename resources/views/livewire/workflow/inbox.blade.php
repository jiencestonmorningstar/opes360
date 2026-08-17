<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div>
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">My actions</h1>
        <p class="mt-1 text-[14.5px] text-muted">Everything waiting on you, from every part of the business.</p>
    </div>

    <div class="mt-5">
        <x-ui.panel title="Waiting on you ({{ $assignments->total() }})">
            @forelse ($assignments as $assignment)
                @php $instance = $assignment->instance; @endphp

                <div wire:key="assign-{{ $assignment->id }}"
                     class="{{ $loop->first ? '' : 'mt-3' }} rounded-xl bg-surface-2 p-4">

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[14.5px] font-semibold text-ink">
                                {{ $instance->subject?->description
                                    ?? $instance->subject?->title
                                    ?? $instance->subject?->reference
                                    ?? $instance->workflow?->name
                                    ?? 'Awaiting approval' }}
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                {{ $instance->workflow?->name }} · {{ $assignment->step?->name }}
                                @if ($assignment->delegatedFrom)
                                    · Handed to you by {{ $assignment->delegatedFrom->firstName() }}
                                @endif
                            </p>
                        </div>

                        @if ($assignment->isOverdue())
                            <span class="shrink-0 rounded-full bg-tint-warning px-3 py-1 text-[12px] font-semibold text-warning">
                                Overdue
                            </span>
                        @elseif ($assignment->due_on)
                            <span class="shrink-0 text-[12.5px] text-muted">
                                Due {{ $assignment->due_on->format('j M') }}
                            </span>
                        @endif
                    </div>

                    @if ($acting === $assignment->workflow_instance_id)
                        <textarea wire:model="comment" rows="2"
                                  placeholder="Add a note — required if you are asking for changes."
                                  class="focusable mt-3 w-full rounded-xl border border-border bg-surface p-3 text-[14px] text-ink"></textarea>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="approve('{{ $assignment->workflow_instance_id }}')"
                                class="focusable flex h-9 items-center rounded-full bg-fill-brand px-4 text-[13px] font-semibold text-white hover:opacity-90">
                            Approve
                        </button>

                        <button type="button" wire:click="requestChanges('{{ $assignment->workflow_instance_id }}')"
                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                            Ask for changes
                        </button>

                        <button type="button" wire:click="reject('{{ $assignment->workflow_instance_id }}')"
                                wire:confirm="Reject this? It stops the approval for good — ask for changes instead if it can be fixed."
                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-negative hover:bg-surface-2">
                            Reject
                        </button>

                        <button type="button" wire:click="$set('acting', '{{ $assignment->workflow_instance_id }}')"
                                class="focusable flex h-9 items-center rounded-full px-4 text-[13px] font-semibold text-muted hover:bg-surface">
                            Add a note
                        </button>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-[13.5px] text-muted">Nothing is waiting on you.</p>
            @endforelse

            @if ($assignments->hasPages())
                <div class="mt-4">{{ $assignments->links() }}</div>
            @endif
        </x-ui.panel>
    </div>

    @if ($stuck->isNotEmpty())
        <div class="mt-5">
            <x-ui.panel title="Stuck approvals ({{ $stuck->count() }})">
                <p class="text-[13px] text-muted">These stopped because nobody could be asked at a step. Fix who fills the step, then send them back round.</p>

                @error('stuck')
                    <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p>
                @enderror

                @foreach ($stuck as $instance)
                    <div wire:key="stuck-{{ $instance->id }}" class="mt-3 rounded-xl bg-surface-2 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-[14.5px] font-semibold text-ink">
                                    {{ $instance->subject?->description
                                        ?? $instance->subject?->title
                                        ?? $instance->subject?->reference
                                        ?? $instance->workflow?->name
                                        ?? 'Awaiting approval' }}
                                </p>
                                <p class="mt-0.5 text-[12.5px] text-muted">
                                    {{ $instance->workflow?->name }} · stalled at {{ $instance->currentStep()?->name ?? 'a step' }}
                                </p>
                            </div>

                            <button type="button" wire:click="resubmit('{{ $instance->id }}')"
                                    class="focusable flex h-9 shrink-0 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Resubmit
                            </button>
                        </div>
                    </div>
                @endforeach
            </x-ui.panel>
        </div>
    @endif
</div>
