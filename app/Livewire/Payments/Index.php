<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\PaymentRefunder;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $method = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public function setMethod(string $method): void
    {
        $this->method = $method === 'all' || PaymentMethod::tryFrom($method) ? $method : 'all';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    // ── Refunding ────────────────────────────────────────────────────────

    public ?string $refundingId = null;

    public string $refundAmount = '';

    public string $refundMethod = 'cash';

    public string $refundReason = '';

    public function startRefund(string $id): void
    {
        $this->authorize('payments.refund');

        $payment = Payment::findOrFail($id);

        $this->refundingId = $payment->id;
        // Defaults to what is left rather than to the whole payment: a second
        // refund on a partly-refunded payment should not offer to overpay.
        $this->refundAmount = (string) $this->refundableOn($payment);
        $this->refundMethod = $payment->method?->value ?? 'cash';
        $this->refundReason = '';
        $this->resetErrorBag();
    }

    public function cancelRefund(): void
    {
        $this->reset('refundingId', 'refundAmount', 'refundMethod', 'refundReason');
    }

    public function refund(PaymentRefunder $refunder): void
    {
        $this->authorize('payments.refund');

        $this->validate([
            'refundAmount' => ['required', 'numeric', 'gt:0'],
            'refundMethod' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'refundReason' => ['required', 'string', 'max:255'],
        ], [
            'refundReason.required' => 'Say why this is being refunded.',
        ]);

        $payment = Payment::findOrFail($this->refundingId);

        try {
            $refunder->refund(
                payment: $payment,
                actor: auth()->user(),
                amount: (float) $this->refundAmount,
                method: PaymentMethod::from($this->refundMethod),
                reason: $this->refundReason,
            );
        } catch (RuntimeException $e) {
            $this->addError('refundAmount', $e->getMessage());

            return;
        }

        session()->flash('status', 'Refund recorded. The invoice is owed again.');

        $this->cancelRefund();
    }

    /** What is left to give back on a payment. */
    public function refundableOn(Payment $payment): float
    {
        return round(
            (float) $payment->amount - (float) Refund::where('payment_id', $payment->id)->sum('amount'),
            2
        );
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();
        $now = CarbonImmutable::now($company?->timezone ?? 'UTC');

        return view('livewire.payments.index', [
            'payments' => $this->query()->paginate(20),
            'currency' => $company?->currency ?? 'USD',
            'totals' => [
                'today' => $this->sumBetween($now->startOfDay(), $now->endOfDay()),
                'week' => $this->sumBetween($now->startOfWeek(), $now->endOfWeek()),
                'month' => $this->sumBetween($now->startOfMonth(), $now->endOfMonth()),
            ],
        ])->layout('components.layouts.app', ['title' => 'Payments', 'active' => 'payments']);
    }

    protected function sumBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) Payment::query()->whereBetween('received_at', [$from, $to])->sum('amount');
    }

    protected function query(): Builder
    {
        return Payment::query()
            ->with(['contact', 'receipt'])
            ->when($this->method !== 'all', fn (Builder $q) => $q->where('method', $this->method))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('reference', 'like', $term)
                    ->orWhereHas('receipt', fn (Builder $r) => $r->where('number', 'like', $term))
                    ->orWhereHas('contact', fn (Builder $c) => $c
                        ->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)));
            })
            ->latest('received_at');
    }
}
