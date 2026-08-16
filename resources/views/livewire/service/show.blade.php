@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $pill = 'tap focusable rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2';
    $primary = 'tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white';

    // Why the clock is stopped, in the words of whoever stopped it.
    $pause = $events->where('kind', 'paused')->last();
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[13px] font-semibold uppercase tracking-wide text-muted">{{ $ticket->reference }}</p>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $ticket->subject }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $ticket->contact?->name ?? 'No customer on file' }}
                · {{ $ticket->state()['label'] }}
                · {{ $ticket->priorityLabel() }}
                @if ($ticket->asset) · {{ $ticket->asset->name }} @endif
            </p>
        </div>

        <a href="{{ route('service') }}" wire:navigate class="{{ $pill }} shrink-0">Queue</a>
    </div>

    @if ($ticket->description)
        <div class="mt-5 rounded-2xl border border-border p-4 text-[14.5px] leading-relaxed text-ink-2">
            {{ $ticket->description }}
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-3">

        {{-- ───────────────────────────────────────────────── the clock ── --}}
        <div class="rounded-2xl border border-border p-4">
            <p class="text-[13px] font-semibold uppercase tracking-wide text-muted">What we promised</p>

            @if ($ticket->policy === null)
                <p class="mt-2 text-[14.5px] text-ink-2">
                    No policy attached, so there is no deadline on this one.
                </p>
            @else
                <p class="mt-2 text-[14px] text-muted">{{ $ticket->policy->name }}</p>

                {{-- Instants, not a countdown: these were worked out in working
                     hours against the policy's calendar, and a ticking clock
                     would be counting hours the business is shut. --}}
                <dl class="mt-3 space-y-2 text-[14.5px]">
                    <div>
                        <dt class="text-muted">Answer by</dt>
                        <dd class="{{ $ticket->hasBreachedResponse() ? 'font-semibold text-rose-600' : 'text-ink' }}">
                            {{ $ticket->response_due_at?->toDayDateTimeString() ?? 'Nothing promised' }}
                            @if ($ticket->first_response_at)
                                <span class="block text-[13px] text-muted">
                                    Answered {{ $ticket->first_response_at->toDayDateTimeString() }}
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted">Fixed by</dt>
                        <dd class="{{ $ticket->hasBreachedResolution() ? 'font-semibold text-rose-600' : 'text-ink' }}">
                            {{ $ticket->resolution_due_at?->toDayDateTimeString() ?? 'Nothing promised' }}
                            @if ($ticket->resolved_at)
                                <span class="block text-[13px] text-muted">
                                    Resolved {{ $ticket->resolved_at->toDayDateTimeString() }}
                                </span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($ticket->paused_at)
                    <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-[14px] text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                        Clock stopped since {{ $ticket->paused_at->toDayDateTimeString() }}
                        @if ($pause?->note) — {{ $pause->note }} @endif
                    </p>
                @endif

                @if ($ticket->paused_minutes > 0)
                    <p class="mt-2 text-[13.5px] text-muted">
                        {{ $ticket->paused_minutes }} working minutes have been credited back to both deadlines.
                    </p>
                @endif
            @endif
        </div>

        {{-- ──────────────────────────────────────────────── what to do ── --}}
        <div class="rounded-2xl border border-border p-4 lg:col-span-2">
            <p class="text-[13px] font-semibold uppercase tracking-wide text-muted">Move it on</p>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @can('service.assign')
                    <div>
                        <label class="{{ $labelClass }}">Whose it is</label>
                        <select wire:model="assigneeId" class="{{ $inputClass }}">
                            <option value="">Nobody</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </select>
                        @error('assigneeId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="assign" class="{{ $pill }} mt-2">Assign</button>
                    </div>
                @endcan

                @can('service.update')
                    <div>
                        <label class="{{ $labelClass }}">How urgent</label>
                        <select wire:model="priority" class="{{ $inputClass }}">
                            @foreach ($priorities as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('priority') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="reprioritise" class="{{ $pill }} mt-2">Change priority</button>
                    </div>
                @endcan
            </div>

            @can('service.update')
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($ticket->first_response_at === null)
                        <button type="button" wire:click="recordResponse" class="{{ $primary }}">We answered them</button>
                    @endif

                    @if ($ticket->isPaused())
                        <button type="button" wire:click="resume" class="{{ $pill }}">Customer came back</button>
                    @elseif ($ticket->isOpen())
                        <button type="button" wire:click="waitOnCustomer" class="{{ $pill }}">Waiting on customer</button>
                    @endif

                    @if ($ticket->isOpen())
                        <button type="button" wire:click="resolve" class="{{ $pill }}">Resolve</button>
                    @endif

                    @if ($ticket->status !== 'closed')
                        <button type="button" wire:click="close" class="{{ $pill }}">Close</button>
                    @else
                        <button type="button" wire:click="reopen" class="{{ $pill }}">Reopen</button>
                    @endif
                </div>

                @error('holdNote') <p class="mt-2 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
                @error('resolution') <p class="mt-2 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}">Why we are waiting</label>
                        <input type="text" wire:model="holdNote" placeholder="Asked for the serial number" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">What fixed it</label>
                        <input type="text" wire:model="resolution" placeholder="Replaced the starter relay" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="mt-3">
                    <label class="{{ $labelClass }}">Add a note</label>
                    <div class="flex gap-2">
                        <input type="text" wire:model="note" placeholder="Anything worth recording" class="{{ $inputClass }}">
                        <button type="button" wire:click="addNote" class="{{ $pill }} shrink-0">Note it</button>
                    </div>
                    @error('note') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
            @endcan
        </div>
    </div>

    {{-- ──────────────────────────────────────────────────────── visits ── --}}
    <div class="mt-6 flex items-center justify-between gap-4">
        <h2 class="text-[18px] font-bold text-ink">Visits</h2>

        @can('service.schedule')
            <button type="button" wire:click="$set('booking', true)" class="{{ $pill }}">Book a visit</button>
        @endcan
    </div>

    @error('jobs')
        <p class="mt-2 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
    @enderror

    @if ($booking)
        @can('service.schedule')
            <div class="mt-3 rounded-2xl border border-border p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="{{ $labelClass }}">Who goes</label>
                        <select wire:model="technicianId" class="{{ $inputClass }}">
                            <option value="">Not decided</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">When</label>
                        <input type="datetime-local" wire:model="scheduledFor" class="{{ $inputClass }}">
                        @error('scheduledFor') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Expected minutes</label>
                        <input type="number" wire:model="estimatedMinutes" placeholder="90" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Chargeable</label>
                        {{-- Warranty and goodwill visits cost the same and earn
                             nothing, and a visit that says otherwise makes every
                             warranty look free. --}}
                        <select wire:model="jobIsBillable" class="{{ $inputClass }}">
                            <option value="1">Yes, bill for it</option>
                            <option value="0">No — warranty or goodwill</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <label class="{{ $labelClass }}">What to do there</label>
                        <input type="text" wire:model="visitNotes" placeholder="Optional" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="bookVisit" class="{{ $primary }}">Book it</button>
                    <button type="button" wire:click="$set('booking', false)" class="{{ $pill }}">Cancel</button>
                </div>
            </div>
        @endcan
    @endif

    <div class="mt-3 space-y-3">
        @forelse ($jobs as $job)
            <div class="rounded-2xl border border-border p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[15.5px] font-semibold text-ink">
                            {{ $job->reference }} · {{ $job->state()['label'] }}
                        </p>
                        <p class="mt-0.5 text-[14px] text-muted">
                            {{ $job->scheduled_for?->toDayDateTimeString() ?? 'No date' }}
                            · {{ $job->technician?->name ?? 'Nobody assigned' }}
                            @unless ($job->is_billable) · not chargeable @endunless
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @can('service.schedule')
                            @if (! $job->isComplete() && $job->status !== 'cancelled')
                                <button type="button" wire:click="startJob('{{ $job->id }}')" class="{{ $pill }}">On site</button>
                                <button type="button" wire:click="cancelJob('{{ $job->id }}')" class="{{ $pill }}">Cancel</button>
                            @endif
                        @endcan

                        @can('service.complete')
                            @if (! $job->isComplete() && $job->status !== 'cancelled')
                                <button type="button" wire:click="$set('completing', '{{ $job->id }}')" class="{{ $pill }}">Done</button>
                            @endif
                            <button type="button" wire:click="$set('timeJob', '{{ $job->id }}')" class="{{ $pill }}">Log time</button>
                            <button type="button" wire:click="$set('partJob', '{{ $job->id }}')" class="{{ $pill }}">Add a part</button>
                        @endcan

                        {{-- A separate ability on purpose: the technician says the
                             machine runs, somebody else decides what it is worth. --}}
                        @can('service.bill')
                            @if ($job->isComplete() && ! $job->isBilled() && $job->is_billable)
                                <button type="button" wire:click="bill('{{ $job->id }}')" class="{{ $primary }}">Draft the invoice</button>
                            @endif
                        @endcan
                    </div>
                </div>

                @if ($job->isBilled())
                    {{-- The link and nothing else. There is one invoice generator
                         in this product and this screen is not it. --}}
                    <p class="mt-3 text-[14px] text-ink-2">
                        Drafted on
                        <a href="{{ route('documents.show', $job->document_id) }}" wire:navigate
                           class="font-semibold underline">an invoice</a>,
                        which still has to be checked and issued.
                    </p>
                @endif

                @if ($completing === $job->id)
                    <div class="mt-3 flex gap-2">
                        <input type="text" wire:model="completionNotes" placeholder="What was done on site" class="{{ $inputClass }}">
                        <button type="button" wire:click="completeJob('{{ $job->id }}')" class="{{ $primary }} shrink-0">
                            Complete
                        </button>
                    </div>
                @endif

                @if ($timeJob === $job->id)
                    <div class="mt-3 flex gap-2">
                        <input type="number" step="0.25" wire:model="hours" placeholder="Hours" class="{{ $inputClass }}">
                        <input type="text" wire:model="timeNotes" placeholder="Optional note" class="{{ $inputClass }}">
                        <button type="button" wire:click="logTime('{{ $job->id }}')" class="{{ $pill }} shrink-0">Log</button>
                    </div>
                    @error('hours') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                @endif

                @if ($partJob === $job->id)
                    <div class="mt-3 flex gap-2">
                        <input type="text" wire:model="partDescription" placeholder="Starter relay" class="{{ $inputClass }}">
                        <input type="number" step="0.001" wire:model="partQuantity" class="{{ $inputClass }}">
                        <input type="number" step="0.01" wire:model="partPrice" class="{{ $inputClass }}">
                        <button type="button" wire:click="addPart('{{ $job->id }}')" class="{{ $pill }} shrink-0">Add</button>
                    </div>
                    @error('partDescription') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                @endif

                @if ($job->timeEntries->isNotEmpty())
                    <p class="mt-4 text-[13px] font-semibold uppercase tracking-wide text-muted">Time</p>
                    <ul class="mt-1 space-y-1 text-[14px] text-ink-2">
                        @foreach ($job->timeEntries as $entry)
                            <li>
                                {{ $entry->hours }} h — {{ $entry->user?->name ?? 'somebody' }}
                                @if ($entry->notes) · {{ $entry->notes }} @endif
                                @if ($entry->locked_at) <span class="text-muted">(billed, locked)</span> @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($job->parts->isNotEmpty())
                    <p class="mt-4 text-[13px] font-semibold uppercase tracking-wide text-muted">Parts</p>
                    <ul class="mt-1 space-y-1 text-[14px] text-ink-2">
                        @foreach ($job->parts as $part)
                            <li>
                                {{ rtrim(rtrim((string) $part->quantity, '0'), '.') }} × {{ $part->label() }}
                                @unless ($part->is_billable) <span class="text-muted">(not charged)</span> @endunless
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($job->on_site_notes)
                    <p class="mt-3 text-[14px] text-muted">{{ $job->on_site_notes }}</p>
                @endif
            </div>
        @empty
            <p class="rounded-2xl border border-border px-4 py-8 text-center text-muted">
                Nobody has been out yet.
            </p>
        @endforelse
    </div>

    {{-- ─────────────────────────────────────────────── what happened ── --}}
    <h2 class="mt-6 text-[18px] font-bold text-ink">What happened</h2>

    <ul class="mt-3 space-y-2 text-[14.5px]">
        @foreach ($events->sortByDesc('occurred_at') as $event)
            <li class="rounded-xl border border-border px-4 py-3">
                <p class="text-ink">
                    <span class="font-semibold">{{ Str::headline($event->kind) }}</span>
                    @if ($event->from_status && $event->to_status && $event->from_status !== $event->to_status)
                        <span class="text-muted">{{ $event->from_status }} → {{ $event->to_status }}</span>
                    @endif
                </p>
                <p class="mt-0.5 text-[13.5px] text-muted">
                    {{ $event->occurred_at->toDayDateTimeString() }}
                    · {{ $event->user?->name ?? 'the system' }}
                    @if ($event->clock_minutes !== null)
                        · {{ $event->clock_minutes }} working minutes back on the clock
                    @endif
                </p>
                @if ($event->note)
                    <p class="mt-1 text-[14px] text-ink-2">{{ $event->note }}</p>
                @endif
            </li>
        @endforeach
    </ul>
</div>
