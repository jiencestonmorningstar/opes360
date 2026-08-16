@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $state = $contract->state();
    $days = $contract->daysToNotice();
    $ccy = $contract->currency;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('contracts.index') }}" wire:navigate class="text-[13.5px] font-semibold text-muted hover:text-ink">← Contracts</a>

            <h1 class="mt-1 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $contract->title }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $contract->typeLabel() }} ·
                {{ $contract->direction === 'outbound' ? 'We sell under it' : 'We buy under it' }} ·
                {{ $contract->counterparty?->displayName() ?? 'No counterparty' }}
                @if ($contract->reference()) · {{ $contract->reference() }} @endif
            </p>
        </div>

        <x-ui.status-badge class="mt-2 shrink-0" :label="$state['label']" :tone="$state['tone']" />
    </div>

    @error('contract')
        <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
    @enderror

    {{--
        The notice date leads, above the value and above the dates it is derived
        from. It is the only thing on this screen that stops being actionable
        while you are looking at something else.
    --}}
    @if ($contract->notice_by)
        <div class="mt-5 rounded-2xl border px-4 py-3.5 text-[14.5px]
                    {{ $contract->noticeMissed()
                        ? 'border-rose-400 bg-rose-50 text-rose-800 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200'
                        : 'border-amber-300/60 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200' }}">
            <strong>Notice by {{ $contract->notice_by->toFormattedDateString() }}</strong>
            @if ($contract->noticeMissed())
                — that was {{ abs($days) }} {{ Str::plural('day', abs($days)) }} ago.
                @if ($contract->autoRenews())
                    This contract renews itself, so it is committed to another term unless the other side agrees otherwise.
                @endif
            @elseif ($days !== null)
                — {{ $days === 0 ? 'today' : $days.' '.Str::plural('day', $days).' left' }}.
                {{ $contract->notice_period_days }} days' notice on an end date of {{ $contract->ends_on?->toFormattedDateString() }}.
            @endif
        </div>
    @endif

    {{-- ──────────────────────────────────────────────────────── the terms ── --}}
    <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.panel title="The terms">
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Value</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">
                            {{ $contract->value === null ? 'Not stated' : number_format((float) $contract->value).' '.$ccy }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Runs</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">
                            {{ $contract->starts_on?->toFormattedDateString() ?? '—' }}
                            → {{ $contract->ends_on?->toFormattedDateString() ?? 'open-ended' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Renewal</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">
                            {{ $contract->renewalLabel() }}
                            @if ($contract->renewal_term_months)
                                <span class="text-muted">· {{ $contract->renewal_term_months }} months a term</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Owner</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">{{ $contract->owner?->name ?? 'Nobody' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Notice period</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">
                            {{ $contract->notice_period_days ? $contract->notice_period_days.' days' : 'None agreed' }}
                        </dd>
                    </div>
                    @if ($contract->isTerminated())
                        <div>
                            <dt class="text-[13px] font-semibold text-ink-2">Ended</dt>
                            <dd class="mt-0.5 text-[15px] text-ink">
                                {{ $contract->terminated_on?->toFormattedDateString() }}
                                @if ($contract->termination_reason)
                                    <span class="block text-[13.5px] text-muted">{{ $contract->termination_reason }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($contract->description)
                    <p class="mt-4 border-t border-border pt-4 text-[14.5px] text-ink-2">{{ $contract->description }}</p>
                @endif

                {{-- No approve button, on purpose: approving happens in /actions. --}}
                <div class="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
                    @can('contracts.manage')
                        @if ($contract->status === 'draft')
                            <button type="button" wire:click="submit"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Submit for approval
                            </button>
                        @endif
                    @endcan

                    @can('contracts.renew')
                        @if ($contract->isActive())
                            <button type="button" wire:click="startRenewing"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Renew
                            </button>
                        @endif
                    @endcan

                    @can('contracts.terminate')
                        @if ($contract->isActive())
                            <button type="button" wire:click="startTerminating"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-rose-600">
                                Terminate
                            </button>
                        @endif
                    @endcan
                </div>

                @if ($renewing)
                    <div class="mt-4 rounded-xl bg-surface-2 p-4">
                        @error('renewing')
                            <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                        @enderror

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}">New end date</label>
                                <input type="date" wire:model="newEndsOn" class="{{ $inputClass }}">
                                <p class="mt-1 text-[13px] text-muted">Leave blank to roll on by the agreed term.</p>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">New value</label>
                                <input type="number" step="0.01" wire:model="newValue" placeholder="{{ $ccy }}" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">How</label>
                                <select wire:model="renewalMethod" class="{{ $inputClass }}">
                                    @foreach (\App\Models\ContractRenewal::METHODS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Note</label>
                                <input type="text" wire:model="renewalNotes" placeholder="Optional" class="{{ $inputClass }}">
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="renew"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Record the renewal
                            </button>
                            <button type="button" wire:click="$set('renewing', false)"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif

                @if ($terminating)
                    <div class="mt-4 rounded-xl bg-surface-2 p-4">
                        @error('terminating')
                            <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                        @enderror

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}">Ends on</label>
                                <input type="date" wire:model="terminatedOn" class="{{ $inputClass }}">
                                @error('terminatedOn') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Why</label>
                                <input type="text" wire:model="terminationReason" placeholder="Optional" class="{{ $inputClass }}">
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="terminate"
                                    class="tap focusable rounded-full bg-fill-negative px-5 py-2 text-[14.5px] font-semibold text-white">
                                End the contract
                            </button>
                            <button type="button" wire:click="$set('terminating', false)"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </x-ui.panel>
        </div>

        {{-- The signed paper is an ordinary document; this is the same panel
             every other record uses to show what has been filed against it. --}}
        <div>
            <x-documents.library-panel :record="$contract" title="The paper" />
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────── obligations ── --}}
    <div class="mt-4">
        <x-ui.panel title="What each side must do">
            @can('contracts.manage')
                <div class="mb-4">
                    @if (! $addingObligation)
                        <button type="button" wire:click="$set('addingObligation', true)"
                                class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                            Add an obligation
                        </button>
                    @else
                        <div class="rounded-xl bg-surface-2 p-4">
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label class="{{ $labelClass }}">Whose</label>
                                    <select wire:model="owedBy" class="{{ $inputClass }}">
                                        @foreach (\App\Models\ContractObligation::SIDES as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="{{ $labelClass }}">What</label>
                                    <input type="text" wire:model="obligationTitle" placeholder="Monthly service report" class="{{ $inputClass }}">
                                    @error('obligationTitle') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="{{ $labelClass }}">By when</label>
                                    <input type="date" wire:model="obligationDueOn" class="{{ $inputClass }}">
                                    <p class="mt-1 text-[13px] text-muted">Blank for a standing promise.</p>
                                </div>
                                <div>
                                    <label class="{{ $labelClass }}">Detail</label>
                                    <input type="text" wire:model="obligationDescription" placeholder="Optional" class="{{ $inputClass }}">
                                </div>
                            </div>

                            <div class="mt-3 flex gap-2">
                                <button type="button" wire:click="addObligation"
                                        class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                    Add it
                                </button>
                                <button type="button" wire:click="$set('addingObligation', false)"
                                        class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @endcan

            @forelse ($obligations as $obligation)
                <div wire:key="ob-{{ $obligation->id }}"
                     class="flex flex-wrap items-center justify-between gap-3 {{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <div class="min-w-0">
                        <p class="text-[14.5px] font-semibold {{ $obligation->isDone() ? 'text-muted line-through' : 'text-ink' }}">
                            {{ $obligation->sideLabel() }}: {{ $obligation->title }}
                        </p>
                        <p class="mt-0.5 text-[13px] {{ $obligation->isOverdue() ? 'font-semibold text-rose-600' : 'text-muted' }}">
                            @if ($obligation->isDone())
                                Done {{ $obligation->completed_on->toFormattedDateString() }}
                                @if ($obligation->completer) by {{ $obligation->completer->name }} @endif
                            @elseif ($obligation->due_on)
                                Due {{ $obligation->due_on->toFormattedDateString() }}{{ $obligation->isOverdue() ? ' — overdue' : '' }}
                            @else
                                Ongoing, no date
                            @endif
                        </p>
                    </div>

                    @can('contracts.manage')
                        @unless ($obligation->isDone())
                            <button type="button" wire:click="completeObligation('{{ $obligation->id }}')"
                                    class="tap focusable shrink-0 rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                Mark done
                            </button>
                        @endunless
                    @endcan
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">
                    Nothing recorded. Until something is here, "are we complying with what we signed" has no answer.
                </p>
            @endforelse
        </x-ui.panel>
    </div>

    {{-- ───────────────────────────────────────────────────────── renewals ── --}}
    @if ($renewals->isNotEmpty())
        <div class="mt-4">
            <x-ui.panel title="How it got to this term">
                @foreach ($renewals as $renewal)
                    <div wire:key="rn-{{ $renewal->id }}"
                         class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                        <p class="text-[14.5px] text-ink">
                            {{ $renewal->renewed_on->toFormattedDateString() }} —
                            {{ $renewal->methodLabel() }},
                            {{ $renewal->previous_ends_on->toFormattedDateString() }}
                            → {{ $renewal->new_ends_on->toFormattedDateString() }}
                        </p>
                        <p class="mt-0.5 text-[13px] text-muted">
                            @php $change = $renewal->valueChange(); @endphp
                            @if ($change !== null && abs($change) > 0.001)
                                Value {{ $change > 0 ? 'up' : 'down' }} {{ number_format(abs($change)) }} {{ $ccy }}
                                to {{ number_format((float) $renewal->new_value) }}
                            @else
                                Same price
                            @endif
                            @if ($renewal->creator) · {{ $renewal->creator->name }} @endif
                            @if ($renewal->notes) · {{ $renewal->notes }} @endif
                        </p>
                    </div>
                @endforeach
            </x-ui.panel>
        </div>
    @endif
</div>
