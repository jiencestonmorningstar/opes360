@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $toneClass = [
        'bad' => 'text-rose-600 dark:text-rose-400',
        'warn' => 'text-amber-700 dark:text-amber-300',
        'ok' => 'text-ink-2',
        'muted' => 'text-muted',
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Service desk</h1>
            <p class="mt-1 text-[14.5px] text-muted">Who is waiting, and what we promised them.</p>
        </div>

        <div class="flex shrink-0 gap-2">
            @can('service.manage-sla')
                <a href="{{ route('service.sla') }}" wire:navigate
                   class="tap focusable rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                    Promises
                </a>
            @endcan

            @can('service.create')
                <button type="button" wire:click="$set('raising', true)"
                        class="tap focusable rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white">
                    Raise a ticket
                </button>
            @endcan
        </div>
    </div>

    {{-- ────────────────────────────────────── what is going wrong now ── --}}
    <div class="mt-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            'On the clock' => $summary['open'],
            'Nobody assigned' => $summary['unassigned'],
            'Unanswered' => $summary['awaiting_response'],
            'Waiting on customer' => $summary['waiting_on_customer'],
            'Response breached' => $summary['response_breached'],
            'Fix breached' => $summary['resolution_breached'],
        ] as $label => $value)
            <div class="rounded-2xl border border-border p-3.5">
                <p class="text-[13px] font-semibold text-muted">{{ $label }}</p>
                <p class="mt-1 text-[22px] font-bold text-ink">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if ($breaching->isNotEmpty())
        <div class="mt-5 rounded-2xl border border-rose-300/60 bg-rose-50 px-4 py-3 dark:border-rose-500/30 dark:bg-rose-500/10">
            <p class="text-[14.5px] font-semibold text-rose-900 dark:text-rose-200">
                {{ $breaching->count() }} {{ Str::plural('ticket', $breaching->count()) }} past what we promised
            </p>
            <ul class="mt-2 space-y-1 text-[14px] text-rose-900 dark:text-rose-200">
                @foreach ($breaching as $ticket)
                    <li>
                        <a href="{{ route('service.show', $ticket) }}" wire:navigate class="font-semibold underline">
                            {{ $ticket->reference }}
                        </a>
                        — {{ $ticket->subject }}
                        <span class="text-[13.5px]">
                            ({{ $ticket->contact?->name ?? 'no customer' }},
                            {{ $ticket->assignee?->name ?? 'unassigned' }})
                        </span>
                        <span class="block text-[13px]">{{ $this->slaState($ticket)['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($atRisk->isNotEmpty())
        <div class="mt-3 rounded-2xl border border-amber-300/60 bg-amber-50 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
            {{-- Four *working* hours, walked through each policy's calendar. --}}
            <p class="text-[14.5px] font-semibold text-amber-900 dark:text-amber-200">
                {{ $atRisk->count() }} due within four working hours
            </p>
            <ul class="mt-2 space-y-1 text-[14px] text-amber-900 dark:text-amber-200">
                @foreach ($atRisk as $ticket)
                    <li>
                        <a href="{{ route('service.show', $ticket) }}" wire:navigate class="font-semibold underline">
                            {{ $ticket->reference }}
                        </a>
                        — {{ $ticket->subject }}
                        <span class="block text-[13px]">{{ $this->slaState($ticket)['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────── raise a ticket ── --}}
    @if ($raising)
        @can('service.create')
            <div class="mt-5 rounded-2xl border border-border p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="{{ $labelClass }}">Customer</label>
                        <select wire:model="contactId" class="{{ $inputClass }}">
                            <option value="">Nobody in particular</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="{{ $labelClass }}">What is wrong</label>
                        <input type="text" wire:model="subject" placeholder="Generator will not start" class="{{ $inputClass }}">
                        @error('subject') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">How urgent</label>
                        <select wire:model="newPriority" class="{{ $inputClass }}">
                            @foreach ($priorities as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">How it reached us</label>
                        <select wire:model="channel" class="{{ $inputClass }}">
                            @foreach ($channels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Kind</label>
                        <input type="text" wire:model="category" placeholder="Optional" class="{{ $inputClass }}">
                    </div>
                    @can('service.assign')
                        <div>
                            <label class="{{ $labelClass }}">Give it to</label>
                            <select wire:model="newAssignee" class="{{ $inputClass }}">
                                <option value="">Nobody yet</option>
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endcan
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="{{ $labelClass }}">Detail</label>
                        <textarea wire:model="description" rows="3" placeholder="Optional"
                                  class="w-full rounded-xl border border-border bg-surface px-3.5 py-2.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                    </div>
                </div>

                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="raise"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Raise it
                    </button>
                    <button type="button" wire:click="$set('raising', false)"
                            class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                        Cancel
                    </button>
                </div>
            </div>
        @endcan
    @endif

    {{-- ────────────────────────────────────────────────────── filters ── --}}
    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Reference or subject" class="{{ $inputClass }}">

        <select wire:model.live="status" class="{{ $inputClass }}">
            <option value="">Any status</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <select wire:model.live="priority" class="{{ $inputClass }}">
            <option value="">Any priority</option>
            @foreach ($priorities as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <select wire:model.live="assignee" class="{{ $inputClass }}">
            <option value="">Anybody</option>
            <option value="unassigned">Nobody</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>
    </div>

    @error('acting')
        <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
    @enderror

    {{-- ──────────────────────────────────────────────────────── queue ── --}}
    <div class="mt-4 overflow-hidden rounded-2xl border border-border">
        <table class="w-full text-left text-[14.5px]">
            <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                <tr>
                    <th class="px-4 py-3">Ticket</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Priority</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Promise</th>
                    <th class="px-4 py-3">Who</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    @php $sla = $this->slaState($ticket); @endphp
                    <tr class="border-t border-border">
                        <td class="px-4 py-3">
                            <a href="{{ route('service.show', $ticket) }}" wire:navigate
                               class="font-semibold text-ink underline decoration-border underline-offset-4">
                                {{ $ticket->reference }}
                            </a>
                            <span class="block text-[13px] text-muted">{{ $ticket->subject }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-2">{{ $ticket->contact?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $ticket->priorityLabel() }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $ticket->state()['label'] }}</td>
                        <td class="px-4 py-3 {{ $toneClass[$sla['tone']] }}">{{ $sla['label'] }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                        <td class="px-4 py-3 text-right">
                            @canany(['service.assign', 'service.update'])
                                <button type="button" wire:click="startAction('{{ $ticket->id }}')"
                                        class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                    Hand over
                                </button>
                            @endcanany
                        </td>
                    </tr>

                    @if ($acting === $ticket->id)
                        <tr class="border-t border-border bg-fill-2">
                            <td colspan="7" class="px-4 py-4">
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    @can('service.assign')
                                        <div>
                                            <label class="{{ $labelClass }}">Give it to</label>
                                            <select wire:model="actingAssignee" class="{{ $inputClass }}">
                                                <option value="">Nobody</option>
                                                @foreach ($people as $person)
                                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="assign"
                                                    class="tap focusable mt-2 rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white">
                                                Assign
                                            </button>
                                        </div>
                                    @endcan

                                    @can('service.update')
                                        <div>
                                            <label class="{{ $labelClass }}">Priority</label>
                                            <select wire:model="actingPriority" class="{{ $inputClass }}">
                                                @foreach ($priorities as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="reprioritise"
                                                    class="tap focusable mt-2 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                                                Change it
                                            </button>
                                            {{-- The new deadline is measured from when the ticket was
                                                 raised, not from now, so escalating cannot clear a breach. --}}
                                            <p class="mt-1.5 text-[13px] text-muted">
                                                Counted from when it was raised.
                                            </p>
                                        </div>
                                    @endcan

                                    <div class="lg:col-span-2">
                                        <button type="button" wire:click="$set('acting', null)"
                                                class="tap focusable rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                                            Done
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-muted">
                            Nothing here. Either nobody has a problem, or nobody has told you yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tickets->links() }}</div>
</div>
