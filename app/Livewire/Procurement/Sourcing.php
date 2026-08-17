<?php

namespace App\Livewire\Procurement;

use App\Models\Contact;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\SupplierQuotation;
use App\Services\Procurement\Sourcing as SourcingService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Asking suppliers what it costs, and choosing one of the answers.
 *
 * The comparison is the point of the screen. It is ordered by price and by
 * nothing else: lead time and payment terms sit beside each row so the buyer
 * weighs them, because a combined score would quietly decide what a week of
 * delay is worth and leave nobody able to argue with the result.
 */
class Sourcing extends Component
{
    #[Url]
    public string $tab = 'rfqs'; // rfqs|approved

    /** The RFQ on screen, if any. */
    #[Url]
    public ?string $rfqId = null;

    // ── Opening an RFQ from an approved requisition ─────────────────────
    public ?string $opening = null;

    public string $rfqTitle = '';

    public string $closesOn = '';

    public string $terms = '';

    // ── Inviting ────────────────────────────────────────────────────────
    /** @var array<int, string> */
    public array $invitees = [];

    // ── Recording what came back ────────────────────────────────────────
    public bool $recording = false;

    public ?string $quotingSupplierId = null;

    public string $reference = '';

    public string $quotedOn = '';

    public string $validUntil = '';

    public string $leadTimeDays = '';

    public string $paymentTerms = '';

    /** rfq line id => ['unit_price' => string, 'tax_amount' => string] */
    public array $prices = [];

    public function mount(): void
    {
        Gate::authorize('procurement.rfq-view');

        $this->quotedOn = now()->toDateString();
    }

    public function openFor(string $requisitionId): void
    {
        Gate::authorize('procurement.rfq-manage');

        $requisition = PurchaseRequisition::query()->findOrFail($requisitionId);

        $this->opening = $requisition->id;
        $this->rfqTitle = $requisition->title;
        $this->closesOn = now()->addWeek()->toDateString();
        $this->terms = '';
        $this->resetValidation();
    }

