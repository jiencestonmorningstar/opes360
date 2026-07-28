<?php

namespace App\Livewire\Documents;

use App\Enums\PaymentMethod;
use App\Models\Document;
use App\Services\PaymentRecorder;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

class Show extends Component
{
    public Document $document;

    public bool $payingOpen = false;

    public string $amount = '';

    public string $method = 'cash';

    public string $reference = '';

    public function mount(Document $document): void
    {
        // Route model binding already applied the tenant scope; loading here keeps
        // the view free of lazy loads (which are fatal in development).
        $this->document = $this->loaded($document);
    }

    public function openPayment(): void
    {
        $this->payingOpen = true;
        // Prefill with the outstanding balance — settling in full is the common
        // case; part-payment means editing down, not typing from scratch.
        $this->amount = (string) $this->document->balance;
        $this->resetErrorBag();
    }

    public function closePayment(): void
    {
        $this->payingOpen = false;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,bank_transfer,mobile_money,card'],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [
            'amount.gt' => 'Enter an amount greater than zero.',
        ]);

        try {
            app(PaymentRecorder::class)->record(
                document: $this->document,
                cashier: auth()->user(),
                amount: (float) $this->amount,
                method: PaymentMethod::from($this->method),
                reference: $this->reference !== '' ? $this->reference : null,
            );
        } catch (RuntimeException $e) {
            // Service guards (overpayment, wrong status) surface as field errors
            // rather than a 500 — the user can correct and retry in place.
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->reset('payingOpen', 'amount', 'reference');
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
        return view('livewire.documents.show')
            ->layout('components.layouts.app', [
                'title' => $this->document->number ?? 'Document',
                'active' => 'sales',
            ]);
    }
}
