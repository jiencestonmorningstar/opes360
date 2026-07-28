<?php

namespace App\Livewire\Customers;

use App\Models\Contact;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesRequests;

    public ?Contact $contact = null;

    #[Url]
    public string $type = 'customer';

    /** @var array<string, mixed> */
    public array $form = [
        'name' => '',
        'company_name' => '',
        'email' => '',
        'phone' => '',
        'whatsapp' => '',
        'street' => '',
        'city' => '',
        'country' => '',
        'tax_id' => '',
        'payment_terms_days' => '',
        'notes' => '',
    ];

    public function mount(?Contact $contact = null): void
    {
        $this->contact = $contact?->exists ? $contact : null;

        if ($this->contact) {
            $this->type = $this->contact->type;
            $this->form = [
                'name' => $this->contact->name,
                'company_name' => $this->contact->company_name,
                'email' => $this->contact->email,
                'phone' => data_get($this->contact->phones, 0),
                'whatsapp' => $this->contact->whatsapp,
                'street' => data_get($this->contact->address, 'street'),
                'city' => data_get($this->contact->address, 'city'),
                'country' => data_get($this->contact->address, 'country'),
                'tax_id' => $this->contact->tax_id,
                'payment_terms_days' => $this->contact->payment_terms_days !== null
                    ? (string) $this->contact->payment_terms_days
                    : '',
                'notes' => $this->contact->notes,
            ];
        } elseif (! array_key_exists($this->type, Index::TYPES)) {
            $this->type = 'customer';
        }
    }

    public function save(): void
    {
        $this->contact
            ? $this->authorize('update', $this->contact)
            : $this->authorize('customers.create');

        $this->validate([
            'form.name' => ['required', 'string', 'max:160'],
            'form.email' => ['nullable', 'email', 'max:160'],
            'form.phone' => ['nullable', 'string', 'max:40'],
            'form.payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [
            'form.name.required' => 'Give this contact a name.',
        ]);

        $attributes = [
            'type' => $this->type,
            'name' => trim($this->form['name']),
            'company_name' => $this->form['company_name'] ?: null,
            'email' => $this->form['email'] ? strtolower(trim($this->form['email'])) : null,
            'phones' => array_values(array_filter([$this->form['phone']])),
            'whatsapp' => $this->form['whatsapp'] ?: null,
            'address' => array_filter([
                'street' => $this->form['street'] ?: null,
                'city' => $this->form['city'] ?: null,
                'country' => $this->form['country'] ? strtoupper($this->form['country']) : null,
            ]) ?: null,
            'tax_id' => $this->form['tax_id'] ?: null,
            'tax_id_index' => $this->form['tax_id']
                ? hash('sha256', preg_replace('/\W+/', '', $this->form['tax_id']))
                : null,
            'payment_terms_days' => $this->form['payment_terms_days'] !== ''
                ? (int) $this->form['payment_terms_days']
                : null,
            'notes' => $this->form['notes'] ?: null,
        ];

        if ($this->contact) {
            $this->contact->update($attributes);
        } else {
            $this->contact = Contact::create($attributes + ['created_by' => auth()->id()]);
        }

        $this->redirectRoute('customers.show', $this->contact);
    }

    public function render(): View
    {
        return view('livewire.customers.form')
            ->layout('components.layouts.app', [
                'title' => $this->contact ? 'Edit '.$this->contact->displayName() : 'New '.Index::TYPES[$this->type],
                'active' => 'customers',
                'bottomNav' => false,
            ]);
    }
}
