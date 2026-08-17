@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Dispatch</h1>
            <p class="mt-1 text-[14.5px] text-muted">What is waiting, what is being loaded, and what is on the road.</p>
        </div>

        @can('logistics.manage')
            <div class="flex shrink-0 gap-2">
                <button type="button" wire:click="$toggle('opening')"
                        class="tap focusable rounded-full border border-border px-5 py-2.5 text-[14.5px] font-semibold text-ink">
                    Open a manifest
                </button>
                <button type="button" wire:click="$toggle('booking')"
                        class="tap focusable rounded-full bg-fill-brand px-5 py-2.5 text-[14.5px] font-semibold text-white">
                    Book a shipment
                </button>
            </div>
        @endcan
    </div>

    {{-- ── Book a shipment ─────────────────────────────────────────────── --}}
    @if ($booking)
        <form wire:submit="book" class="card mt-5 grid gap-4 p-5 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}">Sender</label>
                <select wire:model="senderId" class="{{ $inputClass }}">
                    <option value="">Choose…</option>
                    @foreach ($contacts as $contact)
                        <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                    @endforeach
                </select>
                @error('senderId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Receiver</label>
                <select wire:model="receiverId" class="{{ $inputClass }}">
                    <option value="">Choose…</option>
                    @foreach ($contacts as $contact)
                        <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                    @endforeach
                </select>
                @error('receiverId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $labelClass }}">Cargo</label>
                <input type="text" wire:model="cargo" class="{{ $inputClass }}" placeholder="What is being carried">
                @error('cargo') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            {{-- Route and weight sync on blur so the rate card can quote. --}}
            <div>
                <label class="{{ $labelClass }}">From</label>
                <input type="text" wire:model.blur="fromLocation" class="{{ $inputClass }}">
                @error('fromLocation') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">To</label>
                <input type="text" wire:model.blur="toLocation" class="{{ $inputClass }}">
                @error('toLocation') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Weight (kg)</label>
                <input type="number" step="0.01" wire:model.blur="weightKg" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Declared value</label>
                <input type="number" step="0.01" wire:model="declaredValue" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Freight charge</label>
                <input type="number" step="0.01" wire:model="freightAmount" class="{{ $inputClass }}">
                @if ($proposedFreight !== null && $freightAmount === $proposedFreight)
                    <p class="mt-1 text-[12.5px] text-faint">Proposed from the rate card — edit as agreed.</p>
                @endif
            </div>
            <div class="flex items-end">
                <button type="submit" class="tap focusable rounded-full bg-fill-brand px-6 py-2.5 text-[14.5px] font-semibold text-white">
                    Book
                </button>
            </div>
        </form>
    @endif

    {{-- ── Open a manifest ─────────────────────────────────────────────── --}}
    @if ($opening)
        <form wire:submit="openManifest" class="card mt-5 grid gap-4 p-5 sm:grid-cols-3">
            <div>
                <label class="{{ $labelClass }}">Vehicle</label>
                <select wire:model="vehicleId" class="{{ $inputClass }}">
                    <option value="">Choose…</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}">{{ $vehicle->vehicle?->label() }} — {{ $vehicle->name }}</option>
                    @endforeach
                </select>
                @error('vehicleId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Driver</label>
                <select wire:model="driverId" class="{{ $inputClass }}">
                    <option value="">Unassigned</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $labelClass }}">Departs</label>
                <input type="date" wire:model="departsOn" class="{{ $inputClass }}">
                @error('departsOn') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <button type="submit" class="tap focusable rounded-full bg-fill-brand px-6 py-2.5 text-[14.5px] font-semibold text-white">
                    Open manifest
                </button>
            </div>
        </form>
    @endif

    {{-- ── Failed deliveries — the loudest thing on the board ──────────── --}}
    @if ($exceptions->isNotEmpty())
        <section class="mt-6">
            <h2 class="text-[15px] font-bold text-rose-600">Delivery exceptions <span class="opacity-70">({{ $exceptions->count() }})</span></h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($exceptions as $shipment)
                    <a href="{{ route('logistics.show', $shipment) }}" wire:navigate wire:key="exc-{{ $shipment->id }}"
                       class="block rounded-2xl border border-rose-300 bg-rose-50 p-4 dark:border-rose-500/50 dark:bg-rose-500/10">
                        <p class="text-[14.5px] font-semibold text-rose-700 dark:text-rose-300">{{ $shipment->reference }}</p>
                        <p class="mt-0.5 text-[13.5px] text-rose-800/80 dark:text-rose-200/80">{{ $shipment->cargo_description }}</p>
                        <p class="mt-0.5 text-[13px] text-rose-700/70 dark:text-rose-300/70">
                            {{ $shipment->to_location }} · {{ $shipment->receiver?->name }} — retry or return to sender
                        </p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="mt-6 grid gap-5 lg:grid-cols-3">

        {{-- ── Waiting to travel ───────────────────────────────────────── --}}
        <section>
            <h2 class="text-[15px] font-bold text-ink">Waiting <span class="text-faint">({{ $unassigned->count() }})</span></h2>
            <div class="mt-3 space-y-3">
                @forelse ($unassigned as $shipment)
                    <a href="{{ route('logistics.show', $shipment) }}" wire:navigate wire:key="wait-{{ $shipment->id }}"
                       class="card block p-4">
                        <p class="text-[14.5px] font-semibold text-ink">{{ $shipment->reference }}</p>
                        <p class="mt-0.5 text-[13.5px] text-muted">{{ $shipment->cargo_description }}</p>
                        <p class="mt-0.5 text-[13px] text-faint">
                            {{ $shipment->from_location }} → {{ $shipment->to_location }} ·
                            {{ $shipment->sender?->name }} → {{ $shipment->receiver?->name }}
                        </p>
                    </a>
                @empty
                    <p class="text-[13.5px] text-muted">Nothing waiting.</p>
                @endforelse
            </div>
        </section>

        {{-- ── Open manifests ──────────────────────────────────────────── --}}
        <section>
            <h2 class="text-[15px] font-bold text-ink">Loading <span class="text-faint">({{ $openManifests->count() }})</span></h2>
            <div class="mt-3 space-y-3">
                @forelse ($openManifests as $manifest)
                    <div class="card p-4" wire:key="open-{{ $manifest->id }}">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-[14.5px] font-semibold text-ink">{{ $manifest->reference }}</p>
                            <p class="text-[13px] text-faint">{{ $manifest->departs_on->toFormattedDateString() }}</p>
                        </div>
                        <p class="mt-0.5 text-[13.5px] text-muted">
                            {{ $manifest->vehicle?->vehicle?->label() ?? $manifest->vehicle?->name }}
                            · {{ $manifest->driver?->name ?? 'No driver' }}
                            · {{ $manifest->shipments->count() }} aboard
                        </p>

                        @can('logistics.manage')
                            @if ($loading === $manifest->id)
                                <div class="mt-3 flex gap-2">
                                    <select wire:model="loadShipmentId" class="{{ $inputClass }} h-10">
                                        <option value="">Choose a shipment…</option>
                                        @foreach ($unassigned as $shipment)
                                            <option value="{{ $shipment->id }}">{{ $shipment->reference }} — {{ $shipment->cargo_description }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="load('{{ $manifest->id }}')"
                                            class="tap focusable shrink-0 rounded-full bg-fill-brand px-4 py-2 text-[13.5px] font-semibold text-white">
                                        Load
                                    </button>
                                </div>
                                @error('loadShipmentId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            @else
                                <div class="mt-3 flex gap-2">
                                    <button type="button" wire:click="$set('loading', '{{ $manifest->id }}')"
                                            class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink">
                                        Load cargo
                                    </button>
                                    @if (Route::has('logistics.manifest.print'))
                                        <a href="{{ route('logistics.manifest.print', $manifest) }}" target="_blank"
                                           class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink">
                                            Loading sheet
                                        </a>
                                    @endif
                                    @can('logistics.dispatch')
                                        <button type="button" wire:click="dispatchManifest('{{ $manifest->id }}')"
                                                wire:confirm="Dispatch {{ $manifest->reference }}? Everything aboard goes in transit."
                                                class="tap focusable rounded-full bg-fill-brand px-4 py-2 text-[13.5px] font-semibold text-white">
                                            Dispatch
                                        </button>
                                    @endcan
                                </div>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p class="text-[13.5px] text-muted">No manifests being loaded.</p>
                @endforelse
            </div>
        </section>

        {{-- ── On the road ─────────────────────────────────────────────── --}}
        <section>
            <h2 class="text-[15px] font-bold text-ink">On the road <span class="text-faint">({{ $inTransit->count() }})</span></h2>
            <div class="mt-3 space-y-3">
                @forelse ($inTransit as $manifest)
                    <div class="card p-4" wire:key="road-{{ $manifest->id }}">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-[14.5px] font-semibold text-ink">{{ $manifest->reference }}</p>
                            <p class="text-[13px] text-faint">{{ $manifest->departs_on->toFormattedDateString() }}</p>
                        </div>
                        <p class="mt-0.5 text-[13.5px] text-muted">
                            {{ $manifest->vehicle?->vehicle?->label() ?? $manifest->vehicle?->name }}
                            · {{ $manifest->driver?->name ?? 'No driver' }}
                        </p>
                        <ul class="mt-2 space-y-1">
                            @foreach ($manifest->shipments as $shipment)
                                <li wire:key="road-s-{{ $shipment->id }}" class="text-[13.5px]">
                                    <a href="{{ route('logistics.show', $shipment) }}" wire:navigate class="font-semibold text-ink underline-offset-2 hover:underline">
                                        {{ $shipment->reference }}
                                    </a>
                                    <span @class(['text-rose-600 font-semibold' => $shipment->inException(), 'text-muted' => ! $shipment->inException()])>
                                        — {{ $shipment->receiver?->name }}, {{ $shipment->statusLabel() }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        @can('logistics.manage')
                            @if ($closing === $manifest->id)
                                <div class="mt-3 flex flex-wrap items-end gap-2">
                                    <div>
                                        <label class="block text-[12.5px] font-medium text-muted">Odometer out</label>
                                        <input type="number" min="0" wire:model="startOdometer" placeholder="km"
                                               class="{{ $inputClass }} mt-1 h-10 w-32">
                                    </div>
                                    <div>
                                        <label class="block text-[12.5px] font-medium text-muted">Odometer back</label>
                                        <input type="number" min="0" wire:model="endOdometer" placeholder="km"
                                               class="{{ $inputClass }} mt-1 h-10 w-32">
                                    </div>
                                    <button type="button" wire:click="closeManifest('{{ $manifest->id }}')"
                                            class="tap focusable shrink-0 rounded-full bg-fill-brand px-4 py-2 text-[13.5px] font-semibold text-white">
                                        Close manifest
                                    </button>
                                    <button type="button" wire:click="$set('closing', null)"
                                            class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink">
                                        Cancel
                                    </button>
                                </div>
                                @error('startOdometer') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                @error('endOdometer') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-[12.5px] text-muted">Both readings write the journey into the vehicle's trip log. Leave them blank to close without one.</p>
                            @else
                                <div class="mt-3">
                                    <button type="button" wire:click="startClosing('{{ $manifest->id }}')"
                                            class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink">
                                        Close manifest
                                    </button>
                                </div>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p class="text-[13.5px] text-muted">Nothing on the road.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
