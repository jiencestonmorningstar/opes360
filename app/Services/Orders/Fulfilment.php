<?php

namespace App\Services\Orders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\Stock\StockReservations;
use App\Support\Aging;
use App\Support\CurrentCompany;
use App\Support\Modules;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Draft → confirmed → picked → delivered → invoiced, mirroring inbound.
 *
 * Everything here consumes machinery the platform already has, which is the
 * whole design:
 *
 *  confirm    holds stock through the EXISTING StockReservations service —
 *             one reservation row per order line, never a parallel hold
 *             table. What cannot be held is written on the line as a named
 *             backorder, visible, never a silent truncation.
 *  deliver    writes ordinary StockMovements (reason 'sale', referenced to
 *             the delivery note) and the delivery note itself in ONE
 *             transaction, so the paper and the shelf cannot disagree.
 *  invoice    creates an ordinary draft Document from the delivered-not-yet-
 *             invoiced lines, priced through the same Vat rules as every
 *             other invoice, and links it. ONE invoice generator: the lines
 *             carry no item_id, because the stock already moved at delivery
 *             and DocumentIssuer would otherwise move it a second time.
 *  cancel     releases the reservations. Nothing else exists to undo.
 */
class Fulfilment
{
    public function __construct(
        protected StockReservations $reservations,
        protected OrderNumbers $numbers,
    ) {}

