<?php

namespace Tests\Feature\Logistics;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Services\DocumentIssuer;
use RuntimeException;

class FreightInvoiceTest extends LogisticsTestCase
{
    public function test_the_freight_invoice_is_an_ordinary_linked_document(): void
    {
        $shipment = $this->shipment(['freight_amount' => 250_000]);

        $invoice = $this->dispatcher()->draftInvoice($shipment, $this->owner);

        // A row in `documents`, billed to the sender, still a draft — the
        // module renders nothing and numbers nothing of its own.
        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($this->sender->id, $invoice->contact_id);
        $this->assertSame(1, $invoice->lines()->count());
        $this->assertStringContainsString($shipment->reference, $invoice->lines()->first()->description);

        // The shipment keeps a LINK, never a copy of the money.
        $this->assertSame($invoice->id, $shipment->fresh()->document_id);

        // And it issues through the one issuer, like every other invoice.
        $issued = app(DocumentIssuer::class)->issue($invoice, $this->owner);
        $this->assertSame(DocumentStatus::Issued, $issued->status);
        $this->assertNotNull($issued->number);
        $this->assertNotNull($issued->verification_token_id);
    }

    public function test_a_shipment_cannot_be_invoiced_twice(): void
    {
        $shipment = $this->shipment();
        $this->dispatcher()->draftInvoice($shipment, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already on an invoice');

        $this->dispatcher()->draftInvoice($shipment->fresh(), $this->owner);
    }

    public function test_no_freight_amount_means_nothing_to_invoice(): void
    {
        $shipment = $this->shipment(['freight_amount' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no freight amount');

        $this->dispatcher()->draftInvoice($shipment, $this->owner);
    }

    public function test_a_cancelled_shipment_cannot_be_invoiced(): void
    {
        $shipment = $this->shipment();
        $this->dispatcher()->cancel($shipment, $this->owner);

        $this->expectException(RuntimeException::class);

        $this->dispatcher()->draftInvoice($shipment->fresh(), $this->owner);
    }
}
