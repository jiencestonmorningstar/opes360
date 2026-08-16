@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $money = fn ($amount) => $amount === null ? '—' : number_format((float) $amount).' '.$currency;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('estate') }}" wire:navigate class="text-[13.5px] font-semibold text-muted hover:underline">← Properties</a>
            <h1 class="mt-1 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $property->name }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $property->kindLabel() }}{{ $property->address ? ' · '.$property->address : '' }}
                · {{ $property->landlord?->displayName() ?? 'Own building' }}
            </p>
        </div>

        @can('estate.manage')
            <button type="button" wire:click="startAddingUnit"
                    class="tap focusable shrink-0 rounded-full bg-fill-brand px-5 py-2.5 text-[14.5px] font-semibold text-white">
                Add a unit
            </button>
        @endcan
    </div>

    @error('letting') <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
    @error('ending') <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
    @error('maintenance') <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror

    {{-- ─────────────────────────────────────────────────────── adding a unit ── --}}
    @if ($addingUnit)
        <div class="mt-5 rounded-2xl border border-border bg-surface p-5">
            <h2 class="text-[17px] font-bold text-ink">Add a unit</h2>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">Label</label>
                    <input type="text" wire:model="unitLabel" placeholder="Apartment 2B" class="{{ $inputClass }}">
                    @error('unitLabel') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Target rent / month</label>
                    <input type="number" wire:model="unitTargetRent" class="{{ $inputClass }}">
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="addUnit" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Add it</button>
                <button type="button" wire:click="$set('addingUnit', false)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
            </div>
        </div>
    @endif

    {{-- ──────────────────────────────────────────────────────────── the doors ── --}}
    <div class="mt-5 space-y-3">
        @forelse ($property->units as $unit)
            @php
                $tenancy = $unit->currentTenancy;
                $state = $unit->state();
                $owed = $tenancy ? ($arrears[$tenancy->id] ?? 0) : 0;
            @endphp
            <div wire:key="unit-{{ $unit->id }}" class="rounded-2xl border border-border bg-surface p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[16px] font-bold text-ink">
                            {{ $unit->label }}
                            <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />
                        </p>

                        @if ($tenancy)
                            <p class="mt-1 text-[14px] text-ink-2">
                                {{ $tenancy->tenant?->displayName() }} · {{ $money($tenancy->rent) }}/month
                                · in since {{ $tenancy->moved_in_on->toFormattedDateString() }}
                                @if ($tenancy->deposit_amount > 0)
                                    · deposit {{ $money($tenancy->deposit_amount) }} held
                                @endif
                            </p>
                            @if ($owed > 0)
                                <p class="mt-1 text-[13.5px] font-semibold text-rose-600">Owes {{ $money($owed) }}</p>
                            @endif
                            @if ($tenancy->lease)
                                <p class="mt-0.5 text-[13px] text-muted">
                                    Lease {{ $tenancy->lease->ends_on ? 'ends '.$tenancy->lease->ends_on->toFormattedDateString() : 'is open-ended' }}
                                    @if ($tenancy->lease->notice_by)
                                        · notice by {{ $tenancy->lease->notice_by->toFormattedDateString() }}
                                    @endif
                                </p>
                            @endif
                        @else
                            <p class="mt-1 text-[14px] text-muted">
                                {{ $unit->target_rent !== null ? 'Asking '.$money($unit->target_rent).'/month' : 'No asking rent set' }}
                            </p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if ($tenancy)
                            @can('estate.manage')
                                <button type="button" wire:click="startMaintenance('{{ $tenancy->id }}')"
                                        class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                                    Report a fault
                                </button>
                            @endcan
                            @can('estate.end-tenancy')
                                <button type="button" wire:click="startEnding('{{ $tenancy->id }}')"
                                        class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                                    Move out
                                </button>
                            @endcan
                        @elseif ($unit->isVacant())
                            @can('estate.manage')
                                <button type="button" wire:click="startLetting('{{ $unit->id }}')"
                                        class="tap focusable rounded-full bg-fill-brand px-4 py-2 text-[13.5px] font-semibold text-white">
                                    Move a tenant in
                                </button>
                            @endcan
                        @endif
                    </div>
                </div>

                {{-- ── moving in ── --}}
                @if ($lettingUnitId === $unit->id)
                    <div class="mt-4 border-t border-border pt-4">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <div>
                                <label class="{{ $labelClass }}">Tenant</label>
                                <select wire:model="tenantId" class="{{ $inputClass }}">
                                    <option value="">Choose…</option>
                                    @foreach ($tenants as $contact)
                                        <option value="{{ $contact->id }}">{{ $contact->displayName() }}</option>
                                    @endforeach
                                </select>
                                @error('tenantId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Rent / month</label>
                                <input type="number" wire:model="rent" class="{{ $inputClass }}">
                                @error('rent') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Deposit taken</label>
                                <input type="number" wire:model="depositAmount" class="{{ $inputClass }}">
                                <p class="mt-1 text-[12.5px] text-muted">Posted to the books as a liability the day it is taken.</p>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Moving in on</label>
                                <input type="date" wire:model="movedInOn" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Lease ends</label>
                                <input type="date" wire:model="endsOn" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Renewal</label>
                                <select wire:model.live="renewalType" class="{{ $inputClass }}">
                                    <option value="none">Does not renew</option>
                                    <option value="auto">Renews automatically</option>
                                    <option value="manual">Renewed by agreement</option>
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Notice period (days)</label>
                                <input type="number" wire:model="noticePeriodDays" class="{{ $inputClass }}">
                                @if ($renewalType === 'auto')
                                    <p class="mt-1 text-[13px] text-warning">Required — without it nobody can be warned before the lease renews itself.</p>
                                @endif
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="let" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Move them in</button>
                            <button type="button" wire:click="$set('lettingUnitId', null)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                        </div>
                    </div>
                @endif

                {{-- ── moving out ── --}}
                @if ($tenancy && $endingTenancyId === $tenancy->id)
                    <div class="mt-4 border-t border-border pt-4">
                        <p class="text-[14.5px] font-semibold text-ink">
                            Settle the deposit ({{ $money($tenancy->deposit_amount) }} held) and end the tenancy
                        </p>
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}">Amount kept back</label>
                                <input type="number" wire:model.live="retained" class="{{ $inputClass }}">
                                <p class="mt-1 text-[12.5px] text-muted">The rest is refunded from the bank. What is kept becomes income, for the reason you give.</p>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Why is it kept?</label>
                                <input type="text" wire:model="retentionReason" placeholder="Broken window, cleaning…" class="{{ $inputClass }}">
                            </div>
                            @if (($arrears[$tenancy->id] ?? 0) > 0)
                                <div class="lg:col-span-2 rounded-xl bg-rose-50 p-4 dark:bg-rose-500/10">
                                    <label class="flex items-center gap-2 text-[14px] font-semibold text-rose-800 dark:text-rose-200">
                                        <input type="checkbox" wire:model.live="force" class="rounded border-border">
                                        Move them out anyway, owing {{ $money($arrears[$tenancy->id]) }}
                                    </label>
                                    @if ($force)
                                        <input type="text" wire:model="forceReason" placeholder="Why is the balance being let go?"
                                               class="{{ $inputClass }} mt-2">
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="endTenancy" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">End the tenancy</button>
                            <button type="button" wire:click="$set('endingTenancyId', null)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                        </div>
                    </div>
                @endif

                {{-- ── a fault ── --}}
                @if ($tenancy && $maintenanceTenancyId === $tenancy->id)
                    <div class="mt-4 border-t border-border pt-4">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}">What needs fixing?</label>
                                <input type="text" wire:model="maintenanceSubject" placeholder="Leaking tap in the kitchen" class="{{ $inputClass }}">
                                @error('maintenanceSubject') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Details</label>
                                <input type="text" wire:model="maintenanceDescription" class="{{ $inputClass }}">
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="reportMaintenance" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Log it</button>
                            <button type="button" wire:click="$set('maintenanceTenancyId', null)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-2xl border border-border bg-surface p-10 text-center text-muted">
                No units yet. Add the doors this building lets.
            </div>
        @endforelse
    </div>

    {{-- ─────────────────────────────────────────── tickets and the paper trail ── --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-2">
        <x-ui.panel title="Maintenance & service tickets">
            @forelse ($tickets as $ticket)
                @php $tstate = $ticket->state(); @endphp
                <div wire:key="ticket-{{ $ticket->id }}" class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">
                        {{ $ticket->subject }}
                        <x-ui.status-badge :label="$tstate['label']" :tone="$tstate['tone']" />
                    </p>
                    <p class="mt-0.5 text-[13px] text-muted">
                        {{ $ticket->reference }} · {{ $ticket->contact?->displayName() ?? $ticket->visitor_name ?? '—' }}
                        · opened {{ $ticket->opened_at?->toFormattedDateString() }}
                    </p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">Nothing reported for this property.</p>
            @endforelse
        </x-ui.panel>

        <x-ui.panel title="Papers">
            @forelse ($papers as $paper)
                <div wire:key="paper-{{ $paper->id }}" class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">{{ $paper->title ?? $paper->reference }}</p>
                    <p class="mt-0.5 text-[13px] text-muted">{{ $paper->reference }}</p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">
                    No documents filed against this property yet. Link them from the document itself.
                </p>
            @endforelse
        </x-ui.panel>
    </div>
</div>
