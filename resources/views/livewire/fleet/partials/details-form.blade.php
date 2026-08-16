@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

{{--
    Only the columns a generator has no business carrying. What it cost, where
    it is and who has it are the register's answers and are not repeated here.
--}}
<tr class="border-t border-border bg-fill-2">
    <td colspan="5" class="px-4 py-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="{{ $labelClass }}">Registration</label>
                <input type="text" wire:model="registration" placeholder="LT 4821 AB" class="{{ $inputClass }}">
                @error('registration') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Chassis (VIN)</label>
                <input type="text" wire:model="vin" placeholder="Optional" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Make</label>
                <input type="text" wire:model="make" placeholder="Toyota" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Model</label>
                <input type="text" wire:model="model" placeholder="Hiace" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Year</label>
                <input type="number" wire:model="year" class="{{ $inputClass }}">
                @error('year') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Fuel</label>
                <select wire:model="fuelType" class="{{ $inputClass }}">
                    <option value="">Not said</option>
                    @foreach ($fuelTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $labelClass }}">Tank (litres)</label>
                <input type="number" step="0.01" wire:model="tankLitres" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Insurance until</label>
                <input type="date" wire:model="insuranceExpiresOn" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Roadworthiness until</label>
                <input type="date" wire:model="roadworthyExpiresOn" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Licence until</label>
                <input type="date" wire:model="licenceExpiresOn" class="{{ $inputClass }}">
            </div>
        </div>

        <div class="mt-3 flex gap-2">
            <button type="button" wire:click="saveVehicle"
                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                Save
            </button>
            <button type="button" wire:click="$set('editing', null)"
                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                Cancel
            </button>
        </div>
    </td>
</tr>
