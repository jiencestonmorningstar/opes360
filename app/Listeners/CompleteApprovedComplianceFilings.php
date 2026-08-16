<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\ComplianceFiling;
use App\Services\Compliance\ComplianceRegister;

/**
 * Turns the approval engine's verdict on a statutory filing into the thing
 * that actually matters: the obligation moving on, or not.
 *
 * A listener rather than a call inside the engine, for the same reason
 * SyncApprovedRequisitions is one — the engine serves every approvable model
 * and must not learn that compliance exists. It announces `workflow.*` through
 * the subject; whoever cares about that subject reacts here.
 *
 * The rule this enforces is worth stating plainly: nothing rolls forward on
 * the strength of a request. A return is filed when somebody with the
 * authority says it was, and only then does the next deadline appear.
 */
class CompleteApprovedComplianceFilings
{
    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof ComplianceFiling) {
            return;
        }

        /*
         * Re-read before writing. The engine has just written the instance
         * that makes this an approval, and the in-memory subject it announced
         * through still carries its state as loaded — stale by exactly the row
         * that decides whether a deadline moves.
         */
        $filing = $event->subject->fresh();

        if ($filing === null || $filing->isDone()) {
            return;
        }

        $register = app(ComplianceRegister::class);

        match ($event->name) {
            'workflow.approved' => $register->complete($filing),
            // "No" and "not yet" stay different answers. A refused filing is
            // finished; one sent back for a missing attachment goes back to
            // being prepared and can be submitted again.
            'workflow.rejected', 'workflow.cancelled' => $register->reject($filing),
            'workflow.changes_requested' => $register->returnToPreparer($filing),
            default => null,
        };
    }
}
