<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\ExpenseClaim;
use App\Services\ExpenseClaimService;

/**
 * Charges the cost and records the staff debt the moment a claim is approved.
 *
 * A listener rather than a call inside the workflow engine, for the same
 * reason TranslateDocumentWorkflowEvents is one: the engine serves every
 * approvable model and must not learn that expense claims exist. It emits
 * `workflow.approved` through the subject, and whoever cares about that
 * subject reacts here.
 *
 * Also marks the claim rejected, because "the engine said no" and "the list
 * screen shows no" have to agree without every screen re-asking the engine.
 */
class PostApprovedExpenseClaims
{
    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof ExpenseClaim) {
            return;
        }

        $claim = $event->subject;

        if ($event->name === 'workflow.rejected' || $event->name === 'workflow.cancelled') {
            $claim->forceFill(['status' => 'rejected'])->save();

            return;
        }

        if ($event->name !== 'workflow.approved') {
            return;
        }

        /*
         * Re-read before posting. The engine has just written the instance
         * that makes isApproved() true, and the in-memory subject it announced
         * through still carries the relation as it was loaded — stale by
         * exactly the row that matters.
         */
        app(ExpenseClaimService::class)->postApproval($claim->fresh(), null);
    }
}
