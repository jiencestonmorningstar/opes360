<?php

namespace App\Services\Procurement;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\RfqLine;
use App\Models\RfqSupplier;
use App\Models\SupplierQuotation;
use App\Models\SupplierQuotationLine;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asking suppliers what it costs, and turning the best answer into an order.
 *
 * The gate at the top of every method that spends money is
 * `PurchaseRequisition::isSourceable()`, which asks the workflow engine — not
 * a status column and not a flag written by this service. An approval this
 * class could grant itself would not be an approval.
 *
 * Purchase orders, goods receipts and three-way matching already exist. This
 * stops at handing over a *draft* purchase order: issuing it, numbering it and
 * matching it against a delivery belong to the code that already does them.
 */
class Sourcing
{
    /**
     * Take an approved requisition out to the market.
     *
     * @param  array{title?: ?string, closes_on?: ?string, issued_on?: ?string, terms?: ?string, notes?: ?string}  $data
     */
    public function openRfq(PurchaseRequisition $requisition, array $data, User $actor): Rfq
    {
        $this->assertSourceable($requisition);

        $company = $this->company();

        return DB::transaction(function () use ($company, $requisition, $data, $actor) {
            $rfq = Rfq::create([
                'company_id' => $company->id,
                'purchase_requisition_id' => $requisition->id,
                'number' => $this->nextRfqNumber($company),
                'title' => $data['title'] ?? $requisition->title,
                'status' => 'draft',
                'issued_on' => $data['issued_on'] ?? now()->toDateString(),
                'closes_on' => $data['closes_on'] ?? null,
                'terms' => $data['terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($requisition->lines as $index => $line) {
                RfqLine::create([
                    'company_id' => $company->id,
                    'rfq_id' => $rfq->id,
                    // Kept so the quotations that come back can be compared
                    // line for line against what was actually asked for.
                    'purchase_requisition_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'sort_order' => $index,
                ]);
            }

            $requisition->forceFill(['status' => 'sourcing'])->save();

            $rfq->load('lines');
            $rfq->emitDomainEvent('rfq.opened', ['number' => $rfq->number]);

            return $rfq;
        });
    }

    /**
     * Invite suppliers to quote.
     *
     * @param  array<int, string>  $supplierIds
     * @return Collection<int, RfqSupplier>
     */
    public function invite(Rfq $rfq, array $supplierIds, User $actor): Collection
    {
        if (! $rfq->isOpen()) {
            throw new RuntimeException('That request for quotation is closed.');
        }

        $company = $this->company();

        return DB::transaction(function () use ($company, $rfq, $supplierIds) {
            $invitations = collect();

            foreach (array_unique($supplierIds) as $id) {
                $supplier = $this->supplier($company, $id);

                // firstOrCreate, not create: re-sending an RFQ to a supplier
                // already on it is an ordinary thing to do, and a second row
                // would mean two answers to "have they replied yet".
                $invitations->push(RfqSupplier::firstOrCreate(
                    ['rfq_id' => $rfq->id, 'supplier_id' => $supplier->id],
                    ['company_id' => $company->id, 'invited_at' => now()],
                ));
            }

            if ($rfq->status === 'draft' && $invitations->isNotEmpty()) {
                $rfq->forceFill(['status' => 'sent'])->save();
            }

            return $invitations;
        });
    }

    /**
     * Write down what a supplier came back with.
     *
     * @param  array{reference?: ?string, quoted_on?: ?string, valid_until?: ?string,
     *               lead_time_days?: ?int, payment_terms?: ?string, notes?: ?string,
     *               lines: array<int, array<string, mixed>>}  $data
     */
    public function recordQuotation(Rfq $rfq, Contact $supplier, array $data, ?User $actor = null): SupplierQuotation
    {
        if (! $rfq->isOpen()) {
            throw new RuntimeException('That request for quotation is closed.');
        }

        /*
         * A quote from somebody who was never asked is the shape a kickback
         * takes: the trail has to show that every price considered was
         * solicited. Invite them first, on the record, then record the quote.
         */
        if (! $rfq->hasInvited($supplier)) {
            throw new RuntimeException('That supplier was not invited to this request for quotation.');
        }

        $lines = array_values($data['lines'] ?? []);

        if ($lines === []) {
            throw new RuntimeException('A quotation needs at least one priced line.');
        }

        $company = $this->company();

        return DB::transaction(function () use ($company, $rfq, $supplier, $data, $lines, $actor) {
            $quotation = SupplierQuotation::create([
                'company_id' => $company->id,
                'rfq_id' => $rfq->id,
                'supplier_id' => $supplier->id,
                'reference' => $data['reference'] ?? null,
                'status' => 'received',
                'quoted_on' => $data['quoted_on'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'currency' => $company->currency ?: 'XAF',
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor?->id,
            ]);

            foreach ($lines as $index => $line) {
                $row = new SupplierQuotationLine([
                    'company_id' => $company->id,
                    'supplier_quotation_id' => $quotation->id,
                    'rfq_line_id' => $line['rfq_line_id'] ?? null,
                    'item_id' => $line['item_id'] ?? null,
                    'description' => trim((string) ($line['description'] ?? '')),
                    'quantity' => (float) ($line['quantity'] ?? 1),
                    'unit' => $line['unit'] ?? 'unit',
                    'unit_price' => round((float) ($line['unit_price'] ?? 0), 2),
                    'tax_amount' => round((float) ($line['tax_amount'] ?? 0), 2),
                    'sort_order' => $index,
                ]);

                $row->recompute();
                $row->save();
            }

            $quotation->load('lines');
            $quotation->recompute();
            $quotation->save();

            RfqSupplier::query()
                ->where('rfq_id', $rfq->id)
                ->where('supplier_id', $supplier->id)
                ->update(['responded_at' => now()]);

            $rfq->forceFill(['responded_at' => now()])->save();

            $quotation->emitDomainEvent('quotation.received', ['total' => (float) $quotation->total]);

            return $quotation;
        });
    }

    /**
     * The answers, cheapest first.
     *
     * Price only, and deliberately so — lead time and payment terms are on
     * every row for the buyer to weigh, but ranking on a weighted score the
     * buyer cannot see would be a purchasing decision made by software.
     *
     * @return Collection<int, SupplierQuotation>
     */
    public function compare(Rfq $rfq): Collection
    {
        return $rfq->quotations()
            ->with(['supplier', 'lines'])
            ->whereNotIn('status', ['withdrawn', 'rejected'])
            ->orderBy('total')
            ->get();
    }

    /**
     * Choose a quotation; everything else follows from that one act.
     *
     * Produces a *draft* purchase order. Issuing it is a separate, deliberate
     * step by a human — awarding is a sourcing decision, issuing is a
     * commitment, and collapsing the two removes the last chance to check the
     * numbers before they are sent to a supplier.
     */
    public function award(SupplierQuotation $quotation, User $actor): Document
    {
        $rfq = $quotation->rfq;

        if ($rfq === null) {
            throw new RuntimeException('That quotation is not attached to a request for quotation.');
        }

        if (! $rfq->isOpen()) {
            throw new RuntimeException('That request for quotation has already been decided.');
        }

        $requisition = $rfq->requisition;

        if ($requisition !== null) {
            $this->assertSourceable($requisition);
        }

        $company = $this->company();
        $quotation->loadMissing('lines', 'supplier');

        return DB::transaction(function () use ($company, $rfq, $quotation, $requisition, $actor) {
            $order = $this->draftOrder(
                $company,
                $quotation->supplier,
                $quotation->lines->map(fn (SupplierQuotationLine $line) => [
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => (float) $line->unit_price,
                    'tax_amount' => (float) $line->tax_amount,
                ])->all(),
                $actor,
                $quotation->reference,
            );

            $quotation->forceFill(['status' => 'awarded'])->save();

            // Everyone else is told no here rather than left as 'received',
            // so "which quotes are still live" never needs the RFQ's status
            // to be read alongside each quote's to be answered.
            SupplierQuotation::query()
                ->where('rfq_id', $rfq->id)
                ->whereKeyNot($quotation->id)
                ->whereIn('status', ['received', 'shortlisted'])
                ->update(['status' => 'rejected']);

            $rfq->forceFill([
                'status' => 'awarded',
                'awarded_at' => now(),
                'purchase_order_id' => $order->id,
            ])->save();

            if ($requisition !== null) {
                $requisition->forceFill([
                    'status' => 'ordered',
                    'ordered_at' => now(),
                    'purchase_order_id' => $order->id,
                ])->save();
            }

            $quotation->emitDomainEvent('quotation.awarded', ['purchase_order_id' => $order->id]);

            return $order;
        });
    }

    /**
     * Straight from an approved requisition to an order, with no RFQ.
     *
     * A real and legitimate path — a single known supplier, a small amount, an
     * emergency. The approval is still mandatory; only the market test is
     * skipped.
     */
    public function orderDirect(PurchaseRequisition $requisition, Contact $supplier, User $actor): Document
    {
        $this->assertSourceable($requisition);

        $company = $this->company();
        $this->supplier($company, $supplier->id);
        $requisition->loadMissing('lines');

        return DB::transaction(function () use ($company, $requisition, $supplier, $actor) {
            $order = $this->draftOrder(
                $company,
                $supplier,
                $requisition->lines->map(fn ($line) => [
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => (float) $line->estimated_unit_price,
                    'tax_amount' => 0.0,
                ])->all(),
                $actor,
                $requisition->number,
            );

            $requisition->forceFill([
                'status' => 'ordered',
                'ordered_at' => now(),
                'purchase_order_id' => $order->id,
            ])->save();

            return $order;
        });
    }

    /**
     * Stop taking answers without choosing one.
     *
     * The honest end of a sourcing round that produced nothing worth buying —
     * every quote came in too high, or the need went away. The requisition
     * goes back to `approved`, because the approval still stands and the
     * market can be asked again.
     */
    public function closeRfq(Rfq $rfq, User $actor, ?string $reason = null): Rfq
    {
        if (! $rfq->isOpen()) {
            throw new RuntimeException("{$rfq->number} is already ".(Rfq::STATUSES[$rfq->status] ?? $rfq->status).'.');
        }

        return DB::transaction(function () use ($rfq, $reason) {
            $rfq->forceFill([
                'status' => 'closed',
                'notes' => filled($reason)
                    ? trim(($rfq->notes ? $rfq->notes."\n" : '').'Closed: '.$reason)
                    : $rfq->notes,
            ])->save();

            $this->releaseRequisition($rfq);

            $rfq->emitDomainEvent('rfq.closed', ['number' => $rfq->number]);

            return $rfq->refresh();
        });
    }

    /** Strike the round entirely — same guards as closing, its own word for the record. */
    public function cancelRfq(Rfq $rfq, User $actor, ?string $reason = null): Rfq
    {
        if (! $rfq->isOpen()) {
            throw new RuntimeException("{$rfq->number} is already ".(Rfq::STATUSES[$rfq->status] ?? $rfq->status).'.');
        }

        return DB::transaction(function () use ($rfq, $reason) {
            $rfq->forceFill([
                'status' => 'cancelled',
                'notes' => filled($reason)
                    ? trim(($rfq->notes ? $rfq->notes."\n" : '').'Cancelled: '.$reason)
                    : $rfq->notes,
            ])->save();

            $this->releaseRequisition($rfq);

            $rfq->emitDomainEvent('rfq.cancelled', ['number' => $rfq->number]);

            return $rfq->refresh();
        });
    }

    /**
     * Mark a quotation as one of the front-runners — or take the mark off.
     *
     * A bookmark, not a decision: it commits nothing and nobody is told.
     * The decision is award(), and only award() closes the round.
     */
    public function shortlist(SupplierQuotation $quotation, User $actor): SupplierQuotation
    {
        $rfq = $quotation->rfq;

        if ($rfq === null || ! $rfq->isOpen()) {
            throw new RuntimeException('That request for quotation has already been decided.');
        }

        if (! in_array($quotation->status, ['received', 'shortlisted'], true)) {
            throw new RuntimeException(
                'That quotation is '.(SupplierQuotation::STATUSES[$quotation->status] ?? $quotation->status).' and cannot be shortlisted.'
            );
        }

        $quotation->forceFill([
            'status' => $quotation->status === 'shortlisted' ? 'received' : 'shortlisted',
        ])->save();

        return $quotation->refresh();
    }

    /** The supplier took their price back. Off the comparison, kept on the record. */
    public function withdrawQuotation(SupplierQuotation $quotation, User $actor): SupplierQuotation
    {
        if ($quotation->status === 'awarded') {
            throw new RuntimeException('That quotation was awarded — an order rests on it. It can no longer be withdrawn.');
        }

        if (! in_array($quotation->status, ['received', 'shortlisted'], true)) {
            throw new RuntimeException(
                'That quotation is already '.(SupplierQuotation::STATUSES[$quotation->status] ?? $quotation->status).'.'
            );
        }

        $quotation->forceFill(['status' => 'withdrawn'])->save();

        return $quotation->refresh();
    }

    // ------------------------------------------------------------- internals

    /**
     * A round that ended without an order hands the requisition back: the
     * approval still stands, and `approved` is what lets it be sourced again.
     */
    protected function releaseRequisition(Rfq $rfq): void
    {
        $requisition = $rfq->requisition;

        if ($requisition !== null
            && $requisition->status === 'sourcing'
            && $requisition->purchase_order_id === null) {
            $requisition->forceFill(['status' => 'approved'])->save();
        }
    }

    /**
     * The one gate that matters, asked of the engine rather than of a column.
     *
     * `status` is a cache written by a listener; between the engine finishing
     * and the listener running it is stale by exactly the row that decides
     * whether money may be committed.
     */
    protected function assertSourceable(PurchaseRequisition $requisition): void
    {
        if (! $requisition->isApproved()) {
            throw new RuntimeException('That requisition has not been approved yet.');
        }

        if ($requisition->purchase_order_id !== null) {
            throw new RuntimeException('That requisition has already been ordered.');
        }
    }

    /**
     * A draft purchase order, unnumbered.
     *
     * The number is left to the issuing code that owns the sequence. Handing
     * out a number here would burn one every time somebody awarded and then
     * changed their mind, and a purchase order sequence with holes in it is
     * the first thing an auditor asks about.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function draftOrder(
        Company $company,
        ?Contact $supplier,
        array $lines,
        User $actor,
        ?string $reference = null,
    ): Document {
        if ($supplier === null) {
            throw new RuntimeException('A purchase order needs a supplier.');
        }

        $net = round(collect($lines)->sum(fn (array $l) => round($l['quantity'] * $l['unit_price'], 2)), 2);
        $tax = round(collect($lines)->sum(fn (array $l) => (float) $l['tax_amount']), 2);

        $order = Document::create([
            'company_id' => $company->id,
            'type' => DocumentType::PurchaseOrder,
            'contact_id' => $supplier->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => $company->currency ?: 'XAF',
            'exchange_rate' => 1,
            'subtotal' => $net,
            'discount_total' => 0,
            'tax_total' => $tax,
            'total' => round($net + $tax, 2),
            'amount_paid' => 0,
            'balance' => round($net + $tax, 2),
            'reference' => $reference,
            'created_by' => $actor->id,
        ]);

        foreach ($lines as $index => $line) {
            DocumentLine::create([
                'company_id' => $company->id,
                'document_id' => $order->id,
                'item_id' => $line['item_id'] ?? null,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'] ?? 'unit',
                'unit_price' => $line['unit_price'],
                'discount_value' => 0,
                'tax_amount' => $line['tax_amount'] ?? 0,
                'line_total' => round($line['quantity'] * $line['unit_price'], 2),
                'sort_order' => $index,
            ]);
        }

        return $order->load('lines');
    }

    /** A supplier has to be this business's own contact. */
    protected function supplier(Company $company, string $id): Contact
    {
        $supplier = Contact::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->find($id);

        if ($supplier === null) {
            throw new RuntimeException('That supplier does not belong to this business.');
        }

        return $supplier;
    }

    /** Highest issued, not a row count: a soft-deleted RFQ still holds its number. */
    protected function nextRfqNumber(Company $company): string
    {
        $prefix = 'RFQ-'.now()->format('Y').'-';

        $last = Rfq::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $company->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function company(): Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot source a purchase without a current company.');
        }

        return $company;
    }
}
