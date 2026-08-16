@php
    use App\Models\CrmActivity;
    use App\Models\Deal;
    use App\Models\Lead;
    use App\Support\Money;

    $company = app(\App\Support\CurrentCompany::class)->get();
    $currency = $company?->currency ?? 'XAF';

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $tab === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';

    $statusAccent = [
        'new' => 'bg-tint-slate text-accent-slate',
        'working' => 'bg-tint-blue text-accent-blue',
        'qualified' => 'bg-tint-purple text-accent-purple',
        'converted' => 'bg-tint-green text-accent-green',
        'lost' => 'bg-tint-orange text-accent-orange',
    ];
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Leads</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $openCount }} {{ Str::plural('lead', $openCount) }} in the funnel
            </p>
        </div>

        <a href="{{ route('deals') }}" wire:navigate
           class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
            Pipeline
        </a>
    </div>

    @if ($overdueActivities->isNotEmpty())
        <div class="mt-5 rounded-2xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-[14.5px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>{{ $overdueActivities->count() }}</strong>
            {{ Str::plural('follow-up', $overdueActivities->count()) }} overdue.
        </div>
    @endif

    <div class="mt-5 flex gap-1 border-b border-border pb-3">
        <button type="button" wire:click="$set('tab', 'leads')" class="{{ $tabClass('leads') }}">Leads</button>
        <button type="button" wire:click="$set('tab', 'activity')" class="{{ $tabClass('activity') }}">Activity</button>
        <button type="button" wire:click="$set('tab', 'forecast')" class="{{ $tabClass('forecast') }}">Forecast</button>
    </div>

    {{-- ──────────────────────────────────────────────────────── leads ── --}}
    @if ($tab === 'leads')
        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search leads…" class="{{ $inputClass }} pl-11">
            </div>

            <label class="flex h-12 shrink-0 cursor-pointer items-center gap-2.5 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-ink-2">
                <input type="checkbox" wire:model.live="showClosed" class="size-4 rounded border-border-strong text-brand focus:ring-brand/30">
                Show closed
            </label>

            @can('deals.create')
                <button type="button" wire:click="$set('adding', true)"
                        class="tap focusable flex h-12 shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    <x-icon name="plus" class="size-[18px]" stroke-width="2.2" />
                    Add lead
                </button>
            @endcan
        </div>

        @error('leads')
            <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
        @enderror

        @if ($adding)
            <div class="mt-4 rounded-2xl border border-border p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="{{ $labelClass }}">Name</label>
                        <input type="text" wire:model="leadName" placeholder="Jane Mbeki" class="{{ $inputClass }}">
                        @error('leadName') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Business</label>
                        <input type="text" wire:model="leadCompany" placeholder="Optional" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Phone</label>
                        <input type="text" wire:model="leadPhone" placeholder="+237 …" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Email</label>
                        <input type="email" wire:model="leadEmail" placeholder="Optional" class="{{ $inputClass }}">
                        @error('leadEmail') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Where from</label>
                        <input type="text" wire:model="leadSource" placeholder="Referral, trade fair, walk-in…" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Notes</label>
                        <input type="text" wire:model="leadNotes" placeholder="Optional" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="addLead"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Add
                    </button>
                    <button type="button" wire:click="$set('adding', false)"
                            class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <div class="overflow-x-auto">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Who</th>
                        <th class="px-4 py-3">Where from</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Working it</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leads as $lead)
                        <tr class="border-t border-border" wire:key="lead-{{ $lead->id }}">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $lead->name }}
                                @if ($lead->company_name || $lead->phone)
                                    <span class="block text-[13px] font-normal text-muted">
                                        {{ collect([$lead->company_name, $lead->phone])->filter()->implode(' · ') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $lead->source ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($lead->isOpen())
                                    @can('update', $lead)
                                        <select wire:change="move('{{ $lead->id }}', $event.target.value)"
                                                aria-label="Move {{ $lead->name }} to another status"
                                                class="h-9 rounded-lg border border-border bg-surface px-2 text-[12.5px] font-medium text-ink-2 focus:border-brand focus:outline-none">
                                            @foreach (Lead::STATUSES as $key => $label)
                                                @continue(in_array($key, Lead::CLOSED_STATUSES, true))
                                                <option value="{{ $key }}" @selected($lead->status === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="rounded-full px-2.5 py-1 text-[12px] font-semibold {{ $statusAccent[$lead->status] ?? '' }}">
                                            {{ Lead::STATUSES[$lead->status] ?? $lead->status }}
                                        </span>
                                    @endcan
                                @else
                                    <span class="rounded-full px-2.5 py-1 text-[12px] font-semibold {{ $statusAccent[$lead->status] ?? '' }}">
                                        {{ Lead::STATUSES[$lead->status] ?? $lead->status }}
                                    </span>
                                    @if ($lead->status === 'lost' && $lead->lost_reason)
                                        <span class="block pt-1 text-[12.5px] text-muted">{{ $lead->lost_reason }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $lead->assignee?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($lead->isOpen())
                                    @can('update', $lead)
                                        <button type="button" wire:click="startConvert('{{ $lead->id }}')"
                                                class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                            Convert
                                        </button>
                                        <button type="button" wire:click="startLose('{{ $lead->id }}')"
                                                class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-muted">
                                            Lost
                                        </button>
                                    @endcan
                                @elseif ($lead->contact_id)
                                    <span class="text-[13px] text-positive">Customer ✓</span>
                                @endif
                            </td>
                        </tr>

                        @if ($converting === $lead->id)
                            <tr class="border-t border-border bg-fill-2">
                                <td colspan="5" class="px-4 py-4">
                                    @error('converting')
                                        <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                                    @enderror

                                    <p class="text-[14px] text-ink-2">
                                        This creates <strong>{{ $lead->name }}</strong> in the customer book.
                                    </p>

                                    <label class="mt-3 flex cursor-pointer items-center gap-2.5 text-[14px] font-semibold text-ink-2">
                                        <input type="checkbox" wire:model.live="withDeal" class="size-4 rounded border-border-strong text-brand focus:ring-brand/30">
                                        Also start a deal on the pipeline
                                    </label>

                                    @if ($withDeal)
                                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label class="{{ $labelClass }}">Deal</label>
                                                <input type="text" wire:model="dealTitle" class="{{ $inputClass }}">
                                                @error('dealTitle') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label class="{{ $labelClass }}">Worth</label>
                                                <input type="number" wire:model="dealValue" placeholder="0" class="{{ $inputClass }}">
                                                @error('dealValue') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label class="{{ $labelClass }}">Expected close</label>
                                                <input type="date" wire:model="dealCloseOn" class="{{ $inputClass }}">
                                            </div>
                                        </div>
                                    @endif

                                    <div class="mt-3 flex gap-2">
                                        <button type="button" wire:click="convert"
                                                class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                            Convert
                                        </button>
                                        <button type="button" wire:click="$set('converting', null)"
                                                class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endif

                        @if ($losing === $lead->id)
                            <tr class="border-t border-border bg-fill-2">
                                <td colspan="5" class="px-4 py-4">
                                    @error('losing')
                                        <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                                    @enderror

                                    <div class="sm:max-w-md">
                                        <label class="{{ $labelClass }}">Why did it go nowhere?</label>
                                        <input type="text" wire:model="lostReason" placeholder="Went with a competitor, no budget…" class="{{ $inputClass }}">
                                        @error('lostReason') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                    </div>

                                    <div class="mt-3 flex gap-2">
                                        <button type="button" wire:click="lose"
                                                class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                            Mark as lost
                                        </button>
                                        <button type="button" wire:click="$set('losing', null)"
                                                class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-muted">
                                No leads yet. A lead is a name and a number that might become a
                                customer — add the first one and work it from here.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    @endif

    {{-- ───────────────────────────────────────────────────── activity ── --}}
    @if ($tab === 'activity')
        @can('deals.create')
            <div class="mt-5 rounded-2xl border border-border p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="{{ $labelClass }}">About</label>
                        <select wire:model="actSubject" class="{{ $inputClass }}">
                            <option value="">Choose…</option>
                            @if ($openDeals->isNotEmpty())
                                <optgroup label="Deals">
                                    @foreach ($openDeals as $deal)
                                        <option value="deal:{{ $deal->id }}">{{ $deal->title }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($openLeads->isNotEmpty())
                                <optgroup label="Leads">
                                    @foreach ($openLeads as $lead)
                                        <option value="lead:{{ $lead->id }}">{{ $lead->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        @error('actSubject') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Kind</label>
                        <select wire:model="actKind" class="{{ $inputClass }}">
                            @foreach (CrmActivity::KINDS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">What</label>
                        <input type="text" wire:model="actSummary" placeholder="Chase the quotation" class="{{ $inputClass }}">
                        @error('actSummary') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Due (leave empty if already done)</label>
                        <input type="datetime-local" wire:model="actDueAt" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="button" wire:click="logActivity"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Log it
                    </button>
                </div>
            </div>
        @endcan

        @if ($untouched->isNotEmpty())
            <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Deals nobody has touched in a week</p>
            <div class="mt-2 overflow-hidden rounded-2xl border border-border">
                <table class="w-full text-left text-[14.5px]">
                    <tbody>
                        @foreach ($untouched as $deal)
                            <tr class="border-t border-border first:border-t-0">
                                <td class="px-4 py-3 font-semibold text-ink">{{ $deal->title }}</td>
                                <td class="px-4 py-3 text-ink-2">{{ $deal->displayName() }}</td>
                                <td class="px-4 py-3 text-ink-2">{{ Deal::STAGES[$deal->stage] ?? $deal->stage }}</td>
                                <td class="px-4 py-3 text-right text-muted">quiet since {{ $deal->updated_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">To do</p>
        <div class="mt-2 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <tbody>
                    @forelse ($overdueActivities->concat($plannedActivities) as $activity)
                        <tr class="border-t border-border first:border-t-0" wire:key="act-{{ $activity->id }}">
                            <td class="px-4 py-3">
                                <span class="font-semibold text-ink">{{ $activity->summary }}</span>
                                <span class="block text-[13px] text-muted">
                                    {{ $activity->kindLabel() }}
                                    @if ($activity->subject)
                                        · {{ $activity->subject->title ?? $activity->subject->name }}
                                    @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 {{ $activity->isOverdue() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                                {{ $activity->due_at?->toDayDateTimeString() }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('deals.update')
                                    <button type="button" wire:click="completeActivity('{{ $activity->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        Mark done
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-10 text-center text-muted">
                                Nothing planned. Log a call or a task above and it shows up here until done.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($recentActivities->isNotEmpty())
            <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Recently done</p>
            <ul class="mt-2 space-y-1.5 text-[14px] text-ink-2">
                @foreach ($recentActivities as $activity)
                    <li>
                        {{ $activity->done_at->toFormattedDateString() }} —
                        {{ $activity->kindLabel() }}: {{ $activity->summary }}
                        @if ($activity->user)
                            <span class="text-muted">({{ $activity->user->name }})</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    {{-- ───────────────────────────────────────────────────── forecast ── --}}
    @if ($tab === 'forecast')
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="rounded-2xl border border-border p-4">
                <p class="text-[13px] font-semibold uppercase tracking-wide text-muted">Weighted pipeline</p>
                <p class="tnum mt-1 text-[24px] font-bold text-ink">{{ Money::format($forecast['total_weighted'], $currency, false) }}</p>
                <p class="mt-1 text-[13px] text-muted">Each deal's worth × its chance of closing.</p>
            </div>
            <div class="rounded-2xl border border-border p-4">
                <p class="text-[13px] font-semibold uppercase tracking-wide text-muted">If everything closed</p>
                <p class="tnum mt-1 text-[24px] font-bold text-ink">{{ Money::format($forecast['total_gross'], $currency, false) }}</p>
                <p class="mt-1 text-[13px] text-muted">The whole open board, unweighted.</p>
            </div>
        </div>

        <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">By expected close month</p>
        <div class="mt-2 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Month</th>
                        <th class="px-4 py-3 text-right">Deals</th>
                        <th class="px-4 py-3 text-right">Weighted</th>
                        <th class="px-4 py-3 text-right">Gross</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($forecast['months'] as $month)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">{{ $month['label'] }}</td>
                            <td class="tnum px-4 py-3 text-right text-ink-2">{{ $month['count'] }}</td>
                            <td class="tnum px-4 py-3 text-right font-semibold text-ink">{{ Money::format($month['weighted'], $currency, false) }}</td>
                            <td class="tnum px-4 py-3 text-right text-muted">{{ Money::format($month['gross'], $currency, false) }}</td>
                        </tr>
                    @endforeach
                    @if ($forecast['unscheduled']['count'] > 0)
                        <tr class="border-t border-border bg-fill-2">
                            <td class="px-4 py-3 text-ink-2">No close date set</td>
                            <td class="tnum px-4 py-3 text-right text-ink-2">{{ $forecast['unscheduled']['count'] }}</td>
                            <td class="tnum px-4 py-3 text-right font-semibold text-ink">{{ Money::format($forecast['unscheduled']['weighted'], $currency, false) }}</td>
                            <td class="tnum px-4 py-3 text-right text-muted">{{ Money::format($forecast['unscheduled']['gross'], $currency, false) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">By person</p>
        <div class="mt-2 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <tbody>
                    @forelse ($forecast['owners'] as $row)
                        <tr class="border-t border-border first:border-t-0">
                            <td class="px-4 py-3 font-semibold text-ink">{{ $row['owner'] }}</td>
                            <td class="tnum px-4 py-3 text-right text-ink-2">{{ $row['count'] }} {{ Str::plural('deal', $row['count']) }}</td>
                            <td class="tnum px-4 py-3 text-right font-semibold text-ink">{{ Money::format($row['weighted'], $currency, false) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-10 text-center text-muted">
                                Nothing open on the board, so nothing to forecast.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
