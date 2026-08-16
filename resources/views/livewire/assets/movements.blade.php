@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $tab === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';

    $overdue = $servicing->filter->isOverdue();
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Movements &amp; servicing</h1>
            <p class="mt-1 text-[14.5px] text-muted">Where things are, who has them, and what is due a service.</p>
        </div>

        <a href="{{ route('assets') }}" wire:navigate
           class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
            Register
        </a>
    </div>

    @if ($overdue->isNotEmpty())
        <div class="mt-5 rounded-2xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-[14.5px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>{{ $overdue->count() }}</strong>
            {{ Str::plural('item', $overdue->count()) }} overdue a service.
        </div>
    @endif

    <div class="mt-5 flex gap-1 border-b border-border pb-3">
        <button type="button" wire:click="$set('tab', 'assets')" class="{{ $tabClass('assets') }}">Where things are</button>
        <button type="button" wire:click="$set('tab', 'servicing')" class="{{ $tabClass('servicing') }}">Servicing</button>
        <button type="button" wire:click="$set('tab', 'sites')" class="{{ $tabClass('sites') }}">Sites</button>
    </div>

    {{-- ─────────────────────────────────────────── where things are ── --}}
    @if ($tab === 'assets')
        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Asset</th>
                        <th class="px-4 py-3">Where</th>
                        <th class="px-4 py-3">Who has it</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($assets as $asset)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $asset->name }}
                                @if ($asset->reference)
                                    <span class="block text-[13px] font-normal text-muted">{{ $asset->reference }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $asset->locationName() ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $asset->custodian?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('assets.transfer')
                                    <button type="button" wire:click="startTransfer('{{ $asset->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        Transfer
                                    </button>
                                @endcan
                            </td>
                        </tr>

                        @if ($transferring === $asset->id)
                            <tr class="border-t border-border bg-fill-2">
                                <td colspan="4" class="px-4 py-4">
                                    @error('transferring')
                                        <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                                    @enderror

                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <label class="{{ $labelClass }}">Move to</label>
                                            <select wire:model="toLocationId" class="{{ $inputClass }}">
                                                <option value="">Nowhere in particular</option>
                                                @foreach ($sites as $site)
                                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="{{ $labelClass }}">Hand to</label>
                                            <select wire:model="toCustodianId" class="{{ $inputClass }}">
                                                <option value="">Nobody — back to the store</option>
                                                @foreach ($people as $person)
                                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="{{ $labelClass }}">On</label>
                                            <input type="date" wire:model="transferredOn" class="{{ $inputClass }}">
                                        </div>
                                        <div>
                                            <label class="{{ $labelClass }}">Why</label>
                                            <input type="text" wire:model="reason" placeholder="Optional" class="{{ $inputClass }}">
                                        </div>
                                    </div>

                                    <div class="mt-3 flex gap-2">
                                        <button type="button" wire:click="transfer"
                                                class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                            Record transfer
                                        </button>
                                        <button type="button" wire:click="$set('transferring', null)"
                                                class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                            Cancel
                                        </button>
                                    </div>

                                    @if ($asset->transfers->isNotEmpty())
                                        <p class="mt-4 text-[13px] font-semibold text-ink-2">Where it has been</p>
                                        <ul class="mt-1.5 space-y-1 text-[13.5px] text-muted">
                                            @foreach ($asset->transfers->take(5) as $move)
                                                <li>
                                                    {{ $move->transferred_on->toFormattedDateString() }} —
                                                    {{ $move->fromLocation?->name ?? 'nowhere' }} →
                                                    {{ $move->toLocation?->name ?? 'nowhere' }}
                                                    @if ($move->changedHands())
                                                        , to {{ $move->toCustodian?->name ?? 'the store' }}
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-muted">
                                Nothing on the register yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────────── servicing ── --}}
    @if ($tab === 'servicing')
        @can('assets.maintain')
            <div class="mt-5">
                @if (! $booking)
                    <button type="button" wire:click="$set('booking', true)"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Book servicing
                    </button>
                @else
                    <div class="rounded-2xl border border-border p-4">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <label class="{{ $labelClass }}">Asset</label>
                                <select wire:model="jobAssetId" class="{{ $inputClass }}">
                                    <option value="">Choose…</option>
                                    @foreach ($assets as $asset)
                                        <option value="{{ $asset->id }}">{{ $asset->name }}</option>
                                    @endforeach
                                </select>
                                @error('jobAssetId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Kind</label>
                                <select wire:model="jobKind" class="{{ $inputClass }}">
                                    @foreach ($kinds as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">What</label>
                                <input type="text" wire:model="jobTitle" placeholder="10 000 km service" class="{{ $inputClass }}">
                                @error('jobTitle') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Due</label>
                                <input type="date" wire:model="jobDueOn" class="{{ $inputClass }}">
                                @error('jobDueOn') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Repeat every</label>
                                <input type="number" wire:model="jobInterval" placeholder="months" class="{{ $inputClass }}">
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="bookServicing"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Book it
                            </button>
                            <button type="button" wire:click="$set('booking', false)"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @endcan

        @error('servicing')
            <p class="mt-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
        @enderror

        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Asset</th>
                        <th class="px-4 py-3">Work</th>
                        <th class="px-4 py-3">Due</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($servicing as $job)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">{{ $job->asset?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">
                                {{ $job->title }}
                                <span class="block text-[13px] text-muted">
                                    {{ $job->kindLabel() }}@if ($job->interval_months), every {{ $job->interval_months }} months @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 {{ $job->isOverdue() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                                {{ $job->due_on?->toFormattedDateString() ?? 'No date' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('assets.maintain')
                                    <button type="button" wire:click="completeServicing('{{ $job->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        Mark done
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-muted">
                                Nothing outstanding.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($recentlyDone->isNotEmpty())
            <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Recently done</p>
            <ul class="mt-2 space-y-1.5 text-[14px] text-ink-2">
                @foreach ($recentlyDone as $job)
                    <li>
                        {{ $job->completed_on->toFormattedDateString() }} —
                        {{ $job->asset?->name }}: {{ $job->title }}
                        @if ($job->expense_id)
                            <span class="text-muted">(billed)</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    {{-- ──────────────────────────────────────────────────────── sites ── --}}
    @if ($tab === 'sites')
        @can('assets.update')
            <div class="mt-5">
                @if (! $addingSite)
                    <button type="button" wire:click="$set('addingSite', true)"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Add a site
                    </button>
                @else
                    <div class="rounded-2xl border border-border p-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="{{ $labelClass }}">Name</label>
                                <input type="text" wire:model="siteName" placeholder="Bonabéri Depot" class="{{ $inputClass }}">
                                @error('siteName') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Code</label>
                                <input type="text" wire:model="siteCode" placeholder="Optional" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Address</label>
                                <input type="text" wire:model="siteAddress" placeholder="Optional" class="{{ $inputClass }}">
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="addSite"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Add
                            </button>
                            <button type="button" wire:click="$set('addingSite', false)"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @endcan

        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($sites as $site)
                <div class="rounded-2xl border border-border p-4">
                    <p class="text-[15.5px] font-semibold text-ink">{{ $site->name }}</p>
                    @if ($site->address)
                        <p class="mt-1 text-[13.5px] text-muted">{{ $site->address }}</p>
                    @endif
                    <p class="mt-2 text-[13.5px] text-ink-2">
                        {{ $site->assets_count }} {{ Str::plural('item', $site->assets_count) }}
                    </p>
                </div>
            @empty
                <p class="text-muted">No sites yet. Add one, then transfer things to it.</p>
            @endforelse
        </div>
    @endif
</div>