    public function openRfq(): void
    {
        Gate::authorize('procurement.rfq-manage');

        $this->validate([
            'rfqTitle' => ['required', 'string', 'max:180'],
            'closesOn' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:1000'],
        ]);

        $requisition = PurchaseRequisition::query()->findOrFail($this->opening);

        try {
            $rfq = app(SourcingService::class)->openRfq($requisition, [
                'title' => $this->rfqTitle,
                'closes_on' => $this->closesOn ?: null,
                'terms' => $this->terms ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('opening', $e->getMessage());

            return;
        }

        $this->opening = null;
        $this->tab = 'rfqs';
        $this->rfqId = $rfq->id;

        session()->flash('status', "{$rfq->number} opened. Invite the suppliers you want to hear from.");
    }

    public function select(?string $id): void
    {
        $this->rfqId = $this->rfqId === $id ? null : $id;
        $this->recording = false;
        $this->invitees = [];
        $this->resetValidation();
    }

    public function invite(): void
    {
        Gate::authorize('procurement.rfq-manage');

        $rfq = Rfq::query()->findOrFail($this->rfqId);

        if ($this->invitees === []) {
            $this->addError('invitees', 'Choose at least one supplier to ask.');

            return;
        }

        try {
            app(SourcingService::class)->invite($rfq, array_values($this->invitees), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('invitees', $e->getMessage());

            return;
        }

        $this->invitees = [];

        session()->flash('status', 'Invitations recorded.');
    }

    public function startRecording(): void
    {
        Gate::authorize('procurement.rfq-manage');

        $rfq = Rfq::query()->with('lines')->findOrFail($this->rfqId);

        $this->quotingSupplierId = null;
        $this->reference = '';
        $this->quotedOn = now()->toDateString();
        $this->validUntil = '';
        $this->leadTimeDays = '';
        $this->paymentTerms = '';

        // Seeded from the RFQ's own lines so the quote is transcribed against
        // what was actually asked for, line for line, rather than retyped.
        $this->prices = $rfq->lines
            ->mapWithKeys(fn ($line) => [$line->id => ['unit_price' => '', 'tax_amount' => '']])
            ->all();

        $this->recording = true;
        $this->resetValidation();
    }

    public function recordQuotation(): void
    {
        Gate::authorize('procurement.rfq-manage');

        $this->validate([
            'quotingSupplierId' => ['required', 'string'],
            'quotedOn' => ['required', 'date'],
            'validUntil' => ['nullable', 'date'],
            'leadTimeDays' => ['nullable', 'numeric', 'min:0', 'max:3650'],
            'paymentTerms' => ['nullable', 'string', 'max:180'],
            'reference' => ['nullable', 'string', 'max:60'],
            'prices.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'quotingSupplierId.required' => 'Whose quotation is this?',
        ]);

        $rfq = Rfq::query()->with('lines')->findOrFail($this->rfqId);

        /*
         * Found through the invitation list rather than by id alone. The
         * service refuses an uninvited supplier anyway; doing it here as well
         * means the screen never behaves as though the quote were accepted and
         * then takes it back.
         */
        $supplier = $rfq->invitations()
            ->with('supplier')
            ->where('supplier_id', $this->quotingSupplierId)
            ->first()?->supplier;

        if (! $supplier instanceof Contact) {
            $this->addError('quotingSupplierId', 'That supplier was not invited to this request for quotation.');

            return;
        }

        try {
            app(SourcingService::class)->recordQuotation($rfq, $supplier, [
                'reference' => $this->reference ?: null,
                'quoted_on' => $this->quotedOn,
                'valid_until' => $this->validUntil ?: null,
                'lead_time_days' => $this->leadTimeDays === '' ? null : (int) $this->leadTimeDays,
                'payment_terms' => $this->paymentTerms ?: null,
                'lines' => $rfq->lines->map(fn ($line) => [
                    'rfq_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => (float) ($this->prices[$line->id]['unit_price'] ?? 0),
                    'tax_amount' => (float) ($this->prices[$line->id]['tax_amount'] ?? 0),
                ])->all(),
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('quotation', $e->getMessage());

            return;
        }

        $this->recording = false;

        session()->flash('status', 'Quotation recorded.');
    }

    /**
     * Choose one quotation.
     *
     * What comes back is a draft, unnumbered purchase order. Issuing it is a
     * separate, deliberate act by the code that owns the PO sequence — this
     * screen only links to it.
     */
    public function award(string $quotationId): void
    {
        Gate::authorize('procurement.rfq-award');

        $quotation = SupplierQuotation::query()->with('rfq')->findOrFail($quotationId);

        try {
            $order = app(SourcingService::class)->award($quotation, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('award', $e->getMessage());

            return;
        }

        session()->flash('status', 'Awarded. A draft purchase order is waiting to be checked and issued.');
        session()->flash('draftOrderId', $order->id);
    }

    /** Stop taking answers without choosing one; the requisition can go out again. */
    public function closeRfq(): void
    {
        Gate::authorize('procurement.rfq-manage');

        $rfq = Rfq::query()->findOrFail($this->rfqId);

        try {
            app(SourcingService::class)->closeRfq($rfq, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('rfqState', $e->getMessage());

            return;
        }

        session()->flash('status', "{$rfq->number} closed without an award. The requisition is approved and waiting again.");
    }

    public function cancelRfq(): void
    {
        Gate::authorize('procurement.rfq-manage');

        $rfq = Rfq::query()->findOrFail($this->rfqId);

        try {
            app(SourcingService::class)->cancelRfq($rfq, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('rfqState', $e->getMessage());

            return;
        }

        session()->flash('status', "{$rfq->number} cancelled. The requisition is approved and waiting again.");
    }

    /** A bookmark on the comparison, not a decision — award() decides. */
    public function shortlist(string $quotationId): void
    {
        Gate::authorize('procurement.rfq-manage');

        $quotation = SupplierQuotation::query()->with('rfq')->findOrFail($quotationId);

        try {
            app(SourcingService::class)->shortlist($quotation, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('award', $e->getMessage());

            return;
        }
    }

    public function withdrawQuotation(string $quotationId): void
    {
        Gate::authorize('procurement.rfq-manage');

        $quotation = SupplierQuotation::query()->findOrFail($quotationId);

        try {
            app(SourcingService::class)->withdrawQuotation($quotation, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('award', $e->getMessage());

            return;
        }

        session()->flash('status', 'Quotation withdrawn — off the comparison, kept on the record.');
    }

    public function render(): View
    {
        $rfq = $this->rfqId === null
            ? null
            : Rfq::query()
                ->with(['lines', 'requisition', 'invitations.supplier', 'purchaseOrder'])
                ->find($this->rfqId);

        $invited = $rfq?->invitations->pluck('supplier')->filter()->values() ?? collect();

        return view('livewire.procurement.sourcing', [
            'rfqs' => Rfq::query()
                ->with(['requisition', 'invitations', 'quotations'])
                ->latest('created_at')
                ->get(),
            'rfq' => $rfq,
            // Only invited suppliers are ever offered; the backend refuses the
            // rest, and a form that appeared to allow it would be a lie.
            'invited' => $invited,
            'comparison' => $rfq === null ? collect() : app(SourcingService::class)->compare($rfq),
            // Approved and not yet ordered: exactly the requisitions that can
            // legitimately go out to the market.
            'approved' => PurchaseRequisition::query()
                ->where('status', 'approved')
                ->whereNull('purchase_order_id')
                ->with('lines')
                ->latest('approved_at')
                ->get(),
            'suppliers' => Contact::query()
                ->whereIn('type', ['supplier', 'vendor'])
                ->orderBy('name')
                ->get(['id', 'name', 'company_name']),
            'statuses' => Rfq::STATUSES,
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', [
            'title' => 'Sourcing',
            'active' => 'procurement',
        ]);
    }
}
