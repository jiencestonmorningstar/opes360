<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\PurchaseRequisition;
use App\Services\Procurement\Requisitions;

/**
 * Caches the approval engine's verdict onto the requisition it was about.
 *
 * A listener rather than a call inside the workflow engine, for the same
 * reason PostApprovedExpenseClaims is one: the engine serves every approvable
 * model and must not learn that requisitions exist. It emits `workflow.*`
 * through the subject, and whoever cares about that subject reacts here.
 *
 * "No" and "not yet" stay different answers. A rejected requisition is
 * finished; a returned one is an invitation to fix something and resubmit,
 * and collapsing the two would lose the difference between a purchase the
 * business refused and one that needed a second quote attaching.
 */
class SyncApprovedRequisitions
{
    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof PurchaseRequisition) {
            return;
        }

        $verdict = match ($event->name) {
            'workflow.approved' => 'approved',
            'workflow.rejected', 'workflow.cancelled' => 'rejected',
            'workflow.changes_requested' => 'returned',
            default => null,
        };

        if ($verdict === null) {
            return;
        }

        /*
         * Re-read before writing. The engine has just written the instance
         * that makes isApproved() true, and the in-memory subject it announced
         * through still carries the relation as it was loaded — stale by
         * exactly the row that decides whether money may be committed.
         */
        $requisition = $event->subject->fresh();

        if ($requisition === null) {
            return;
        }

        app(Requisitions::class)->syncVerdict($requisition, $verdict);
    }
}
