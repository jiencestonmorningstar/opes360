<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Document;
use App\Models\Payment;
use App\Services\DocumentConverter;
use App\Services\PaymentRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use RuntimeException;

class Show extends Component
{
    use AuthorizesRequests;

    public Document $document;

    public bool $voidingOpen = false;

    public string $voidReason = '';

    public bool $creditingOpen = false;

    public string $creditAmount = '';

    public string $creditReason = '';

    public function mount(Document $document): void
    {
        // Route model binding already applied the tenant scope; loading here keeps
        // the view free of lazy loads (which are fatal in development).
        $this->document = $this->loaded($document);
    }

    /**
     * Records a payment.
     *
     * The panel owns its own state so it can also complete with no connection
     * (see resources/js/forms/payment.js), which means errors are returned for
     * the client to render rather than thrown — the offline path has no server
     * to ask, and the two must not show different messages.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function recordPayment(array $form): array
    {
        try {
            // Route middleware only gates opening the page; each action
            // re-checks, so a crafted Livewire call cannot bypass the right.
            $this->authorize('record', Payment::class);
        } catch (AuthorizationException) {
            return ['ok' => false, 'errors' => ['amount' => ['You do not have permission to record payments.']]];
        }

        $validator = validator($form, [
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,bank_transfer,mobile_money,card'],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [
            'amount.gt' => 'Enter an amount greater than zero.',
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => $validator->errors()->toArray()];
        }

        try {
            app(PaymentRecorder::class)->record(
                document: $this->document,
                cashier: auth()->user(),
                amount: (float) $form['amount'],
                method: PaymentMethod::from($form['method']),
                reference: filled($form['reference'] ?? null) ? $form['reference'] : null,
            );
        } catch (RuntimeException $e) {
            // Service guards (overpayment, wrong status) come back as a field
            // error so the user can correct and retry in place.
            return ['ok' => false, 'errors' => ['amount' => [$e->getMessage()]]];
        }

        $this->document = $this->loaded($this->document->fresh());

        return ['ok' => true];
    }

    public function convert(): void
    {
        $this->authorize('convert', $this->document);

        try {
            $created = app(DocumentConverter::class)->convert($this->document, auth()->user());
        } catch (RuntimeException $e) {
            session()->flash('documentError', $e->getMessage());

            return;
        }

        $this->redirectRoute('documents.show', $created);
    }

    /**
     * Credit part of this invoice.
     *
     * Separate from `convert()` because they are different acts: converting
     * cancels the invoice outright, and this gives back an agreed amount of a
     * bill the customer is still otherwise paying.
     */
    public function openCredit(): void
    {
        $this->authorize('convert', $this->document);

        $this->creditAmount = number_format(
            app(DocumentConverter::class)->creditableAmount($this->document), 2, '.', ''
        );
        $this->creditReason = '';
        $this->resetErrorBag();
        $this->creditingOpen = true;
    }

    public function closeCredit(): void
    {
        $this->creditingOpen = false;
    }

    public function issueCredit(): void
    {
        $this->authorize('convert', $this->document);

        $this->validate([
            'creditAmount' => ['required', 'numeric', 'gt:0'],
            'creditReason' => ['nullable', 'string', 'max:200'],
        ], [
            'creditAmount.gt' => 'A credit note for nothing is not a credit note.',
        ]);

        try {
            $note = app(DocumentConverter::class)->creditNote(
                $this->document,
                auth()->user(),
                (float) $this->creditAmount,
                $this->creditReason !== '' ? $this->creditReason : null,
            );
        } catch (RuntimeException $e) {
            $this->addError('creditAmount', $e->getMessage());

            return;
        }

        $this->redirectRoute('documents.show', $note);
    }

    public function openVoid(): void
    {
        $this->voidingOpen = true;
        $this->resetErrorBag();
    }

    public function closeVoid(): void
    {
        $this->voidingOpen = false;
    }

    public function voidDocument(): void
    {
        $this->authorize('void', $this->document);

        try {
            app(DocumentConverter::class)->void(
                $this->document,
                auth()->user(),
                $this->voidReason !== '' ? $this->voidReason : null,
            );
        } catch (RuntimeException $e) {
            $this->addError('voidReason', $e->getMessage());

            return;
        }

        $this->reset('voidingOpen', 'voidReason');
        $this->document = $this->loaded($this->document->fresh());
    }

    protected function loaded(Document $document): Document
    {
        return $document->load([
            'contact',
            'lines',
            'allocations.payment.receipt',
            'verificationToken',
        ]);
    }

    public function render(): View
    {
        $converter = app(DocumentConverter::class);

        $isInvoice = $this->document->type === DocumentType::Invoice;

        return view('livewire.documents.show', [
            'canConvert' => $converter->canConvert($this->document),
            'convertTarget' => $converter->targetType($this->document),
            // What has been given back on this invoice, and what is left to
            // give. Shown on the invoice itself, because "why does this say
            // 100 000 when we agreed 80 000" is answered by the credit note
            // that nobody thinks to look for.
            'creditedTotal' => $isInvoice ? $converter->creditedTotal($this->document) : 0.0,
            'creditableAmount' => $isInvoice ? $converter->creditableAmount($this->document) : 0.0,
            'creditNotes' => $isInvoice
                ? Document::query()
                    ->where('parent_document_id', $this->document->id)
                    ->ofType(DocumentType::CreditNote)
                    ->issued()
                    ->orderBy('issue_date')
                    ->get(['id', 'number', 'issue_date', 'total', 'notes'])
                : collect(),
        ])->layout('components.layouts.app', [
            'title' => $this->document->number ?? 'Document',
            'active' => 'sales',
        ]);
    }
}
