<?php

namespace App\Livewire\Documents;

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

        return view('livewire.documents.show', [
            'canConvert' => $converter->canConvert($this->document),
            'convertTarget' => $converter->targetType($this->document),
        ])->layout('components.layouts.app', [
            'title' => $this->document->number ?? 'Document',
            'active' => 'sales',
        ]);
    }
}
