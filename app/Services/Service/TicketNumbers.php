<?php

namespace App\Services\Service;

use App\Services\DocumentNumbers;
use Carbon\CarbonImmutable;

/**
 * Human-readable references for tickets and visits.
 *
 * Extends the existing allocator rather than counting rows, because the same
 * two guarantees matter here as on an invoice: two people raising a ticket at
 * the same moment must not be handed the same reference, and a device that
 * raised tickets with no connection must not renumber them on sync.
 * `max(id) + 1` fails both. The lease ledger already solves it, and every
 * number ever allocated — used, unused or voided — keeps its auditable row.
 */
class TicketNumbers extends DocumentNumbers
{
    public function nextTicket(?CarbonImmutable $date = null): string
    {
        return $this->allocate('service_ticket', 'TKT', $date);
    }

    public function nextJob(?CarbonImmutable $date = null): string
    {
        return $this->allocate('service_job', 'SVJ', $date);
    }
}