    /**
     * Draft an order. Nothing is promised yet — no reservation, no number
     * consumed beyond the order's own reference.
     *
     * @param  array{contact_id: string, promised_date?: ?string, notes?: ?string,
     *               stock_location_id?: ?string, source_document_id?: ?string,
     *               lines: array<int, array{item_id: string, quantity: float|string, unit_price?: float|string|null}>}  $data
     */
    public function create(array $data, ?User $actor = null): SalesOrder
    {
        $company = $this->company();

        $lines = array_values($data['lines'] ?? []);

        if ($lines === []) {
            throw new RuntimeException('An order needs at least one thing on it.');
        }

        $contact = Contact::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->find($data['contact_id'] ?? null);

        if ($contact === null) {
            throw new RuntimeException('That customer does not belong to this business.');
        }

        /*
         * Which shelf will ship this order. Only meaningful when the
         * stock_locations module is on — the same convention StockLedger
         * follows ("one location or none") — and always the company's own.
         * deliver() already stamps this onto the note and its movements.
         */
        $location = null;

        if (! empty($data['stock_location_id']) && Modules::enabled($company, 'stock_locations')) {
            $location = StockLocation::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->find($data['stock_location_id']);

            if ($location === null) {
                throw new RuntimeException('That stock location does not belong to this business.');
            }
        }

        return DB::transaction(function () use ($company, $contact, $data, $lines, $actor, $location) {
            $order = SalesOrder::create([
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'number' => $this->numbers->nextOrder(),
                // Set explicitly: create() does not read column defaults back.
                'status' => SalesOrder::STATUS_DRAFT,
                'currency' => $company->currency ?: 'XAF',
                'promised_date' => $data['promised_date'] ?? null,
                'stock_location_id' => $location?->id,
                'source_document_id' => $data['source_document_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            foreach ($lines as $index => $line) {
                $item = Item::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->find($line['item_id'] ?? null);

                if ($item === null) {
                    throw new RuntimeException('A line names an item this business does not have.');
                }

                $quantity = round((float) ($line['quantity'] ?? 0), 3);

                if ($quantity <= 0) {
                    throw new RuntimeException("A line has to order more than nothing of {$item->name}.");
                }

                SalesOrderLine::create([
                    'company_id' => $company->id,
                    'sales_order_id' => $order->id,
                    'item_id' => $item->id,
                    'description' => $item->name,
                    'unit' => $item->unit ?? 'unit',
                    'quantity_ordered' => $quantity,
                    'unit_price' => round((float) ($line['unit_price'] ?? $item->price ?? 0), 2),
                    'sort_order' => $index,
                ]);
            }

            return $order->load('lines');
        });
    }

    /**
     * Confirm the order: promise what exists, name what does not.
     *
     * Reserves through the existing reservations mechanism, one reservation
     * per line so a partial delivery later releases exactly the right hold.
     * The remainder that could not be reserved becomes the line's backorder —
     * a figure a person reads, never a quiet reduction of the order.
     *
     * Credit control happens here, because this is the commitment moment:
     * when the customer's OVERDUE balance (the existing Aging read model,
     * total minus the not-yet-due bucket) exceeds their credit limit, the
     * confirm is refused — unless a written override reason is given, which
     * is then stored on the order with who wrote it. A zero or null limit
     * means the business has chosen not to check this customer.
     *
     * @return array<int, array{item: string, short: float}> the named shortages
     */
    public function confirm(SalesOrder $order, ?User $actor = null, ?string $creditOverride = null): array
    {
        if (! $order->isDraft()) {
            throw new RuntimeException("{$order->number} has already been confirmed.");
        }

        $creditOverride = trim((string) $creditOverride) ?: null;

        $order->loadMissing('contact');
        $limit = (float) ($order->contact?->credit_limit ?? 0);

        if ($limit > 0 && $creditOverride === null) {
            $overdue = $this->overdueBalance($order->contact);

            if ($overdue - $limit > 0.005) {
                throw new RuntimeException(sprintf(
                    '%s has %s overdue against a credit limit of %s. Confirming anyway needs a written reason.',
                    $order->contact->displayName(),
                    number_format($overdue, 0, '.', ' '),
                    number_format($limit, 0, '.', ' '),
                ));
            }
        }

        return DB::transaction(function () use ($order, $actor, $creditOverride) {
            /*
             * Re-read under a row lock: the draft check above ran on the
             * caller's in-memory copy, and two concurrent confirms would
             * both pass it and each reserve the full order — the shelf
             * promised twice. Lock-before-check, as in PaymentRecorder.
             */
            SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->refresh();

            if (! $order->isDraft()) {
                throw new RuntimeException("{$order->number} has already been confirmed.");
            }

            $shortages = [];

            foreach ($order->lines()->with('item')->get() as $line) {
                $item = $line->item;
                $ordered = (float) $line->quantity_ordered;

                // A service, or a product nobody counts, has nothing to hold:
                // it is always deliverable, so the whole quantity is "reserved"
                // on the line with no reservation row underneath.
                if (! $item->track_stock || $item->type !== 'product') {
                    $line->forceFill(['quantity_reserved' => $ordered, 'quantity_backordered' => 0])->save();

                    continue;
                }

                /*
                 * The item row is the mutex serialising reservation
                 * arithmetic. "Available" is SUM(movements) − SUM(live
                 * reservations) — aggregates with no lockable row of their
                 * own — so two concurrent confirms would read the same
                 * snapshot and together promise the same shelf twice. The
                 * lock is held to commit; a no-op on sqlite, where the
                 * single connection serialises anyway.
                 */
                Item::query()->withoutGlobalScopes()->whereKey($item->id)->lockForUpdate()->first();

                $available = max(0.0, $this->reservations->availableOf($item));
                $hold = round(min($ordered, $available), 3);

                if ($hold > 0) {
                    $this->reservations->reserve(
                        $item,
                        $hold,
                        for: $line,
                        actor: $actor,
                        note: "Order {$order->number}",
                    );
                }

                $short = round($ordered - $hold, 3);

                $line->forceFill([
                    'quantity_reserved' => $hold,
                    'quantity_backordered' => $short,
                ])->save();

                if ($short > 0) {
                    $shortages[] = ['item' => $item->name, 'short' => $short];
                }
            }

            $order->forceFill([
                'status' => SalesOrder::STATUS_CONFIRMED,
                'confirmed_by' => $actor?->id,
                'confirmed_at' => now(),
                // Written only when an over-limit confirm was pushed through:
                // the reason and the person, on the order, for the auditor.
                'credit_override_reason' => $creditOverride,
                'credit_override_by' => $creditOverride !== null ? $actor?->id : null,
            ])->save();

            $order->emitDomainEvent('order.confirmed', [
                'number' => $order->number,
                'backordered' => round(array_sum(array_column($shortages, 'short')), 3),
            ]);

            return $shortages;
        });
    }

    /**
     * Turn an accepted quotation into a draft order carrying its lines and
     * prices, linked back through source_document_id.
     *
     * Only item-bearing lines cross over: an order line promises a catalogue
     * item, and a free-text quotation line has nothing to reserve. The price
     * carried is the quotation's, not today's catalogue price — the customer
     * accepted a figure, and the order keeps it. One order per quotation,
     * the same doctrine as DocumentConverter's one-invoice-per-quotation.
     */
    public function fromQuotation(Document $quotation, ?User $actor = null): SalesOrder
    {
        if ($quotation->type !== DocumentType::Quotation) {
            throw new RuntimeException('Only a quotation can become an order.');
        }

        if (in_array($quotation->status, [DocumentStatus::Draft, DocumentStatus::Void], true)) {
            throw new RuntimeException('A draft or voided quotation has not been accepted by anybody.');
        }

        if ($quotation->contact_id === null) {
            throw new RuntimeException('This quotation names no customer to order for.');
        }

        if (SalesOrder::query()->where('source_document_id', $quotation->id)->exists()) {
            throw new RuntimeException("An order has already been raised from {$quotation->number}.");
        }

        $quotation->loadMissing('lines');

        $lines = $quotation->lines
            ->filter(fn ($line) => $line->item_id !== null)
            ->values()
            ->map(fn ($line) => [
                'item_id' => $line->item_id,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
            ])
            ->all();

        if ($lines === []) {
            throw new RuntimeException(
                "Nothing on {$quotation->number} names a catalogue item — there is nothing an order could reserve."
            );
        }

        return DB::transaction(function () use ($quotation, $actor, $lines) {
            $order = $this->create([
                'contact_id' => $quotation->contact_id,
                'source_document_id' => $quotation->id,
                'notes' => "From quotation {$quotation->number}",
                'lines' => $lines,
            ], $actor);

            // The quotation has done its job — the same closing of the loop
            // DocumentConverter performs when one becomes an invoice.
            $quotation->forceFill(['status' => DocumentStatus::Accepted])->save();

            return $order;
        });
    }

    /**
     * The customer's overdue exposure — the Aging read model's answer, total
     * minus the not-yet-due bucket. An invoice inside its terms is not a
     * reason to hold goods.
     */
    protected function overdueBalance(Contact $contact): float
    {
        $party = (new Aging)->forParty($contact);

        return round($party['total'] - ($party['buckets']['current'] ?? 0.0), 2);
    }

    /**
     * Deliver some or all of what is held: the movements and the note, in one
     * transaction.
     *
     * @param  array<string, float|string|null>  $picks  line id → quantity to
     *                                                   deliver now. A missing or null entry means "everything reserved
     *                                                   on that line"; 0 means "skip it". Partial delivery is normal —
     *                                                   the remainder stays reserved and the order stays open.
     */
    public function deliver(SalesOrder $order, array $picks = [], ?User $actor = null, ?string $note = null): DeliveryNote
    {
        if (! $order->isOpen()) {
            throw new RuntimeException("{$order->number} is {$order->status} — only a confirmed order can be delivered.");
        }

        $company = $this->company();

        return DB::transaction(function () use ($order, $picks, $actor, $note, $company) {
            /*
             * Status and quantities re-read under lock inside the
             * transaction: two concurrent delivers of the same order would
             * both read the caller's stale quantity_reserved, both pass the
             * over-delivery check, and the same goods would leave the shelf
             * twice (quantity_reserved going negative). The order row is the
             * mutex; the lines are locked too because quantity_reserved is
             * the figure the whole method trusts.
             */
            SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->refresh();

            if (! $order->isOpen()) {
                throw new RuntimeException("{$order->number} is {$order->status} — only a confirmed order can be delivered.");
            }

            $lines = $order->lines()->with('item')->lockForUpdate()->get();

            $deliveries = [];

            foreach ($lines as $line) {
                $reserved = (float) $line->quantity_reserved;
                $quantity = array_key_exists($line->id, $picks) && $picks[$line->id] !== null
                    ? round((float) $picks[$line->id], 3)
                    : $reserved;

                if ($quantity <= 0) {
                    continue;
                }

                /*
                 * Only what is held may leave. Delivering past the reservation
                 * would ship stock promised to somebody else — the exact
                 * failure the reservations service exists to prevent — and the
                 * fix is honest: receive stock, confirm the backorder, deliver.
                 */
                if ($quantity - $reserved > 0.0005) {
                    throw new RuntimeException(
                        "Only {$reserved} of {$line->description} is picked and held for {$order->number} — {$quantity} cannot go out."
                    );
                }

                $deliveries[] = [$line, $quantity];
            }

            if ($deliveries === []) {
                throw new RuntimeException("Nothing on {$order->number} is held and ready to deliver.");
            }

            $deliveryNote = DeliveryNote::create([
                'company_id' => $company->id,
                'sales_order_id' => $order->id,
                'number' => $this->numbers->nextDeliveryNote(),
                'status' => DeliveryNote::STATUS_ISSUED,
                'delivered_on' => now()->toDateString(),
                'stock_location_id' => $order->stock_location_id,
                'note' => $note,
                'created_by' => $actor?->id,
            ]);

            // Minted exactly the way DocumentIssuer mints one, so the printed
            // QR resolves through the same public verification page.
            $token = VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => DeliveryNote::class,
                'subject_id' => $deliveryNote->id,
            ]);

            $deliveryNote->forceFill(['verification_token_id' => $token->id])->save();

            foreach ($deliveries as $index => [$line, $quantity]) {
                DeliveryNoteLine::create([
                    'company_id' => $company->id,
                    'delivery_note_id' => $deliveryNote->id,
                    'sales_order_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'quantity' => $quantity,
                    'sort_order' => $index,
                ]);

                /*
                 * The goods leave through the ordinary ledger, reason 'sale' —
                 * the same reason an invoiced sale writes — so stock on hand,
                 * the valuation and the replenishment usage figures all see an
                 * order delivery without knowing orders exist. unit_cost stays
                 * null for the same reason StockLedger leaves it null: cost is
                 * a question about the stock it came out of, not the sale.
                 */
                if ($line->item !== null && $line->item->type === 'product' && $line->item->track_stock) {
                    StockMovement::create([
                        'company_id' => $company->id,
                        'item_id' => $line->item_id,
                        'stock_location_id' => $deliveryNote->stock_location_id,
                        'quantity' => round(-1 * $quantity, 3),
                        'unit_cost' => null,
                        'reason' => 'sale',
                        'reference_type' => DeliveryNote::class,
                        'reference_id' => $deliveryNote->id,
                        'user_id' => $actor?->id,
                        'occurred_at' => now(),
                    ]);

                    $this->consumeReservation($line, $quantity);
                }

                $line->forceFill([
                    'quantity_reserved' => round((float) $line->quantity_reserved - $quantity, 3),
                    'quantity_delivered' => round((float) $line->quantity_delivered + $quantity, 3),
                ])->save();
            }

            $order->load('lines');

            $order->forceFill([
                'status' => $order->lines->every(fn (SalesOrderLine $l) => $l->isFullyDelivered())
                    ? SalesOrder::STATUS_DELIVERED
                    : SalesOrder::STATUS_PICKING,
            ])->save();

            $order->emitDomainEvent('order.delivered', [
                'number' => $order->number,
                'delivery_note' => $deliveryNote->number,
            ]);

            return $deliveryNote->load('lines');
        });
    }

