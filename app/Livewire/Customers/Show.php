<?php

namespace App\Livewire\Customers;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public Contact $contact;

    public string $note = '';

    public function mount(Contact $contact): void
    {
        $this->contact = $contact;
    }

    public function addNote(): void
    {
        $this->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->contact->notes()->create([
            'user_id' => auth()->id(),
            'body' => trim($this->note),
        ]);

        $this->note = '';
    }

    public function render(): View
    {
        $documents = Document::query()
            ->where('contact_id', $this->contact->id)
            ->latest('issue_date')
            ->latest('created_at')
            ->limit(10)
            ->get();

        $billed = (float) Document::query()
            ->where('contact_id', $this->contact->id)
            ->invoices()
            ->issued()
            ->sum('total');

        return view('livewire.customers.show', [
            'documents' => $documents,
            'payments' => Payment::query()
                ->where('contact_id', $this->contact->id)
                ->with('receipt')
                ->latest('received_at')
                ->limit(5)
                ->get(),
            'notes' => $this->contact->notes()->with('user')->latest()->limit(10)->get(),
            'stats' => [
                'billed' => $billed,
                'paid' => (float) Payment::query()->where('contact_id', $this->contact->id)->sum('amount'),
                'owing' => (float) $this->contact->balance,
            ],
        ])->layout('components.layouts.app', [
            'title' => $this->contact->displayName(),
            'active' => 'customers',
        ]);
    }
}
