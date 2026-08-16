<?php

namespace Tests\Feature\Orders;

use App\Enums\DocumentType;
use App\Models\DeliveryNote;
use App\Models\Document;
use App\Models\StockMovement;
use App\Services\DocumentIssuer;
use App\Services\Orders\Fulfilment;
use App\Services\Orders\Returns;
use RuntimeException;

/**
 * Goods coming back, tested against the claims that matter: the stock
 * returns through the EXISTING ledger (reason 'return', referenced to the
 * delivery note), the money returns through the EXISTING credit-note path,
 * more cannot come back than was delivered, and goods returned before they
 * were invoiced are simply never billed.
 */
class ReturnsTest extends OrdersTestCase
{
    /** Deliver, invoice, issue — the state most returns arrive into. */
    protected function deliveredAndInvoicedOrder(float $quantity = 10): array
    {
        $this->stockUp($this->cement, $quantity, 5000);

        $order = $this->draftOrder([
            ['item_id' => $this->cement->id, 'quantity' => $quantity, 'unit_price' => 6500],
        ]);

        app(Fulfilment::class)->confirm($order, $this->owner);
        $note = app(Fulfilment::class)->deliver($order, actor: $this->owner);
        $invoice = app(Fulfilment::class)->invoice($order, $this->owner);
        app(DocumentIssuer::class)->issue($invoice, $this->owner);

        return [$order, $note->fresh()->load('lines'), $invoice->fresh()];
    }

    public function test_a_return_restocks_through_the_ledger_and_credits_through_the_credit_note_path(): void
    {
        [$order, $note, $invoice] = $this->deliveredAndInvoicedOrder();

        $onHandBefore = $this->cement->stockOnHand();

        $result = app(Returns::class)->record(
            $note,
            [$note->lines->first()->id => 4],
            $this->owner,
            'Damaged in transit',
        );

        // Back on the shelf as an ordinary movement, reason 'return',
        // referenced to the note it came back against.
        $movement = StockMovement::query()
            ->where('reason', 'return')
            ->where('reference_type', DeliveryNote::class)
            ->where('reference_id', $note->id)
            ->firstOrFail();

        $this->assertSame('4.000', (string) $movement->quantity);
        $this->assertSame($onHandBefore + 4.0, $this->cement->stockOnHand());

        // The money comes back through the ONE credit-note generator: an
        // ordinary issued CreditNote against the order's invoice, at the
        // invoiced value of what returned (4 × 6 500).
        $credit = $result['credit_note'];
        $this->assertNotNull($credit);
        $this->assertSame(DocumentType::CreditNote, $credit->type);
        $this->assertSame($invoice->id, $credit->parent_document_id);
        $this->assertSame(26000.0, (float) $credit->total);
        $this->assertStringStartsWith('CN-', (string) $credit->number);

        // The note line carries the running returned figure.
        $this->assertSame('4.000', (string) $note->lines->first()->fresh()->quantity_returned);
    }

    public function test_returning_more_than_was_delivered_is_refused(): void
    {
        [, $note] = $this->deliveredAndInvoicedOrder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Only 10/');

        app(Returns::class)->record($note, [$note->lines->first()->id => 12], $this->owner, 'Too much');
    }

    public function test_a_second_return_cannot_exceed_what_is_left(): void
    {
        [, $note] = $this->deliveredAndInvoicedOrder();
        $line = $note->lines->first();

        app(Returns::class)->record($note, [$line->id => 6], $this->owner, 'First batch back');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Only 4/');

        app(Returns::class)->record($note->fresh()->load('lines'), [$line->id => 5], $this->owner, 'Second batch');
    }

    public function test_goods_returned_before_invoicing_are_never_billed(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        $order = $this->draftOrder([
            ['item_id' => $this->cement->id, 'quantity' => 10, 'unit_price' => 6500],
        ]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        $note = app(Fulfilment::class)->deliver($order, actor: $this->owner)->fresh()->load('lines');

        // 3 come back before any invoice exists: no document to correct, so
        // no credit note — the 3 simply drop out of what is billable.
        $result = app(Returns::class)->record($note, [$note->lines->first()->id => 3], $this->owner, 'Wrong grade');

        $this->assertNull($result['credit_note']);
        $this->assertSame(0, Document::query()->ofType(DocumentType::CreditNote)->count());

        $invoice = app(Fulfilment::class)->invoice($order->fresh(), $this->owner);

        // 7 delivered-and-kept × 6 500.
        $this->assertSame(45500.0, (float) $invoice->total);
    }

    public function test_a_return_must_name_a_reason_and_refuses_a_void_note(): void
    {
        [, $note] = $this->deliveredAndInvoicedOrder();

        try {
            app(Returns::class)->record($note, [$note->lines->first()->id => 1], $this->owner, '  ');
            $this->fail('A reasonless return was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must say why', $e->getMessage());
        }

        $note->forceFill(['status' => DeliveryNote::STATUS_VOID])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/void/');

        app(Returns::class)->record($note->fresh()->load('lines'), [$note->lines->first()->id => 1], $this->owner, 'Damaged');
    }
}