    /**
     * Bill what has been delivered and not yet invoiced, as an ordinary
     * Document through the ordinary path.
     *
     * The draft is deliberately not issued here: somebody looks at an invoice
     * before it goes out, and DocumentIssuer remains the single door from
     * draft to issued. The lines carry no item_id — the stock already left at
     * delivery, and an item-bearing line would make the issuer's StockLedger
     * hook take it off the shelf a second time.
     */
    public function invoice(SalesOrder $order, ?User $actor = null): Document
    {
        $company = $this->company();

        return DB::transaction(function () use ($order, $company, $actor) {
            $billable = $order->lines()->get()->filter(fn (SalesOrderLine $line) => $line->uninvoicedQuantity() > 0);

            if ($billable->isEmpty()) {
                throw new RuntimeException(
                    "Nothing on {$order->number} has been delivered that is not already on an invoice."
                );
            }

            $lines = $billable->values()->map(fn (SalesOrderLine $line) => [
                'description' => $line->description,
                'quantity' => $line->uninvoicedQuantity(),
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
            ])->all();

            $vat = Vat::forCompany($company, $lines);

            $invoice = Document::create([
                'company_id' => $company->id,
                'type' => DocumentType::Invoice,
                'contact_id' => $order->contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $order->currency,
                'subtotal' => $vat['subtotal'],
                'discount_total' => $vat['discount_total'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
                'notes' => "Order {$order->number}",
                'created_by' => $actor?->id,
            ]);

            foreach ($lines as $index => $line) {
                DocumentLine::create([
                    'document_id' => $invoice->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $vat['lines'][$index]['tax'] ?? 0.0,
                    'line_total' => $vat['lines'][$index]['net'] ?? 0.0,
                    'sort_order' => $index,
                ]);
            }

            foreach ($billable as $line) {
                $line->forceFill([
                    'quantity_invoiced' => round((float) $line->quantity_invoiced + $line->uninvoicedQuantity(), 3),
                ])->save();
            }

            // The pivot's ulid key is supplied here: attach() knows nothing
            // about HasUlids.
            $order->invoices()->attach($invoice->id, ['id' => strtolower((string) Str::ulid())]);

            $order->load('lines');

            if ($order->lines->every(fn (SalesOrderLine $l) => (float) $l->quantity_invoiced + 0.0005 >= (float) $l->quantity_ordered)) {
                $order->forceFill(['status' => SalesOrder::STATUS_INVOICED])->save();
            }

            $order->emitDomainEvent('order.invoiced', [
                'number' => $order->number,
                'total' => (float) $invoice->total,
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * Cancel the order and give the stock back.
     *
     * Refused once anything has physically gone: goods on a truck are not
     * unwound by a status change — they come back through a credit note and a
     * return, on paper, like everything else.
     */
    public function cancel(SalesOrder $order, ?User $actor = null): SalesOrder
    {
        return DB::transaction(function () use ($order) {
            // Re-read under lock: a cancel racing a deliver must see the
            // committed deliveries, or it releases holds for goods that are
            // already on a truck. The order row is the same mutex deliver()
            // takes, so the two serialise.
            SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->refresh();

            if ($order->isCancelled()) {
                return $order;
            }

            if ((float) $order->lines()->sum('quantity_delivered') > 0.0005) {
                throw new RuntimeException(
                    "{$order->number} has deliveries against it — credit the invoice and record the return instead of cancelling."
                );
            }

            foreach ($order->lines()->get() as $line) {
                $this->reservations->releaseFor($line);
                $line->forceFill(['quantity_reserved' => 0, 'quantity_backordered' => 0])->save();
            }

            $order->forceFill([
                'status' => SalesOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            return $order;
        });
    }

    /**
     * Try again to hold a confirmed order's backordered remainder — the
     * button somebody presses after a delivery arrives.
     *
     * @return array<int, array{item: string, short: float}> what is still short
     */
    public function reserveBackorders(SalesOrder $order, ?User $actor = null): array
    {
        if (! $order->isOpen()) {
            throw new RuntimeException("{$order->number} is {$order->status} — there is nothing to re-reserve.");
        }

        return DB::transaction(function () use ($order, $actor) {
            // Same lock-before-check as confirm(): the open check above ran
            // on the caller's copy, and re-reserving against a stale order
            // races a concurrent deliver or cancel.
            SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->refresh();

            if (! $order->isOpen()) {
                throw new RuntimeException("{$order->number} is {$order->status} — there is nothing to re-reserve.");
            }

            $shortages = [];

            foreach ($order->lines()->with('item')->lockForUpdate()->get() as $line) {
                $short = (float) $line->quantity_backordered;

                if ($short <= 0 || $line->item === null) {
                    continue;
                }

                // Item row locked as the mutex for the availability sum —
                // see the identical lock in confirm() for why.
                Item::query()->withoutGlobalScopes()->whereKey($line->item_id)->lockForUpdate()->first();

                $available = max(0.0, $this->reservations->availableOf($line->item));
                $hold = round(min($short, $available), 3);

                if ($hold > 0) {
                    $this->reservations->reserve(
                        $line->item,
                        $hold,
                        for: $line,
                        actor: $actor,
                        note: "Order {$order->number} (backorder)",
                    );

                    $line->forceFill([
                        'quantity_reserved' => round((float) $line->quantity_reserved + $hold, 3),
                        'quantity_backordered' => round($short - $hold, 3),
                    ])->save();
                }

                if ($short - $hold > 0.0005) {
                    $shortages[] = ['item' => $line->item->name, 'short' => round($short - $hold, 3)];
                }
            }

            return $shortages;
        });
    }

    /**
     * Take a delivered quantity out of this line's live reservations —
     * fulfilling emptied ones through the reservations service, shrinking a
     * partially consumed one in place so the remainder keeps holding.
     */
    protected function consumeReservation(SalesOrderLine $line, float $quantity): void
    {
        $remaining = $quantity;

        $held = StockReservation::query()
            ->withoutGlobalScopes()
            ->where('reference_type', SalesOrderLine::class)
            ->where('reference_id', $line->id)
            ->holding()
            ->orderBy('created_at')
            ->get();

        foreach ($held as $reservation) {
            if ($remaining <= 0.0005) {
                break;
            }

            $holds = (float) $reservation->quantity;

            if ($holds - $remaining <= 0.0005) {
                $this->reservations->fulfil($reservation);
                $remaining = round($remaining - $holds, 3);
            } else {
                $reservation->forceFill(['quantity' => round($holds - $remaining, 3)])->save();
                $remaining = 0.0;
            }
        }
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot work an order without a current company.');
    }
}
