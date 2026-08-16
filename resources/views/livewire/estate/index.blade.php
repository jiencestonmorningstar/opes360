@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $money = fn ($amount) => $amount === null ? '—' : number_format((float) $amount).' '.$currency;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Properties</h1>
            <p class="mt-1 text-[14.5px] text-muted">Which doors are earning, who is behind, and which leases run out.</p>
        </div>

        @can('estate.manage')
            <button type="button" wire:click="startAdding"
                    class="tap focusable shrink-0 rounded-full bg-fill-brand px-5 py-2.5 text-[14.5px] font-semibold text-white">
                Add a property
            </button>
        @endcan
    </div>

    {{-- ──────────────────────────────────────────────────────── the board ── --}}
    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-2xl border border-border bg-surface p-4">
            <p class="text-[13px] font-semibold text-ink-2">Vacant units</p>
            <p class="mt-1 text-[22px] font-bold {{ $summary['vacant_units'] > 0 ? 'text-warning' : 'text-ink' }}">{{ $summary['vacant_units'] }}</p>
            @if ($summary['target_rent_lost'] > 0)
                <p class="text-[12.5px] text-muted">{{ $money($summary['target_rent_lost']) }}/month not earned</p>
            @endif
        </div>
        <div class="rounded-2xl border border-border bg-surface p-4">
            <p class="text-[13px] font-semibold text-ink-2">Tenancies in arrears</p>
            <p class="mt-1 text-[22px] font-bold {{ $summary['tenancies_in_arrears'] > 0 ? 'text-rose-600' : 'text-ink' }}">{{ $summary['tenancies_in_arrears'] }}</p>
            @if ($summary['arrears_total'] > 0)
                <p class="text-[12.5px] text-muted">{{ $money($summary['arrears_total']) }} owed</p>
            @endif
        </div>
        <div class="rounded-2xl border border-border bg-surface p-4">
            <p class="text-[13px] font-semibold text-ink-2">Leases ending soon</p>
            <p class="mt-1 text-[22px] font-bold text-ink">{{ $summary['leases_ending'] }}</p>
            <p class="text-[12.5px] text-muted">inside 60 days</p>
        </div>
        <div class="rounded-2xl border border-border bg-surface p-4">
            <p class="text-[13px] font-semibold text-ink-2">Open tenancies</p>
            <p class="mt-1 text-[22px] font-bold text-ink">{{ $summary['open_tenancies'] }}</p>
        </div>
    </div>

    {{-- Arrears shout loudest: an empty flat costs its rent, a sitting tenant
         who does not pay costs the rent AND the flat. --}}
    @if ($arrears->isNotEmpty())
        <div class="mt-5 rounded-2xl border-2 border-rose-400 bg-rose-50 p-5 dark:border-rose-500/50 dark:bg-rose-500/10">
            <p class="text-[16px] font-bold text-rose-800 dark:text-rose-200">
                {{ $arrears->count() }} {{ Str::plural('tenancy', $arrears->count()) }} in arrears
            </p>
            <ul class="mt-3 space-y-2">
                @foreach ($arrears as $row)
                    <li wire:key="arrears-{{ $row['tenancy']->id }}" class="rounded-xl bg-white/70 px-4 py-3 dark:bg-black/20">
                        <p class="text-[15px] font-semibold text-rose-900 dark:text-rose-100">
                            {{ $row['tenancy']->tenant?->displayName() }}
                            <span class="font-normal">— {{ $row['tenancy']->unit?->label }}{{ $row['tenancy']->unit?->property ? ', '.$row['tenancy']->unit->property->name : '' }}</span>
                        </p>
                        <p class="mt-0.5 text-[13.5px] text-rose-700 dark:text-rose-300">
                            Owes {{ $money($row['total']) }} · oldest invoice {{ $row['oldest_days'] }} {{ Str::plural('day', $row['oldest_days']) }} overdue
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <x-ui.panel title="Vacant units">
            <p class="-mt-2 mb-3 text-[13.5px] text-muted">Every door here earns nothing until somebody moves in.</p>

            @forelse ($vacancies as $row)
                <div wire:key="vacant-{{ $row['property']->id }}" class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">
                        <a href="{{ route('estate.show', $row['property']) }}" wire:navigate class="hover:underline">{{ $row['property']->name }}</a>
                    </p>
                    <p class="mt-0.5 text-[13px] text-warning">
                        {{ $row['units']->pluck('label')->implode(', ') }}
                        @if ($row['target_rent_lost'] > 0)
                            <span class="text-muted">· {{ $money($row['target_rent_lost']) }}/month asking</span>
                        @endif
                    </p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">Every unit is let.</p>
            @endforelse
        </x-ui.panel>

        <x-ui.panel title="Leases ending">
            <p class="-mt-2 mb-3 text-[13.5px] text-muted">
                From the contract watch — the same list, filtered to leases on open tenancies.
            </p>

            @forelse ($endings as $row)
                <div wire:key="ending-{{ $row['tenancy']->id }}" class="{{ $loop->first ? '' : 'mt-2 border-t border-border pt-2' }}">
                    <p class="text-[14.5px] font-semibold text-ink">
                        {{ $row['tenancy']->tenant?->displayName() }} — {{ $row['tenancy']->unit?->label }}
                    </p>
                    <p class="mt-0.5 text-[13px] text-muted">
                        Lease ends {{ $row['contract']->ends_on?->toFormattedDateString() }}
                        @if ($row['contract']->notice_by)
                            · notice by {{ $row['contract']->notice_by->toFormattedDateString() }}
                        @endif
                    </p>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No lease runs out in the next two months.</p>
            @endforelse
        </x-ui.panel>
    </div>

    {{-- ─────────────────────────────────────────────────── adding a property ── --}}
    @if ($adding)
        <div class="mt-6 rounded-2xl border border-border bg-surface p-5">
            <h2 class="text-[17px] font-bold text-ink">Add a property</h2>

            @error('adding')
                <p class="mt-2 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
            @enderror

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">Name</label>
                    <input type="text" wire:model="name" placeholder="Immeuble Bonanjo" class="{{ $inputClass }}">
                    @error('name') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Kind</label>
                    <select wire:model="kind" class="{{ $inputClass }}">
                        @foreach (\App\Models\Property::KINDS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Address</label>
                    <input type="text" wire:model="address" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Landlord</label>
                    <select wire:model="landlordId" class="{{ $inputClass }}">
                        <option value="">This business owns it</option>
                        @foreach ($landlords as $landlord)
                            <option value="{{ $landlord->id }}">{{ $landlord->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="add"
                        class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                    Add it
                </button>
                <button type="button" wire:click="$set('adding', false)"
                        class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ───────────────────────────────────────────────────── the buildings ── --}}
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <div class="min-w-56 flex-1">
            <label for="property-search" class="sr-only">Search properties</label>
            <input id="property-search" wire:model.live.debounce.300ms="search" type="search"
                   placeholder="Search properties…"
                   class="focusable h-11 w-full rounded-full border border-border bg-surface px-4 text-[14.5px] text-ink">
        </div>
    </div>

    <div class="mt-4 overflow-hidden rounded-2xl border border-border">
        <table class="w-full text-left text-[14.5px]">
            <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                <tr>
                    <th class="px-4 py-3">Property</th>
                    <th class="px-4 py-3">Landlord</th>
                    <th class="px-4 py-3">Units</th>
                    <th class="px-4 py-3">Vacant</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($properties as $property)
                    @php $vacant = $property->units->where('status', 'vacant')->count(); @endphp
                    <tr wire:key="prop-{{ $property->id }}" class="border-t border-border">
                        <td class="px-4 py-3 font-semibold text-ink">
                            <a href="{{ route('estate.show', $property) }}" wire:navigate class="hover:underline">{{ $property->name }}</a>
                            <span class="mt-0.5 block text-[13px] font-normal text-muted">
                                {{ $property->kindLabel() }}{{ $property->address ? ' · '.$property->address : '' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-ink-2">{{ $property->landlord?->displayName() ?? 'Own building' }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $property->units->count() }}</td>
                        <td class="px-4 py-3 {{ $vacant > 0 ? 'font-semibold text-warning' : 'text-ink-2' }}">{{ $vacant }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-muted">
                            {{ $search !== '' ? 'Nothing matches.' : 'No properties yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($properties->hasPages())
        <div class="mt-4">{{ $properties->links() }}</div>
    @endif
</div>
