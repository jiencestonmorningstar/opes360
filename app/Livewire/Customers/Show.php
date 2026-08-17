<?php

namespace App\Livewire\Customers;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use App\Services\Documents\CustomDocumentTemplates;
use App\Services\Documents\RecordDocumentComposer;
use App\Services\LoyaltyLedger;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use RuntimeException;

class Show extends Component
{
    use AuthorizesRequests;

    public Contact $contact;

    public string $note = '';

    public string $redeemPoints = '';

    public string $redeemNote = '';

    public bool $composing = false;

    public string $composeTemplate = '';

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

    public function issueLoyaltyCard(LoyaltyLedger $loyalty): void
    {
        $this->authorize('update', $this->contact);
        $this->authorize('loyalty.manage');

        $this->contact = $loyalty->issueCard($this->contact);
    }

    public function redeemLoyaltyPoints(LoyaltyLedger $loyalty): void
    {
        $this->authorize('view', $this->contact);
        $this->authorize('loyalty.redeem');

        $this->validate([
            'redeemPoints' => ['required', 'integer', 'min:1'],
            'redeemNote' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $loyalty->redeem($this->contact, (int) $this->redeemPoints, $this->redeemNote ?: null, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('redeemPoints', $e->getMessage());

            return;
        }

        $this->contact = $this->contact->fresh();
        $this->redeemPoints = '';
        $this->redeemNote = '';
    }

    /**
     * A draft pre-filled with this customer's own details, so composing a
     * letter no longer means typing their name and address by hand into a
     * blank template. The customer is linked back the same way the manual
     * "attach to a record" action on the document's own screen would do it.
     */
    public function composeForCustomer(RecordDocumentComposer $composer): void
    {
        $this->authorize('papers.create');

        $this->validate(['composeTemplate' => ['required', 'string']]);

        $template = DocumentTemplates::find($this->composeTemplate)
            ?? app(CustomDocumentTemplates::class)->find($this->composeTemplate);

        if ($template === null) {
            $this->addError('composeTemplate', 'That template no longer exists.');

            return;
        }

        $document = $composer->composeDraft(
            record: $this->contact,
            templateKey: $this->composeTemplate,
            fields: [],
            title: ($template['title'] ?? $template['name'] ?? 'Document').' — '.$this->contact->displayName(),
            company: app(CurrentCompany::class)->get(),
            user: auth()->user(),
        );

        $this->redirectRoute('papers.edit', $document, navigate: true);
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
            'templates' => DocumentTemplates::all() + app(CustomDocumentTemplates::class)->allPublishedAsArray(),
            'payments' => Payment::query()
                ->where('contact_id', $this->contact->id)
                ->with('receipt')
                ->latest('received_at')
                ->limit(5)
                ->get(),
            'notes' => $this->contact->notes()->with('user')->latest()->limit(10)->get(),
            'loyaltyTransactions' => $this->contact->loyaltyTransactions()->latest()->limit(10)->get(),
            'loyaltyEnabled' => app(CurrentCompany::class)->get()?->loyalty_enabled ?? false,
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
