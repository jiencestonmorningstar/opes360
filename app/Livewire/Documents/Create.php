<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Services\DocumentIssuer;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $type = 'invoice';

    public ?string $contactId = null;

    public string $contactSearch = '';

    /** @var array<int, array{description: string, quantity: string, unit_price: string}> */
    public array $lines = [];

    public string $issueDate = '';

    public ?string $dueDate = null;

    public string $notes = '';

    public function mount(): void
    {
        $this->type = DocumentType::tryFrom($this->type) ? $this->type : DocumentType::Invoice->value;
        $this->issueDate = now()->toDateString();
        $this->dueDate = now()->addDays(14)->toDateString();
        $this->lines = [$this->blankLine()];
    }

    /** @return array{description: string, quantity: string, unit_price: string} */
    protected function blankLine(): array
    {
        return ['description' => '', 'quantity' => '1', 'unit_price' => ''];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        // The form always keeps at least one row; "remove the last row" means
        // "clear it", which is what a user poking at a phone actually intends.
        if (count($this->lines) === 1) {
            $this->lines = [$this->blankLine()];

            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function selectContact(string $id): void
    {
        $this->contactId = Contact::query()->whereKey($id)->exists() ? $id : null;
        $this->contactSearch = '';
        $this->resetErrorBag('contactId');
    }

    public function clearContact(): void
    {
        $this->contactId = null;
    }

    public function saveDraft(): void
    {
        $this->persist(issue: false);
    }

    public function saveAndIssue(): void
    {
        $this->persist(issue: true);
    }

    protected function persist(bool $issue): void
    {
        // Drafting and issuing are separate rights: a Sales Officer may prepare
        // a quotation without being able to commit a permanent numbered document.
        $this->authorize($issue ? 'sales.issue' : 'sales.create');

        $this->validate([
            'contactId' => ['required'],
            'issueDate' => ['required', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:issueDate'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
        ], [
            'contactId.required' => 'Choose a customer first.',
            'lines.*.description.required' => 'Every item needs a description.',
            'lines.*.quantity.gt' => 'Quantity must be more than zero.',
            'lines.*.unit_price.gte' => 'Price cannot be negative.',
        ]);

        // Scoped lookup: a contact id from another company fails here rather than
        // silently attaching someone else's customer.
        $contact = Contact::query()->findOrFail($this->contactId);
        $company = app(CurrentCompany::class)->get();
        $totals = $this->totals();

        $document = DB::transaction(function () use ($contact, $company, $totals, $issue) {
            $document = Document::create([
                'type' => $this->type,
                'contact_id' => $contact->id,
                'status' => DocumentStatus::Draft,
                'issue_date' => $this->issueDate,
                'due_date' => $this->dueDate,
                'currency' => $company->currency,
                'subtotal' => $totals['subtotal'],
                'total' => $totals['total'],
                'amount_paid' => 0,
                'balance' => $totals['total'],
                'notes' => $this->notes !== '' ? $this->notes : null,
                'created_by' => auth()->id(),
            ]);

            foreach ($this->lines as $index => $line) {
                DocumentLine::create([
                    'document_id' => $document->id,
                    'description' => trim($line['description']),
                    'quantity' => (float) $line['quantity'],
                    'unit' => 'unit',
                    'unit_price' => (float) $line['unit_price'],
                    'line_total' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'sort_order' => $index,
                ]);
            }

            if ($issue) {
                $document = app(DocumentIssuer::class)->issue($document, auth()->user());
            }

            return $document;
        });

        $this->redirectRoute('documents.show', $document);
    }

    /** @return array{subtotal: float, total: float} */
    public function totals(): array
    {
        $subtotal = round(collect($this->lines)->sum(
            fn (array $line) => (float) ($line['quantity'] ?: 0) * (float) ($line['unit_price'] ?: 0)
        ), 2);

        return ['subtotal' => $subtotal, 'total' => $subtotal];
    }

    public function render(): View
    {
        $docType = DocumentType::from($this->type);

        return view('livewire.documents.create', [
            'docType' => $docType,
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'USD',
            'selectedContact' => $this->contactId ? Contact::find($this->contactId) : null,
            'contactResults' => $this->contactResults(),
        ])->layout('components.layouts.app', [
            'title' => 'New '.$docType->label(),
            'active' => 'sales',
            // The form's own fixed action bar replaces the tab bar on mobile.
            'bottomNav' => false,
        ]);
    }

    protected function contactResults()
    {
        if ($this->contactId !== null || mb_strlen(trim($this->contactSearch)) < 1) {
            return collect();
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->contactSearch)).'%';

        return Contact::query()
            ->customers()
            ->where(fn ($q) => $q
                ->where('name', 'like', $term)
                ->orWhere('company_name', 'like', $term)
                ->orWhere('email', 'like', $term))
            ->orderBy('name')
            ->limit(6)
            ->get();
    }
}
