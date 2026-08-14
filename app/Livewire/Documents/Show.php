<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\PaymentRecorder;
use App\Support\Money;
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

    /**
     * Everything that has happened to this document, oldest first.
     *
     * Composed from the records that already exist rather than from a separate
     * activity log, because a log written alongside the facts can disagree with
     * them. Each entry here is derived from the thing itself — the row's own
     * timestamps, its approvals trail, the payments allocated to it, and the
     * document it was converted from — so the history cannot claim something
     * the data does not.
     *
     * The consequence worth knowing: documents issued before the issuer began
     * writing an `issued` row show their issue from `issued_at` instead. Same
     * entry, reconstructed rather than recorded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function history(): array
    {
        $entries = [];

        $entries[] = [
            'at' => $this->document->created_at,
            'icon' => 'plus',
            'accent' => 'slate',
            'title' => 'Created',
            'detail' => $this->document->type->label().' drafted',
            'who' => optional(User::find($this->document->created_by))->name,
        ];

        if ($this->document->parent) {
            $entries[] = [
                'at' => $this->document->created_at,
                'icon' => 'sync',
                'accent' => 'purple',
                'title' => 'Converted',
                'detail' => 'From '.($this->document->parent->number ?? 'a draft'),
                'who' => null,
                'href' => route('documents.show', $this->document->parent),
            ];
        }

        $approvals = $this->document->approvals()->with('user')->orderBy('created_at')->get();

        foreach ($approvals as $approval) {
            $entries[] = [
                'at' => $approval->created_at,
                'icon' => $approval->action === 'voided' ? 'alert' : 'check-circle',
                'accent' => $approval->action === 'voided' ? 'orange' : 'green',
                'title' => ucfirst($approval->action),
                'detail' => $approval->comment,
                'who' => $approval->user?->name,
            ];
        }

        // Older documents predate the issuer recording its own row.
        if ($this->document->issued_at && ! $approvals->contains('action', 'issued')) {
            $entries[] = [
                'at' => $this->document->issued_at,
                'icon' => 'check-circle',
                'accent' => 'green',
                'title' => 'Issued',
                'detail' => $this->document->number,
                'who' => optional(User::find($this->document->issued_by))->name,
            ];
        }

        foreach ($this->document->allocations()->with('payment.receipt')->get() as $allocation) {
            $payment = $allocation->payment;

            if ($payment === null) {
                continue;
            }

            $entries[] = [
                'at' => $payment->received_at ?? $payment->created_at,
                'icon' => 'banknotes',
                'accent' => 'blue',
                'title' => 'Payment received',
                'detail' => Money::format($allocation->amount, $this->document->currency)
                    .' · '.($payment->method?->label() ?? $payment->method?->value)
                    .($payment->receipt?->number ? ' · '.$payment->receipt->number : ''),
                'who' => optional(User::find($payment->received_by))->name,
            ];
        }

        usort($entries, fn ($a, $b) => ($a['at']?->timestamp ?? 0) <=> ($b['at']?->timestamp ?? 0));

        return $entries;
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
            'history' => $this->history(),
        ])->layout('components.layouts.app', [
            'title' => $this->document->number ?? 'Document',
            'active' => 'sales',
        ]);
    }
}
