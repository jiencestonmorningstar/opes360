@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $tab === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';

    $lapsed = collect($papers)->where('lapsed', true);
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Fleet</h1>
            <p class="mt-1 text-[14.5px] text-muted">How far the vehicles have gone, what they drank, and whether their papers are current.</p>
        </div>

        <a href="{{ route('assets.movements') }}" wire:navigate
           class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
            Movements
        </a>
    </div>

    @if ($lapsed->isNotEmpty())
        <div class="mt-5 rounded-2xl border border-rose-300/60 bg-rose-50 px-4 py-3 text-[14.5px] text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
            <strong>{{ $lapsed->count() }}</strong>
            {{ Str::plural('paper', $lapsed->count()) }} already expired. A vehicle driving on lapsed cover is the business's problem, not the driver's.
        </div>
    @endif

    @if ($servicingDue->isNotEmpty())
        <div class="mt-3 rounded-2xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-[14.5px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>{{ $servicingDue->count() }}</strong>
            {{ Str::plural('job', $servicingDue->count()) }} due a service — by date or by distance.
        </div>
    @endif

    <div class="mt-5 flex flex-wrap gap-1 border-b border-border pb-3">
        <button type="button" wire:click="$set('tab', 'fleet')" class="{{ $tabClass('fleet') }}">The fleet</button>
        <button type="button" wire:click="$set('tab', 'trips')" class="{{ $tabClass('trips') }}">Trips</button>
        <button type="button" wire:click="$set('tab', 'fuel')" class="{{ $tabClass('fuel') }}">Fuel</button>
        <button type="button" wire:click="$set('tab', 'papers')" class="{{ $tabClass('papers') }}">Papers</button>
    </div>

    {{-- ───────────────────────────────────────────────────── the fleet ── --}}
    @if ($tab === 'fleet')
        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Vehicle</th>
                        <th class="px-4 py-3">Odometer</th>
                        <th class="px-4 py-3">L/100 km</th>
                        <th class="px-4 py-3">Driver</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $vehicle->name }}
                                <span class="block text-[13px] font-normal text-muted">
                                    {{ $vehicle->vehicle->label() }}
                                    @if ($vehicle->vehicle->year), {{ $vehicle->vehicle->year }} @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-ink-2">
                                {{ $odometers[$vehicle->id] === null ? 'Not known' : number_format($odometers[$vehicle->id]).' km' }}
                            </td>
                            <td class="px-4 py-3 text-ink-2">
                                {{ $consumption[$vehicle->id] === null ? '—' : number_format($consumption[$vehicle->id], 2) }}
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $vehicle->custodian?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('assets.update')
                                    <button type="button" wire:click="startVehicle('{{ $vehicle->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        Details
                                    </button>
                                @endcan
                            </td>
                        </tr>

                        @if ($editing === $vehicle->id)
                            @include('livewire.fleet.partials.details-form', ['asset' => $vehicle])
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-muted">
                                No vehicles yet. Give an asset a registration below and it joins the fleet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('assets.update')
            <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Add a vehicle to the fleet</p>
            <p class="mt-1 text-[14px] text-muted">
                A vehicle is an asset you already own. Pick it from the register and add its plate — nothing is entered twice.
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($candidates as $candidate)
                    <button type="button" wire:click="startVehicle('{{ $candidate->id }}')"
                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                        {{ $candidate->name }}
                    </button>
                @empty
                    <p class="text-[14px] text-muted">Everything on the register is already in the fleet.</p>
                @endforelse
            </div>

            @if ($editing && ! $vehicles->contains('id', $editing))
                <div class="mt-4 overflow-hidden rounded-2xl border border-border">
                    <table class="w-full"><tbody>
                        @include('livewire.fleet.partials.details-form', ['asset' => null])
                    </tbody></table>
                </div>
            @endif
        @endcan
    @endif

    {{-- ───────────────────────────────────────────────────────── trips ── --}}
    @if ($tab === 'trips')
        @can('assets.transfer')
            <div class="mt-5 rounded-2xl border border-border p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <div>
                        <label class="{{ $labelClass }}">Vehicle</label>
                        <select wire:model="tripAssetId" class="{{ $inputClass }}">
                            <option value="">Choose…</option>
                            @foreach ($vehicles as $vehicle)
                                <option value="{{ $vehicle->id }}">{{ $vehicle->name }}</option>
                            @endforeach
                        </select>
                        @error('tripAssetId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Driver</label>
                        <select wire:model="tripDriverId" class="{{ $inputClass }}">
                            <option value="">Not recorded</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Date</label>
                        <input type="date" wire:model="tripDate" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Out at</label>
                        <input type="number" wire:model="tripStart" placeholder="km" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Back at</label>
                        <input type="number" wire:model="tripEnd" placeholder="km" class="{{ $inputClass }}">
                        @error('tripEnd') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">What for</label>
                        <input type="text" wire:model="tripPurpose" placeholder="Optional" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="button" wire:click="logTrip"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Log the trip
                    </button>
                </div>
            </div>
        @endcan

        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Vehicle</th>
                        <th class="px-4 py-3">Driver</th>
                        <th class="px-4 py-3">Distance</th>
                        <th class="px-4 py-3">What for</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($trips as $trip)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 text-ink-2">{{ $trip->trip_date->toFormattedDateString() }}</td>
                            <td class="px-4 py-3 font-semibold text-ink">{{ $trip->asset?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $trip->driver?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ number_format($trip->distance()) }} km</td>
                            <td class="px-4 py-3 text-muted">{{ $trip->purpose ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No trips logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ────────────────────────────────────────────────────────── fuel ── --}}
    @if ($tab === 'fuel')
        @can('assets.transfer')
            <div class="mt-5 rounded-2xl border border-border p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <label class="{{ $labelClass }}">Vehicle</label>
                        <select wire:model="fuelAssetId" class="{{ $inputClass }}">
                            <option value="">Choose…</option>
                            @foreach ($vehicles as $vehicle)
                                <option value="{{ $vehicle->id }}">{{ $vehicle->name }}</option>
                            @endforeach
                        </select>
                        @error('fuelAssetId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Date</label>
                        <input type="date" wire:model="fuelDate" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Litres</label>
                        <input type="number" step="0.01" wire:model="fuelLitres" class="{{ $inputClass }}">
                        @error('fuelLitres') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Odometer</label>
                        <input type="number" wire:model="fuelOdometer" placeholder="At the pump" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Paid by</label>
                        <select wire:model="fuelExpenseId" class="{{ $inputClass }}">
                            <option value="">No expense yet</option>
                            @foreach ($expenses as $expense)
                                <option value="{{ $expense->id }}">{{ $expense->reference }} — {{ $expense->description }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <p class="mt-2 text-[13px] text-muted">
                    The amount is not entered here. It is read off the expense, so the month's fuel bill has one answer.
                </p>

                <div class="mt-3">
                    <button type="button" wire:click="logFuel"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Record the fill
                    </button>
                </div>
            </div>
        @endcan

        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Vehicle</th>
                        <th class="px-4 py-3">Litres</th>
                        <th class="px-4 py-3">Odometer</th>
                        <th class="px-4 py-3">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($fills as $fill)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 text-ink-2">{{ $fill->filled_on->toFormattedDateString() }}</td>
                            <td class="px-4 py-3 font-semibold text-ink">{{ $fill->asset?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ number_format((float) $fill->litres, 2) }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $fill->odometer === null ? '—' : number_format($fill->odometer) }}</td>
                            <td class="px-4 py-3 text-ink-2">
                                {{ $fill->cost() === null ? 'Not billed' : number_format($fill->cost()) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No fills recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ──────────────────────────────────────────────────────── papers ── --}}
    @if ($tab === 'papers')
        <div class="mt-5 space-y-2">
            @forelse ($papers as $paper)
                <div class="flex items-center justify-between gap-4 rounded-2xl border p-4
                            {{ $paper['lapsed']
                                ? 'border-rose-300/60 bg-rose-50 dark:border-rose-500/30 dark:bg-rose-500/10'
                                : 'border-border' }}">
                    <div>
                        <p class="text-[15.5px] font-semibold text-ink">
                            {{ $paper['asset']?->name ?? $paper['vehicle']->label() }} — {{ $paper['kind'] }}
                        </p>
                        <p class="mt-1 text-[13.5px] text-muted">{{ $paper['vehicle']->label() }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[14.5px] font-semibold {{ $paper['lapsed'] ? 'text-rose-600' : 'text-ink-2' }}">
                            {{ $paper['expires_on']->toFormattedDateString() }}
                        </p>
                        <p class="text-[13px] text-muted">
                            {{ $paper['lapsed']
                                ? 'expired '.abs($paper['days']).' days ago'
                                : 'in '.$paper['days'].' days' }}
                        </p>
                    </div>
                </div>
            @empty
                <p class="text-muted">Nothing expiring in the next two months.</p>
            @endforelse
        </div>

        @if ($servicingDue->isNotEmpty())
            <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Servicing due</p>
            <ul class="mt-2 space-y-1.5 text-[14px] text-ink-2">
                @foreach ($servicingDue as $job)
                    <li>
                        {{ $job->asset?->name }}: {{ $job->title }}
                        <span class="text-muted">
                            @if ($job->due_at_odometer)
                                (due at {{ number_format($job->due_at_odometer) }} km)
                            @elseif ($job->due_on)
                                (due {{ $job->due_on->toFormattedDateString() }})
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
