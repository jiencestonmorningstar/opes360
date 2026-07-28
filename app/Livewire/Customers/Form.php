<?php

namespace App\Livewire\Customers;

use App\Models\Contact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Contact form.
 *
 * State lives in Alpine and the whole record is submitted in one call, which is
 * what lets a new walk-in customer be added with no connection — without that,
 * an offline invoice could only ever go to someone already on the device.
 * See resources/js/forms/record.js.
 */
class Form extends Component
{
    use AuthorizesRequests;

    public ?Contact $contact = null;

    #[Url]
    public string $type = 'customer';

    public function mount(?Contact $contact = null): void
    {
        $this->contact = $contact?->exists ? $contact : null;

        if ($this->contact) {
            $this->type = $this->contact->type;
        } elseif (! array_key_exists($this->type, Index::TYPES)) {
            $this->type = 'customer';
        }
    }

    /**
     * The form's starting values, in the shape the client holds them.
     *
     * @return array<string, mixed>
     */
    public function initial(): array
    {
        $contact = $this->contact;

        return [
            'type' => $this->type,
            'name' => $contact?->name ?? '',
            'company_name' => $contact?->company_name ?? '',
            'email' => $contact?->email ?? '',
            'phone' => $contact ? data_get($contact->phones, 0, '') : '',
            'whatsapp' => $contact?->whatsapp ?? '',
            'street' => $contact ? data_get($contact->address, 'street', '') : '',
            'city' => $contact ? data_get($contact->address, 'city', '') : '',
            'country' => $contact ? data_get($contact->address, 'country', '') : '',
            'tax_id' => $contact?->tax_id ?? '',
            'payment_terms_days' => $contact?->payment_terms_days !== null
                ? (string) $contact->payment_terms_days
                : '',
            'notes' => $contact?->notes ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function save(array $form): array
    {
        try {
            $this->contact
                ? $this->authorize('update', $this->contact)
                : $this->authorize('customers.create');
        } catch (AuthorizationException) {
            return ['ok' => false, 'errors' => ['form' => ['You do not have permission to do this.']]];
        }

        $validator = validator($form, [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'payment_terms_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
        ], [
            'name.required' => 'Give this contact a name.',
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => $validator->errors()->toArray()];
        }

        $type = array_key_exists($form['type'] ?? '', Index::TYPES) ? $form['type'] : $this->type;

        $attributes = self::attributesFrom($form) + ['type' => $type];

        if ($this->contact) {
            $this->contact->update($attributes);
        } else {
            $this->contact = Contact::create($attributes + ['created_by' => auth()->id()]);
        }

        return [
            'ok' => true,
            'id' => $this->contact->id,
            'redirect' => route('customers.show', $this->contact),
        ];
    }

    /**
     * Form fields to database columns.
     *
     * Public and static because the sync engine applies the identical mapping to
     * a contact created offline. Two versions of this would drift, and the
     * difference would show up as a customer whose address vanished on sync.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function attributesFrom(array $form): array
    {
        // Every field is read defensively. This mapping is reached from the
        // form, from a sync envelope and from tests, and a payload that happens
        // to omit an optional field should leave it empty rather than fatal.
        $get = fn (string $key) => $form[$key] ?? null;
        $taxId = trim((string) $get('tax_id'));

        return [
            'name' => trim((string) $get('name')),
            'company_name' => $get('company_name') ?: null,
            'email' => filled($get('email')) ? strtolower(trim((string) $get('email'))) : null,
            'phones' => array_values(array_filter([$get('phone')])),
            'whatsapp' => $get('whatsapp') ?: null,
            'address' => array_filter([
                'street' => $get('street') ?: null,
                'city' => $get('city') ?: null,
                'country' => filled($get('country')) ? strtoupper((string) $get('country')) : null,
            ]) ?: null,
            'tax_id' => $taxId ?: null,
            // The blind index is derived, never sent: a device must not be able
            // to decide how a tax ID is looked up.
            'tax_id_index' => $taxId !== ''
                ? hash('sha256', preg_replace('/\W+/', '', $taxId))
                : null,
            'payment_terms_days' => filled($get('payment_terms_days'))
                ? (int) $get('payment_terms_days')
                : null,
            'notes' => $get('notes') ?: null,
        ];
    }

    public function render(): View
    {
        return view('livewire.customers.form', ['initial' => $this->initial()])
            ->layout('components.layouts.app', [
                'title' => $this->contact ? 'Edit '.$this->contact->displayName() : 'New '.Index::TYPES[$this->type],
                'active' => 'customers',
                'bottomNav' => false,
            ]);
    }
}
