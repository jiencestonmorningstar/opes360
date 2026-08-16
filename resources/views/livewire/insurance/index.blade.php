@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $link = fn ($policy) => route('insurance.show', $policy);
    $money = fn ($amount, $ccy) => $amount === null ? '—' : number_format((float) $amount).' '.$ccy;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Insurance</h1>
            <p class="mt-1 text-[14.5px] text-muted">The book of business, and the cover that runs out before anybody agreed a renewal.</p>
        </div>

        @can('insurance.manage')
            <button type="button" wire:click="startPlacing"
                    class="tap focusable shrink-0 rounded-full bg-fill-brand px-5 py-2.5 text-[14.5px] font-semibold text-white">
                Place a policy
            </button>
        @endcan
    </div>

    {{-- ──────────────────────────────────────────────────────── the alarm ── --}}
    {{--
        Not a row in a table. Every policy here has rolled itself into a term
        nobody agreed and nobody has billed — the client is on risk, the
        premium is uncollected, and no approval will ever be asked about
        either. That is the failure this vertical exists to stop, so it is
        allowed to shout.
    --}}
    @if ($lapsedOnAutoRenew->isNotEmpty())
        <div class="mt-5 rounded-2xl border-2 border-rose-400 bg-rose-50 p-5 dark:border-rose-500/50 dark:bg-rose-500/10">
            <p class="text-[16px] font-bold text-rose-800 dark:text-rose-200">
                {{ $lapsedOnAutoRenew->count() }}
                {{ Str::plural('policy', $lapsedOnAutoRenew->count()) }}
                {{ $lapsedOnAutoRenew->count() === 1 ? 'has' : 'have' }} renewed themselves past their cover date
            </p>
            <p class="mt-1 text-[14px] text-rose-700 dark:text-rose-300">
                Cover has rolled into a term nobody agreed. The schedule has not been re-issued and the new premium has not been invoiced.
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($lapsedOnAutoRenew as $policy)
                    <li wire:key="risk-{{ $policy->id }}" class="rounded-xl bg-white/70 px-4 py-3 dark:bg-black/20">
                        <p class="text-[15px] font-semibold text-rose-900 dark:text-rose-100">
                            <a href="{{ $link($policy) }}" wire:navigate class="underline decoration-rose-300 underline-offset-2">{{ $policy->label() }}</a>
                        </p>
                        <p class="mt-0.5 text-[13.5px] text-rose-700 dark:text-rose-300">
                            {{ $policy->insurer?->displayName() ?? 'No insurer on record' }} ·
                            cover ran out {{ $policy->covers_to->toFormattedDateString() }},
                            {{ abs($policy->daysOfCover()) }} {{ Str::plural('day', abs($policy->daysOfCover())) }} ago
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ────────────────────────────────────────────── the two other lists ── --}}
    <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <x-ui.panel title="Cover ending soon">
            <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                You can still call the client. Cover runs out inside the next month and renewal has not been agreed.
            </p>

            @forelse ($lapsing as $policy)
                <div wire:key="lapse-{{ $policy->id }}"
                     class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">
                        <a href="{{ $link($policy) }}" wire:navigate class="hover:underline">{{ $policy->label() }}</a>
                    </p>
                    <p class="mt-0.5 text-[13px] text-warning">
                        @php $days = $policy->daysOfCover(); @endphp
                        {{ $days === 0 ? 'Cover ends today' : $days.' '.Str::plural('day', $days).' of cover left' }}
                        <span class="text-muted">· {{ $policy->insurer?->displayName() ?? 'No insurer' }}</span>
                    </p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">Nothing runs out this month.</p>
            @endforelse
        </x-ui.panel>

        <x-ui.panel title="Cover already lapsed">
            <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                The client may be uninsured today. These need a renewal, a rebroke or an honest cancellation — not a diary note.
            </p>

            @forelse ($lapsed as $policy)
                <div wire:key="gone-{{ $policy->id }}"
                     class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">
                        <a href="{{ $link($policy) }}" wire:navigate class="hover:underline">{{ $policy->label() }}</a>
                    </p>
                    <p class="mt-0.5 text-[13px] text-muted">
                        Cover ran out {{ $policy->covers_to->toFormattedDateString() }} ·
                        {{ $policy->insurer?->displayName() ?? 'No insurer' }}
                    </p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No cover has lapsed.</p>
            @endforelse
        </x-ui.panel>
    </div>

    @if ($summary['open_claims'] > 0)
        <p class="mt-4 text-[13.5px] text-muted">
            {{ $summary['open_claims'] }} open {{ Str::plural('claim', $summary['open_claims']) }} being chased.
        </p>
    @endif

    {{-- ───────────────────────────────────────────────────── place a new ── --}}
    @if ($placing)
        <div class="mt-5 rounded-2xl border border-border p-4">
            @error('placing')
                <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
            @enderror

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="{{ $labelClass }}">Policyholder</label>
                    <select wire:model="holderId" class="{{ $inputClass }}">
                        <option value="">Pick the client</option>
                        @foreach ($contacts as $contact)
                            <option value="{{ $contact->id }}">{{ $contact->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('holderId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Insurer</label>
                    <select wire:model="insurerId" class="{{ $inputClass }}">
                        <option value="">Not placed yet</option>
                        @foreach ($contacts as $contact)
                            <option value="{{ $contact->id }}">{{ $contact->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Product line</label>
                    <select wire:model="productLine" class="{{ $inputClass }}">
                        @foreach (\App\Models\InsurancePolicy::PRODUCT_LINES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Policy number</label>
                    <input type="text" wire:model="policyNumber" placeholder="The insurer's reference" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Premium</label>
                    <input type="number" step="0.01" wire:model="premium" placeholder="{{ $currency }}" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Commission</label>
                    <input type="number" step="0.01" wire:model="commissionPercent" placeholder="%" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Cover from</label>
                    <input type="date" wire:model="coversFrom" class="{{ $inputClass }}">
                    @error('coversFrom') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Cover to</label>
                    <input type="date" wire:model="coversTo" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Renewal</label>
                    <select wire:model.live="renewalType" class="{{ $inputClass }}">
                        @foreach (\App\Models\InsurancePolicy::RENEWAL_TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Notice period</label>
                    <input type="number" wire:model="noticePeriodDays" placeholder="days" class="{{ $inputClass }}">
                    @if ($renewalType === 'auto')
                        <p class="mt-1 text-[13px] text-warning">
                            Required — without it nobody can be warned before this renews itself.
                        </p>
                    @endif
                </div>
            </div>

            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="place"
                        class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                    Place it
                </button>
                <button type="button" wire:click="$set('placing', false)"
                        class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ───────────────────────────────────────────────────────────── tabs ── --}}
    <div class="mt-6 flex gap-2">
        @foreach (['policies' => 'Policies', 'claims' => 'Claims board'] as $value => $label)
            <button type="button" wire:click="$set('tab', '{{ $value }}')"
                    class="focusable flex h-10 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $tab === $value ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @error('register')
        <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
    @enderror

    @if ($tab === 'claims')
        {{-- ─────────────────────────────────────────────── the claims board ── --}}
        <div class="mt-4 grid gap-4 lg:grid-cols-4">
            @foreach (\App\Models\InsuranceClaim::STATUSES as $status => $label)
                <x-ui.panel :title="$label">
                    @forelse ($claims->get($status, collect()) as $claim)
                        <div wire:key="claim-{{ $claim->id }}"
                             class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                            <p class="text-[14px] font-semibold text-ink">
                                <a href="{{ route('insurance.show', $claim->policy) }}" wire:navigate class="hover:underline">
                                    {{ $claim->policy?->label() ?? 'Policy removed' }}
                                </a>
                            </p>
                            <p class="mt-0.5 text-[13px] text-muted">
                                {{ $claim->incident_on->toFormattedDateString() }}
                                @if ($claim->claimed_amount) · claimed {{ number_format((float) $claim->claimed_amount) }} @endif
                                @if ($claim->settled_amount && $claim->isSettled()) · settled {{ number_format((float) $claim->settled_amount) }} @endif
                            </p>
                            <p class="mt-0.5 line-clamp-2 text-[13px] text-ink-2">{{ $claim->description }}</p>
                        </div>
                    @empty
                        <p class="py-4 text-center text-[13px] text-muted">Nothing here.</p>
                    @endforelse
                </x-ui.panel>
            @endforeach
        </div>
    @else
        {{-- ──────────────────────────────────────────────────── the register ── --}}
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <div class="min-w-56 flex-1">
                <label for="policy-search" class="sr-only">Search policies</label>
                <input id="policy-search" wire:model.live.debounce.300ms="search" type="search"
                       placeholder="Search policies…"
                       class="focusable h-11 w-full rounded-full border border-border bg-surface px-4 text-[14.5px] text-ink">
            </div>

            <div class="no-scrollbar flex gap-2 overflow-x-auto">
                @foreach (['' => 'All', 'draft' => 'Draft', 'active' => 'In force', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $value => $label)
                    <button type="button" wire:click="$set('status', '{{ $value }}')"
                            class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                                   {{ $status === $value ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="mt-4 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Policy</th>
                        <th class="px-4 py-3">Insurer</th>
                        <th class="px-4 py-3">Premium</th>
                        <th class="px-4 py-3">Cover to</th>
                        <th class="px-4 py-3">Notice by</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($policies as $policy)
                        @php $state = $policy->state(); @endphp
                        <tr wire:key="row-{{ $policy->id }}" class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">
                                <a href="{{ $link($policy) }}" wire:navigate class="hover:underline">{{ $policy->label() }}</a>
                                <span class="mt-0.5 block text-[13px] font-normal text-muted">
                                    {{ $policy->policy_number ?? 'No number yet' }} · {{ $policy->renewalLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $policy->insurer?->displayName() ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $money($policy->premium, $policy->currency ?? $currency) }}</td>
                            <td class="px-4 py-3 {{ $policy->coverLapsed() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                                {{ $policy->covers_to?->toFormattedDateString() ?? 'Open cover' }}
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $policy->notice_by?->toFormattedDateString() ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />

                                @can('insurance.manage')
                                    @if ($policy->status === 'draft')
                                        <button type="button" wire:click="bind('{{ $policy->id }}')"
                                                class="tap focusable ml-2 rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                            Bind
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-muted">
                                @if ($search !== '' || $status !== '')
                                    Nothing matches.
                                @else
                                    No policies on the book yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($policies->hasPages())
            <div class="mt-4">{{ $policies->links() }}</div>
        @endif
    @endif
</div>
