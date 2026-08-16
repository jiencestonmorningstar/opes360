<?php

namespace App\Services\Orders;

use App\Services\DocumentNumbers;
use Carbon\CarbonImmutable;

/**
 * Human-readable references for sales orders and delivery notes.
 *
 * Extends the lease-ledger allocator for the same reasons TicketNumbers does:
 * two clerks confirming orders at the same moment must not be handed the same
 * reference, and every number ever allocated keeps its auditable row.
 *
 * The delivery-note prefix is DLV, not DN, on purpose: DN already belongs to
 * DocumentType::DeliveryNote's series in the documents table, and two papers
 * both reading DN-2026-00001 from different ledgers would be indistinguishable
 * in a filing cabinet.
 */
class OrderNumbers extends DocumentNumbers
{
    public function nextOrder(?CarbonImmutable $date = null): string
    {
        return $this->allocate('sales_order', 'SO', $date);
    }

    public function nextDeliveryNote(?CarbonImmutable $date = null): string
    {
        return $this->allocate('sales_delivery_note', 'DLV', $date);
    }
}
