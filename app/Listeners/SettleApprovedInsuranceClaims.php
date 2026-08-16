<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\InsuranceClaim;
use App\Services\Insurance\Claims;

/**
 * Settles a claim the moment its settlement approval comes back yes.
 *
 * A listener rather than a call inside the workflow engine, on the same
 * reasoning as ActivateApprovedContracts: the engine serves every approvable
 * model and must not learn that insurance exists. It announces
 * `workflow.approved` through the subject, and whoever cares reacts here.
 *
 * The amount was written to the claim when the settlement was submitted, so
 * the approvers approved a number and this listener has nothing to invent.
 */
class SettleApprovedInsuranceClaims
{
    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof InsuranceClaim) {
            return;
        }

        if ($event->name !== 'workflow.approved') {
            return;
        }

        /*
         * Re-read before acting: the engine has just written the instance
         * that makes isAwaitingApproval() false, and the in-memory subject
         * still carries the relation as loaded — stale by exactly the row
         * settle() checks. Same trap ActivateApprovedContracts documents.
         */
        app(Claims::class)->settle($event->subject->fresh());
    }
}
