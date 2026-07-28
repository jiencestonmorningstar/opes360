@php
    use App\Livewire\Customers\Index;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

{{-- State lives in Alpine so a new contact can be added with no connection —
     see resources/js/forms/record.js. --}}
<div class="px-5 pb-8 lg:px-6 lg:pt-6"
     x-data="opesRecordForm({
        entityType: 'contact',
        isEdit: @js((bool) $contact),
        initial: @js($initial),
        rules: {
            name: { required: true, message: 'Give this contact a name.' },
            email: { email: true },
            payment_terms_days: { numeric: true },
        },
        toPayload: (form) => ({
            type: form.type,
            name: form.name.trim(),
            company_name: form.company_name || null,
            email: form.email ? form.email.trim().toLowerCase() : null,
            phones: [form.phone].filter(Boolean),
            whatsapp: form.whatsapp || null,
            address: Object.fromEntries(Object.entries({
                street: form.street || null,
                city: form.city || null,
                country: form.country ? form.country.toUpperCase() : null,
            }).filter(([, v]) => v)),
            tax_id: form.tax_id || null,
            payment_terms_days: form.payment_terms_days === '' ? null : Number(form.payment_terms_days),
            notes: form.notes || null,
        }),
        label: (form) => form.company_name || form.name,
     })">

    <div class="flex items-center gap-3">
        <a href="{{ $contact ? route('customers.show', $contact) : route('customers', ['type' => $type]) }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
            {{ $contact ? 'Edit contact' : 'New '.rtrim(Index::TYPES[$type], 's') }}
        </h1>
    </div>

    {{-- Offline confirmation: there is no server page to send them to yet. --}}
    <template x-if="savedOffline">
        <div class="card mx-auto mt-5 max-w-[640px] p-6 text-center">
            <span class="mx-auto flex size-[52px] items-center justify-center rounded-full bg-tint-green">
                <x-icon name="check-circle" class="size-[26px] text-accent-green" stroke-width="2.2" />
            </span>
            <h2 class="mt-4 text-[19px] font-bold tracking-[-0.02em] text-ink">
                <span x-text="savedOffline.name"></span> saved on this device
            </h2>
            <p class="mt-1.5 text-[14px] text-muted">
                They're ready to invoice now, and will sync when you're back online.
            </p>
            <div class="mt-5 flex gap-3">
                <a href="{{ route('customers', ['type' => $type]) }}"
                   class="focusable flex h-12 flex-1 items-center justify-center rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                    Done
                </a>
                <button type="button" @click="startAnother()"
                        class="focusable h-12 flex-[1.4] rounded-xl bg-brand text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    Add Another
                </button>
            </div>
        </div>
    </template>

    <div class="mx-auto mt-5 max-w-[640px] space-y-4" x-show="! savedOffline">

        @unless ($contact)
            <div class="grid grid-cols-4 gap-2">
                @foreach (Index::TYPES as $key => $label)
                    <button type="button" @click="form.type = @js($key)"
                            class="focusable h-11 rounded-xl text-[13px] font-semibold transition-colors"
                            :class="form.type === @js($key)
                                ? 'bg-tint-blue text-brand ring-1 ring-brand/40'
                                : 'border border-border bg-surface text-ink-2 hover:bg-surface-2'">
                        {{ rtrim($label, 's') }}
                    </button>
                @endforeach
            </div>
        @endunless

        <x-ui.panel title="Who they are">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="block">
                    <span class="{{ $labelClass }}">Name</span>
                    <input type="text" x-model="form.name" class="{{ $inputClass }}">
                    <p class="mt-1 text-[12.5px] font-medium text-warning" x-cloak
                       x-show="error('name')" x-text="error('name')"></p>
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Business name</span>
                    <input type="text" x-model="form.company_name" class="{{ $inputClass }}">
                </label>
            </div>
        </x-ui.panel>

        <x-ui.panel title="How to reach them">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="block">
                    <span class="{{ $labelClass }}">Email</span>
                    <input type="email" x-model="form.email" class="{{ $inputClass }}">
                    <p class="mt-1 text-[12.5px] font-medium text-warning" x-cloak
                       x-show="error('email')" x-text="error('email')"></p>
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Phone</span>
                    <input type="tel" x-model="form.phone" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">WhatsApp</span>
                    <input type="tel" x-model="form.whatsapp" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Street</span>
                    <input type="text" x-model="form.street" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">City</span>
                    <input type="text" x-model="form.city" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Country code</span>
                    <input type="text" x-model="form.country" maxlength="2" placeholder="NG" class="{{ $inputClass }} uppercase">
                </label>
            </div>
        </x-ui.panel>

        <x-ui.panel title="Billing">
            <div class="grid gap-4 min-[560px]:grid-cols-2">
                <label class="block">
                    <span class="{{ $labelClass }}">Tax ID</span>
                    <input type="text" x-model="form.tax_id" class="{{ $inputClass }}">
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Payment terms (days)</span>
                    <input type="number" min="0" max="365" x-model="form.payment_terms_days" class="tnum {{ $inputClass }}">
                    <p class="mt-1 text-[12.5px] font-medium text-warning" x-cloak
                       x-show="error('payment_terms_days')" x-text="error('payment_terms_days')"></p>
                </label>
                <label class="block min-[560px]:col-span-2">
                    <span class="{{ $labelClass }}">Notes</span>
                    <textarea x-model="form.notes" rows="2"
                              class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                </label>
            </div>
        </x-ui.panel>

        <p class="rounded-lg bg-tint-orange px-3 py-2 text-[12.5px] font-medium text-warning" x-cloak
           x-show="error('form')" x-text="error('form')"></p>

        <button type="button" @click="save()" :disabled="saving"
                class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white hover:opacity-90 disabled:opacity-60">
            <span x-show="! saving">{{ $contact ? 'Save Changes' : 'Add Contact' }}</span>
            <span x-show="saving" x-cloak>Saving…</span>
        </button>
    </div>
</div>
