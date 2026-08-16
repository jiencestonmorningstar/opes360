<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\Contract;
use App\Services\Contracts\ContractLifecycle;

/**
 * Puts a contract into force the moment its approval comes back yes.
 *
 * A listener rather than a call inside the workflow engine, on the same
 * reasoning as PostApprovedExpenseClaims: the engine serves every approvable
 * model and must not learn that contracts exist. It announces
 * `workflow.approved` through the subject, and whoever cares reacts here.
 *
 * Approval and activation are joined deliberately. An approved contract that
 * still had to be activated by hand would sit in draft — binding on paper,
 * absent from the register, and therefore absent from every expiry and notice
 * warning this module exists to raise. That gap is worse than the alternative,
 * which is that a business wanting a delay records a later start date.
 */
class ActivateApprovedContracts
{
    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof Contract) {
            return;
        }

        if ($event->name !== 'workflow.approved') {
            return;
        }

        /*
         * Re-read before acting. The engine has just written the instance that
         * makes isAwaitingApproval() false, and the in-memory subject it
         * announced through still carries the relation as it was loaded —
         * stale by exactly the row activate() checks.
         */
        app(ContractLifecycle::class)->activate($event->subject->fresh());
    }
}
