<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Services\DocumentIssuer;
use App\Support\CurrentCompany;
use App\Support\Vat;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The document composer.
 *
 * Form state lives in Alpine rather than in Livewire properties, and the whole
 * document is submitted in one call. That is not a stylistic choice: this app is
 * used on phones on weak connections, and a form needing a round trip per
 * keystroke is painful at two bars and impossible at zero. Owning the state on
 * the client is what lets the same form save a real, numbered invoice with no
 * connection at all — see resources/js/offline/.
 *
 * The server remains the authority. Everything Alpine sends is validated here,
 * and issuing still goes through DocumentIssuer.
 */
class Create extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $type = 'invoice';

    public function mount(): void
    {
        $this->type = DocumentType::tryFrom($this->type) ? $this->type : DocumentType::Invoice->value;
    }

    /**
     * Customer search, used while online. Offline the form searches the device's
     * own mirror instead, which holds whatever the last pull brought down.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchContacts(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return Contact::query()
            ->customers()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('company_name', 'like', $like)
                ->orWhere('email', 'like', $like))
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->displayName(),
                'subtitle' => $contact->email ?? data_get($contact->phones, 0, ''),
                'initials' => $contact->initials(),
                'accent' => $contact->accent(),
            ])
            ->all();
    }

    /**
     * Saves the composed document.
     *
     * Errors are returned rather than thrown so the client renders them itself.
     * The offline path has no server to ask, so the messages have to be
     * something the form can display on its own either way — one rendering path,
     * not two that can drift.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(array $payload, bool $issue): array
    {
        try {
            // Drafting and issuing are separate rights: a Sales Officer may
            // prepare a quotation without being able to commit a permanent,
            // numbered document.
            $this->authorize($issue ? 'sales.issue' : 'sales.create');
        } catch (AuthorizationException) {
            return ['ok' => false, 'errors' => ['form' => ['You do not have permission to do this.']]];
        }

        // Rows nobody typed into are dropped rather than rejected. The composer
        // seeds an empty row and "Add Item" appends more, so an untouched one
        // is not an item the user is trying to charge for — it used to fail the
        // save with "Every item needs a description", pointing at a field the
        // layout never labelled. The client compacts before sending; this is
        // here because the server cannot assume the client did, and an offline
        // device can replay a payload built by older code.
        //
        // Quantity is not part of the test: it is seeded to 1, so an untouched
        // row still has one. A row carrying a price but no wording is a real
        // mistake and still earns the error.
        $payload['lines'] = collect($payload['lines'] ?? [])
            ->reject(fn ($line) => trim((string) ($line['description'] ?? '')) === ''
                && trim((string) ($line['unit_price'] ?? '')) === '')
            ->values()
            ->all();

        $validator = validator($payload, [
            'contact_id' => ['required', 'string'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
        ], [
            'contact_id.required' => 'Choose a customer first.',
            'lines.required' => 'Add at least one item.',
            'lines.min' => 'Add at least one item.',
            'lines.*.description.required' => 'Every item needs a description.',
            'lines.*.quantity.gt' => 'Quantity must be more than zero.',
            'lines.*.unit_price.gte' => 'Price cannot be negative.',
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => $validator->errors()->toArray()];
        }

        $data = $validator->validated();

        // Scoped lookup: a contact id from another company fails here rather
        // than silently attaching someone else's customer.
        $contact = Contact::query()->find($data['contact_id']);

        if ($contact === null) {
            return ['ok' => false, 'errors' => ['contact_id' => ['That customer no longer exists.']]];
        }

        $company = app(CurrentCompany::class)->get();

        // TVA is computed once, here, and the per-line figures come back from
        // the same pass that produced the totals — so the tax column on the
        // printed sheet always sums to the tax line beneath it.
        $vat = Vat::forCompany($company, $data['lines']);
        $lines = $this->normalisedLines($data['lines'], $vat['lines']);

        $document = DB::transaction(function () use ($contact, $company, $lines, $vat, $data, $issue) {
            $document = Document::create([
                'type' => $this->type,
                'contact_id' => $contact->id,
                'status' => DocumentStatus::Draft,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency' => $company->currency,
                'subtotal' => $vat['subtotal'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
                'notes' => $data['notes'] ?: null,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $index => $line) {
                DocumentLine::create($line + [
                    'document_id' => $document->id,
                    'unit' => 'unit',
                    'sort_order' => $index,
                ]);
            }

            return $issue
                ? app(DocumentIssuer::class)->issue($document, auth()->user())
                : $document;
        });

        return [
            'ok' => true,
            'id' => $document->id,
            'number' => $document->number,
            'redirect' => route('documents.show', $document),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array{net: float, tax: float, gross: float, unit_net: float}>  $taxed
     * @return array<int, array<string, mixed>>
     */
    protected function normalisedLines(array $lines, array $taxed): array
    {
        return collect($lines)
            ->map(fn (array $line, int $index) => [
                'description' => trim((string) $line['description']),
                'quantity' => (float) $line['quantity'],
                // Always stored net of tax, whichever way it was keyed, so a
                // line means the same thing on every document regardless of
                // whether the business quotes HT or TTC.
                'unit_price' => $taxed[$index]['unit_net'] ?? (float) $line['unit_price'],
                'tax_amount' => $taxed[$index]['tax'] ?? 0.0,
                'line_total' => $taxed[$index]['net'] ?? 0.0,
            ])
            ->values()
            ->all();
    }

    public function render(): View
    {
        $docType = DocumentType::from($this->type);
        $company = app(CurrentCompany::class)->get();

        return view('livewire.documents.create', [
            'docType' => $docType,
            'currency' => $company?->currency ?? 'USD',
            // The preview's letterhead. Injected once at render — the sheet's
            // header never changes while typing, so it costs no round trips
            // and the preview keeps working offline.
            'companyName' => $company?->name ?? '',
            // Only invoices hold device number leases, so only they can be
            // *issued* offline. Everything else still saves as a draft.
            'canIssueOffline' => $docType === DocumentType::Invoice,
        ])->layout('components.layouts.app', [
            'title' => 'New '.$docType->label(),
            'active' => 'sales',
            // The form's own fixed action bar replaces the tab bar on mobile.
            'bottomNav' => false,
        ]);
    }
}
