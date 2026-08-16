<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\JobOffer;

/**
 * Writes the workflow engine's verdict onto the offer the moment it lands.
 *
 * A listener rather than a call inside the engine, on the same reasoning as
 * ActivateApprovedContracts: the engine serves every approvable model and
 * must not learn that job offers exist. It announces `workflow.approved` (or
 * `.rejected`) through the subject, and whoever cares reacts here.
 *
 * Approval does NOT hire anybody. The verdict is the business saying "you may
 * offer this"; hiring waits for the candidate's own yes, which arrives
 * through JobOffers::accept() — and accept() re-checks the engine rather
 * than trusting the column this listener writes.
 */
class MarkApprovedJobOffers
{
    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof JobOffer) {
            return;
        }

        if (! in_array($event->name, ['workflow.approved', 'workflow.rejected'], true)) {
            return;
        }

        /*
         * Re-read before acting: the in-memory subject the engine announced
         * through predates the instance row that just finished. Idempotent on
         * purpose — discovery registers this listener once in production, but
         * a test suite that also registers it explicitly must not double-write.
         */
        $offer = $event->subject->fresh();

        $verdict = $event->name === 'workflow.approved' ? 'approved' : 'rejected';

        if ($offer->status === 'pending') {
            $offer->update(['status' => $verdict]);
        }
    }
}
