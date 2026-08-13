<?php

namespace App\Livewire\Deals;

use App\Models\Contact;
use App\Models\Deal;
use App\Services\DealPipeline;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Creating and editing a deal.
 *
 * A plain server-rendered form rather than the offline Alpine pattern the
 * contact form uses: a pipeline is desk work, and a deal typed on a phone with
 * no signal is not the case that keeps a business trading.
 */
class Form extends Component
{
    use AuthorizesRequests;

    public ?Deal $deal = null;

    public string $title = '';

    public ?string $contact_id = null;

    public string $lead_name = '';

    public string $lead_phone = '';

    public string $stage = 'lead';

    public string $value = '';

    public string $expected_close_on = '';

    public string $notes = '';

    public function mount(?Deal $deal = null): void
    {
        $this->deal = $deal?->exists ? $deal : null;

        if ($this->deal) {
            $this->authorize('update', $this->deal);

            $this->title = $this->deal->title;
            $this->contact_id = $this->deal->contact_id;
            $this->lead_name = (string) $this->deal->lead_name;
            $this->lead_phone = (string) $this->deal->lead_phone;
            $this->stage = $this->deal->stage;
            $this->value = (string) $this->deal->value;
            $this->expected_close_on = $this->deal->expected_close_on?->toDateString() ?? '';
            $this->notes = (string) $this->deal->notes;

            return;
        }

        $this->authorize('create', Deal::class);
    }

    public function save(): void
    {
        $this->deal
            ? $this->authorize('update', $this->deal)
            : $this->authorize('create', Deal::class);

        $data = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            // Same rule as the API: a deal is about somebody, but that somebody
            // is often a name and a number before they are a customer record.
            'contact_id' => ['nullable', 'required_without:lead_name', 'string', 'exists:contacts,id'],
            'lead_name' => ['nullable', 'required_without:contact_id', 'string', 'max:150'],
            'lead_phone' => ['nullable', 'string', 'max:40'],
            'stage' => ['required', Rule::in(array_keys(Deal::STAGES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'expected_close_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'title.required' => 'Give this deal a name.',
            'contact_id.required_without' => 'Choose a customer, or type a lead name.',
            'lead_name.required_without' => 'Choose a customer, or type a lead name.',
        ]);

        $data['value'] = $data['value'] === '' ? 0 : $data['value'];
        $data['expected_close_on'] = $data['expected_close_on'] ?: null;

        $pipeline = app(DealPipeline::class);

        $this->deal
            ? $pipeline->update($this->deal, $data)
            : $pipeline->create($data, auth()->user());

        session()->flash('status', $this->deal ? 'Deal updated.' : 'Deal added.');

        $this->redirectRoute('deals', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.deals.form', [
            'contacts' => Contact::query()
                ->whereIn('type', ['customer', 'lead'])
                ->orderBy('name')
                ->get(['id', 'name']),
        ])->layout('components.layouts.app', [
            'title' => $this->deal ? 'Edit deal' : 'New deal',
            'active' => 'deals',
        ]);
    }
}
