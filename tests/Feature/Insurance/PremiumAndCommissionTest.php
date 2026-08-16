<?php

namespace Tests\Feature\Insurance;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use RuntimeException;

/**
 * The money moves through the platform, both ways.
 *
 * The premium invoice and the commission invoice are ordinary `documents`
 * rows — drafted here, issued, dunned and receipted where every other invoice
 * is. This vertical links to them; it never restates them.
 */
class PremiumAndCommissionTest extends InsuranceTestCase
{
    public function test_the_premium_invoice_is_an_ordinary_document_linked_back(): void
    {
        $policy = $this->activePolicy(['premium' => 850_000]);

        $invoice = $this->policies()->invoicePremium($policy, $this->owner);

        // An ordinary sales invoice to the holder, in draft for a human eye.
        $this->assertInstanceOf(Document::class, $invoice);
        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($this->client->id, $invoice->contact_id);
        $this->assertEqualsWithDelta(850_000.0, (float) $invoice->total, 0.01);

        // Linked back, never copied: the policy can find its invoices, and
        // the money itself is only stated once, in receivables.
        $this->assertTrue($policy->fresh()->premiumInvoices()->pluck('id')->contains($invoice->id));
    }

    public function test_a_policy_with_no_premium_cannot_be_invoiced(): void
    {
        $policy = $this->activePolicy(['premium' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no premium');

        $this->policies()->invoicePremium($policy, $this->owner);
    }

    public function test_commission_is_a_receivable_against_the_insurer(): void
    {
        $policy = $this->activePolicy(['premium' => 850_000, 'commission_percent' => 12.5]);

        $commission = $this->policies()->recordCommission($policy, null, $this->owner);

        // The agreed percentage of the premium, owed by the insurer contact —
        // a receivable row, not a second ledger.
        $this->assertSame($this->insurer->id, $commission->insurer_contact_id);
        $this->assertSame('106250.00', (string) $commission->amount);
        $this->assertFalse($commission->isInvoiced());

        $invoice = $this->policies()->invoiceCommission($commission, $this->owner);

        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame($this->insurer->id, $invoice->contact_id);
        $this->assertSame($invoice->id, $commission->fresh()->document_id);
    }

    public function test_a_commission_cannot_be_invoiced_twice(): void
    {
        $policy = $this->activePolicy();
        $commission = $this->policies()->recordCommission($policy, 100_000, $this->owner);
        $this->policies()->invoiceCommission($commission, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already on an invoice');

        $this->policies()->invoiceCommission($commission->fresh(), $this->owner);
    }
}
