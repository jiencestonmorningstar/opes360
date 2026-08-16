@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $state = $policy->state();
    $ccy = $policy->currency;
    $money = fn ($amount) => $amount === null ? '—' : number_format((float) $amount).' '.$ccy;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('insurance') }}" wire:navigate class="text-[13.5px] font-semibold text-muted hover:text-ink">← Insurance</a>

            <h1 class="mt-1 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $policy->label() }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $policy->policy_number ?? 'No policy number yet' }} ·
                {{ $policy->insurer?->displayName() ?? 'No insurer on record' }}
            </p>
        </div>

        <x-ui.status-badge class="mt-2 shrink-0" :label="$state['label']" :tone="$state['tone']" />
    </div>

    @error('policy')
        <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
    @enderror

    {{-- Cover leads, above the money: it is the only thing on this screen that
         stops protecting the client while you are looking at something else. --}}
    @if ($policy->covers_to)
        @php $days = $policy->daysOfCover(); @endphp
        <div class="mt-5 rounded-2xl border px-4 py-3.5 text-[14.5px]
                    {{ $policy->coverLapsed()
                        ? 'border-rose-400 bg-rose-50 text-rose-800 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200'
                        : 'border-amber-300/60 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200' }}">
            <strong>Cover to {{ $policy->covers_to->toFormattedDateString() }}</strong>
            @if ($policy->coverLapsed())
                — that was {{ abs($days) }} {{ Str::plural('day', abs($days)) }} ago.
                @if ($policy->autoRenews())
                    This policy renews itself, so the client is on risk for a term nobody agreed and nobody has invoiced.
                @else
                    The client may be uninsured today.
                @endif
            @elseif ($days !== null)
                — {{ $days === 0 ? 'ends today' : $days.' '.Str::plural('day', $days).' left' }}.
                @if ($policy->notice_by)
                    Notice by {{ $policy->notice_by->toFormattedDateString() }}.
                @endif
            @endif
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <x-ui.panel title="The cover">
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Policyholder</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">{{ $policy->holder?->displayName() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Runs</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">
                            {{ $policy->covers_from?->toFormattedDateString() ?? '—' }}
                            → {{ $policy->covers_to?->toFormattedDateString() ?? 'open cover' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Premium</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">{{ $money($policy->premium) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Commission</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">
                            {{ $policy->commission_percent === null ? 'Not agreed' : rtrim(rtrim((string) $policy->commission_percent, '0'), '.').'%' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Renewal</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">{{ $policy->renewalLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="text-[13px] font-semibold text-ink-2">Owner</dt>
                        <dd class="mt-0.5 text-[15px] text-ink">{{ $policy->owner?->name ?? 'Nobody' }}</dd>
                    </div>
                    @if ($policy->status === 'cancelled')
                        <div>
                            <dt class="text-[13px] font-semibold text-ink-2">Cancelled</dt>
                            <dd class="mt-0.5 text-[15px] text-ink">
                                {{ $policy->cancelled_on?->toFormattedDateString() }}
                                @if ($policy->cancellation_reason)
                                    <span class="block text-[13.5px] text-muted">{{ $policy->cancellation_reason }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
                    @can('insurance.manage')
                        @if ($policy->status === 'draft')
                            <button type="button" wire:click="bind"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Bind cover
                            </button>
                        @endif
                        @if ($policy->isActive())
                            <button type="button" wire:click="invoicePremium"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Invoice the premium
                            </button>
                            <button type="button" wire:click="recordCommission"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Record commission
                            </button>
                            <button type="button" wire:click="startClaiming"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Notify a claim
                            </button>
                            <button type="button" wire:click="startCancelling"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-rose-600">
                                Cancel
                            </button>
                        @endif
                    @endcan
                </div>

                @if ($cancelling)
                    <div class="mt-4 rounded-xl border border-border p-4">
                        @error('cancelling') <p class="mb-2 text-[13.5px] font-semibold text-rose-600">{{ $message }}</p> @enderror
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}">Cancelled on</label>
                                <input type="date" wire:model="cancelledOn" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Why</label>
                                <input type="text" wire:model="cancellationReason" class="{{ $inputClass }}">
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="cancel" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Record it</button>
                            <button type="button" wire:click="$set('cancelling', false)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Keep the cover</button>
                        </div>
                    </div>
                @endif
            </x-ui.panel>

            {{-- ─────────────────────────────────────────────────── claims ── --}}
            <x-ui.panel title="Claims">
                @error('claims')
                    <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                @enderror

                @if ($claiming)
                    <div class="mb-4 rounded-xl border border-border p-4">
                        @error('claiming') <p class="mb-2 text-[13.5px] font-semibold text-rose-600">{{ $message }}</p> @enderror
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}">Incident date</label>
                                <input type="date" wire:model="incidentOn" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Amount claimed</label>
                                <input type="number" step="0.01" wire:model="claimedAmount" placeholder="{{ $ccy }}" class="{{ $inputClass }}">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="{{ $labelClass }}">What happened</label>
                                <input type="text" wire:model="claimDescription" class="{{ $inputClass }}">
                                @error('claimDescription') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="openClaim" class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">Notify it</button>
                            <button type="button" wire:click="$set('claiming', false)" class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">Cancel</button>
                        </div>
                    </div>
                @endif

                @forelse ($claims as $claim)
                    @php $cState = $claim->state(); @endphp
                    <div wire:key="claim-{{ $claim->id }}"
                         class="{{ $loop->first ? '' : 'mt-3 border-t border-border pt-3' }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-[14.5px] font-semibold text-ink">
                                {{ $claim->incident_on->toFormattedDateString() }}
                                @if ($claim->claim_number) · {{ $claim->claim_number }} @endif
                            </p>
                            <x-ui.status-badge :label="$cState['label']" :tone="$cState['tone']" />
                        </div>
                        <p class="mt-0.5 text-[13.5px] text-ink-2">{{ $claim->description }}</p>
                        <p class="mt-0.5 text-[13px] text-muted">
                            @if ($claim->claimed_amount) Claimed {{ $money($claim->claimed_amount) }}. @endif
                            @if ($claim->isSettled()) Settled at {{ $money($claim->settled_amount) }} on {{ $claim->settled_on->toFormattedDateString() }}. @endif
                            @if ($claim->status === 'rejected' && $claim->rejection_reason) {{ $claim->rejection_reason }} @endif
                            @if ($claim->isAwaitingApproval()) Settlement of {{ $money($claim->settled_amount) }} is with an approver. @endif
                            @if ($claim->papers()->isNotEmpty()) {{ $claim->papers()->count() }} {{ Str::plural('document', $claim->papers()->count()) }} on file. @endif
                        </p>

                        {{-- No approve button, on purpose: approving happens in /actions. --}}
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @can('insurance.manage')
                                @if ($claim->status === 'fnol')
                                    <button type="button" wire:click="assessClaim('{{ $claim->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13px] font-semibold text-ink-2">
                                        Mark assessed
                                    </button>
                                @endif
                            @endcan

                            @if ($claim->status === 'assessed' && ! $claim->isAwaitingApproval())
                                <input type="number" step="0.01" wire:model="settlementAmount" placeholder="Settlement {{ $ccy }}"
                                       class="h-9 w-40 rounded-full border border-border bg-surface px-3 text-[13px] text-ink">
                                @can('insurance.manage')
                                    <button type="button" wire:click="submitSettlement('{{ $claim->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13px] font-semibold text-ink-2">
                                        Send settlement for approval
                                    </button>
                                @endcan
                                @can('insurance.settle')
                                    <button type="button" wire:click="settleClaim('{{ $claim->id }}')"
                                            class="tap focusable rounded-full bg-fill-brand px-3.5 py-1.5 text-[13px] font-semibold text-white">
                                        Settle
                                    </button>
                                    <button type="button" wire:click="rejectClaim('{{ $claim->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13px] font-semibold text-rose-600">
                                        Reject
                                    </button>
                                @endcan
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-[13.5px] text-muted">No claims under this policy.</p>
                @endforelse
            </x-ui.panel>
        </div>

        <div class="space-y-4">
            {{-- ─────────────────────────────────── the money, both ways ── --}}
            <x-ui.panel title="Premium invoices">
                <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                    Ordinary sales invoices — issued, chased and receipted where every other invoice is.
                </p>
                @forelse ($invoices as $invoice)
                    <div wire:key="inv-{{ $invoice->id }}"
                         class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }} flex items-center justify-between gap-2">
                        <a href="{{ route('documents.show', $invoice) }}" wire:navigate class="text-[14px] font-semibold text-ink hover:underline">
                            {{ $invoice->number ?? 'Draft' }}
                        </a>
                        <span class="text-[13px] text-muted">
                            {{ number_format((float) $invoice->total) }} {{ $invoice->currency }} ·
                            {{ (float) $invoice->balance <= 0 ? 'paid' : number_format((float) $invoice->balance).' owing' }}
                        </span>
                    </div>
                @empty
                    <p class="py-4 text-center text-[13.5px] text-muted">No premium invoiced yet.</p>
                @endforelse
            </x-ui.panel>

            <x-ui.panel title="Commission">
                <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                    What the insurer owes for placing this — a receivable, invoiced like any other.
                </p>
                @forelse ($commissions as $commission)
                    <div wire:key="comm-{{ $commission->id }}"
                         class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[14px] font-semibold text-ink">{{ $money($commission->amount) }}</span>
                            <span class="text-[13px] text-muted">earned {{ $commission->earned_on->toFormattedDateString() }}</span>
                        </div>
                        <div class="mt-1">
                            @if ($commission->isInvoiced())
                                <a href="{{ route('documents.show', $commission->invoice) }}" wire:navigate class="text-[13px] text-ink-2 hover:underline">
                                    On invoice {{ $commission->invoice?->number ?? '(draft)' }}
                                </a>
                            @else
                                @can('insurance.manage')
                                    <button type="button" wire:click="invoiceCommission('{{ $commission->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13px] font-semibold text-ink-2">
                                        Invoice the insurer
                                    </button>
                                @endcan
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-4 text-center text-[13.5px] text-muted">Nothing recorded yet.</p>
                @endforelse
            </x-ui.panel>

            <x-ui.panel title="Papers">
                <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                    The schedule and the wording live in Documents and are linked here.
                </p>
                @forelse ($papers as $paper)
                    <div wire:key="paper-{{ $paper->id }}"
                         class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                        <span class="text-[14px] text-ink">{{ $paper->title ?? $paper->reference }}</span>
                    </div>
                @empty
                    <p class="py-4 text-center text-[13.5px] text-muted">No papers linked yet.</p>
                @endforelse
            </x-ui.panel>
        </div>
    </div>
</div>
