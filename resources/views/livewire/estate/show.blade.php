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
    @error('review') <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
    @error('propertyExpense') <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
    @error('payout') <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror

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
                            @can('estate.manage')
                                <button type="button" wire:click="startRentReview('{{ $tenancy->id }}')"
                                        class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                                    Review the rent
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

                {{-- ── a rent review ── --}}
                @if ($tenancy && $reviewTenancyId === $tenancy->id)
                    <div class="mt-4 border-t border-border pt-4">
                        <p class="text-[14.5px] font-semibold text-ink">
                            Review the rent (now {{ $money($tenancy->rent) }}/month)
                        </p>
                        <div class="mt-3 grid gap-4 lg:grid-cols-3">
                            <div>
                                <label class="{{ $labelClass }}">New rent / month</label>
                                <input type="number" wire:model="newRent" class="{{ $inputClass }}">
                                @error('newRent') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Effective from</label>
                                <input type="date" wire:model="rentEffectiveOn" class="{{ $inputClass }}">
                                <p class="mt-1 text-[12.5px] text-muted">Never backdated — issued invoices stand as they are.</p>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Why?</label>
                                <input type="text" wire:model="rentReason" placeholder="Annual review, renovation…" class="{{ $inputClass }}">
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="reviewRent" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Change the rent</button>
                            <button type="button" wire:click="$set('reviewTenancyId', null)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                        </div>
                        @if ($tenancy->rentChanges->isNotEmpty())
                            <div class="mt-3 text-[13px] text-muted">
                                @foreach ($tenancy->rentChanges as $change)
                                    <p>{{ $change->effective_on->toFormattedDateString() }}:
                                        {{ $money($change->rent_before) }} → {{ $money($change->rent_after) }}
                                        {{ $change->reason ? '— '.$change->reason : '' }}</p>
                                @endforeach
                            </div>
                        @endif
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
                        {{ $ticket->reference }}
                        @if ($ticket->property_unit_id && $unitLabels->has($ticket->property_unit_id))
                            · {{ $unitLabels[$ticket->property_unit_id] }}
                        @endif
                        · {{ $ticket->contact?->displayName() ?? $ticket->visitor_name ?? '—' }}
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

    {{-- ─────────────────────────────────────────────── the landlord's money ── --}}
    @if ($statement !== null)
        <div class="mt-6 rounded-2xl border border-border bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-[17px] font-bold text-ink">Landlord statement — {{ $property->landlord->displayName() }}</h2>
                    <p class="mt-0.5 text-[13.5px] text-muted">
                        Rent collected, minus
                        {{ $property->commission_percent !== null ? rtrim(rtrim(number_format((float) $property->commission_percent, 2), '0'), '.').'% commission' : 'no commission' }},
                        minus what was spent on the building and paid out already.
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    @can('estate.manage')
                        <button type="button" wire:click="startRecordingExpense"
                                class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                            Record a property expense
                        </button>
                    @endcan
                    @can('estate.end-tenancy')
                        <button type="button" wire:click="startPayingOut"
                                class="tap focusable rounded-full bg-fill-brand px-4 py-2 text-[13.5px] font-semibold text-white">
                            Pay the landlord
                        </button>
                    @endcan
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="{{ $labelClass }}">From</label>
                    <input type="date" wire:model.live="statementFrom" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">To</label>
                    <input type="date" wire:model.live="statementTo" class="{{ $inputClass }}">
                </div>
            </div>

            @if ($recordingExpense)
                <div class="mt-4 border-t border-border pt-4">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div>
                            <label class="{{ $labelClass }}">What was spent on?</label>
                            <input type="text" wire:model="expenseDescription" placeholder="Plumbing repair, guard, water bill…" class="{{ $inputClass }}">
                            @error('expenseDescription') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Amount</label>
                            <input type="number" wire:model="expenseAmount" class="{{ $inputClass }}">
                            @error('expenseAmount') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Paid from</label>
                            <select wire:model="expensePaidFrom" class="{{ $inputClass }}">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank</option>
                                <option value="mobile_money">Mobile money</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <button type="button" wire:click="recordExpense" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Record it</button>
                        <button type="button" wire:click="$set('recordingExpense', false)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                    </div>
                </div>
            @endif

            @if ($payingOut)
                <div class="mt-4 border-t border-border pt-4">
                    <p class="text-[14.5px] font-semibold text-ink">
                        Owed for this period: {{ $money($statement['closing_balance']) }}
                    </p>
                    <div class="mt-3 grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}">Amount (leave empty to pay out everything owed)</label>
                            <input type="number" wire:model="payoutAmount" class="{{ $inputClass }}">
                            @error('payoutAmount') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="mt-2 text-[12.5px] text-muted">
                        Recorded as an ordinary payable to the landlord — settle it from the expenses screen like any other bill.
                    </p>
                    <div class="mt-4 flex gap-2">
                        <button type="button" wire:click="payOut" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Pay them out</button>
                        <button type="button" wire:click="$set('payingOut', false)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                    </div>
                </div>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-[13.5px]">
                    <thead>
                        <tr class="border-b border-border text-[12.5px] uppercase tracking-wide text-muted">
                            <th class="py-2 pr-3">Date</th>
                            <th class="py-2 pr-3">What</th>
                            <th class="py-2 pr-3 text-right">To landlord</th>
                            <th class="py-2 pr-3 text-right">Deducted</th>
                            <th class="py-2 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-border/60 text-muted">
                            <td class="py-2 pr-3" colspan="4">Opening balance</td>
                            <td class="py-2 text-right font-semibold">{{ $money($statement['opening_balance']) }}</td>
                        </tr>
                        @forelse ($statement['lines'] as $line)
                            <tr class="border-b border-border/60">
                                <td class="py-2 pr-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($line['date'])->toFormattedDateString() }}</td>
                                <td class="py-2 pr-3">{{ $line['description'] }}{{ $line['reference'] ? ' · '.$line['reference'] : '' }}</td>
                                <td class="py-2 pr-3 text-right">{{ $line['credit'] > 0 ? $money($line['credit']) : '' }}</td>
                                <td class="py-2 pr-3 text-right">{{ $line['debit'] > 0 ? $money($line['debit']) : '' }}</td>
                                <td class="py-2 text-right">{{ $money($line['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-4 text-center text-muted" colspan="5">Nothing moved in this period.</td></tr>
                        @endforelse
                        <tr>
                            <td class="py-2 pr-3 font-bold text-ink" colspan="4">Owed to the landlord</td>
                            <td class="py-2 text-right font-bold text-ink">{{ $money($statement['closing_balance']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
