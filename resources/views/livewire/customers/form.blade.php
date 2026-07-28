@php
    use App\Livewire\Customers\Index;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center gap-3">
        <a href="{{ $contact ? route('customers.show', $contact) : route('customers', ['type' => $type]) }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
            {{ $contact ? 'Edit contact' : 'New '.rtrim(Index::TYPES[$type], 's') }}
        </h1>
    </div>

    <div class="mx-auto mt-5 max-w-[640px] space-y-4">

        @unless ($contact)
            <div class="grid grid-cols-4 gap-2">
                @foreach (Index::TYPES as $key => $label)
                    <button type="button" wire:click="$set('type', '{{ $key }}')"
                            class="focusable h-11 rounded-xl text-[13px] font-semibold transition-colors
                                   {{ $type === $key ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                        {{ rtrim($label, 's') }}
                    </button>
                @endforeach
            </div>
        @endunless

        <x-ui.panel title="Who they are">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="block">
                    <span class="{{ $labelClass }}">Name</span>
                    <input type="text" wire:model="form.name" class="{{ $inputClass }}">
                    @error('form.name') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Business name</span>
                    <input type="text" wire:model="form.company_name" class="{{ $inputClass }}">
                </label>
            </div>
        </x-ui.panel>

        <x-ui.panel title="How to reach them">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="block">
                    <span class="{{ $labelClass }}">Email</span>
                    <input type="email" wire:model="form.email" class="{{ $inputClass }}">
                    @error('form.email') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Phone</span>
                    <input type="tel" wire:model="form.phone" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">WhatsApp</span>
                    <input type="tel" wire:model="form.whatsapp" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Street</span>
                    <input type="text" wire:model="form.street" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">City</span>
                    <input type="text" wire:model="form.city" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Country code</span>
                    <input type="text" wire:model="form.country" maxlength="2" placeholder="NG" class="{{ $inputClass }} uppercase">
                </label>
            </div>
        </x-ui.panel>

        <x-ui.panel title="Billing">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="block">
                    <span class="{{ $labelClass }}">Tax ID</span>
                    <input type="text" wire:model="form.tax_id" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Payment terms (days)</span>
                    <input type="number" min="0" max="365" wire:model="form.payment_terms_days" class="tnum {{ $inputClass }}">
                    @error('form.payment_terms_days') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block min-[560px]:col-span-2">
                    <span class="{{ $labelClass }}">Notes</span>
                    <textarea wire:model="form.notes" rows="2"
                              class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                </label>
            </div>
        </x-ui.panel>

        <button type="button" wire:click="save" wire:loading.attr="disabled"
                class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white hover:opacity-90">
            <span wire:loading.remove wire:target="save">{{ $contact ? 'Save Changes' : 'Add Contact' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>
</div>
