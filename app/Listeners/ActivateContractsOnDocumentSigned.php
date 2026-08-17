<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\BusinessDocument;
use App\Models\Contract;
use App\Services\Contracts\ContractLifecycle;
use App\Services\Documents\DocumentLinker;

/**
 * CLM's "ERP Binding" (master spec §8.1): the moment a contract's paper is
 * fully executed, the contract itself goes live — without anybody having to
 * remember to flip it by hand in a second screen.
 *
 * `ActivateApprovedContracts` already does the equivalent for the internal
 * approval path (`workflow.approved` → activate). This is the other route a
 * contract goes live by: the paper went out for e-signature and every party
 * has now signed it (`document.signed`, from `DocumentSignatureRequests`),
 * with no internal approval workflow necessarily involved at all — the
 * counterparty's signature *is* the approval.
 *
 * Only ever acts on a contract still in draft. A contract that was already
 * activated through the approval path, or has since been renewed or
 * terminated, is left exactly alone — `ContractLifecycle::activate()` is
 * idempotent for "already active", but this listener does not even attempt
 * it once the contract has moved past draft, so a signed copy of a since-
 * terminated contract can never resurrect it.
 */
class ActivateContractsOnDocumentSigned
{
    public function __construct(
        protected DocumentLinker $linker,
        protected ContractLifecycle $lifecycle,
    ) {}

    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof BusinessDocument) {
            return;
        }

        if ($event->name !== 'document.signed') {
            return;
        }

        $contract = $this->linker->relationsOf($event->subject)
            ->pluck('related')
            ->first(fn ($related) => $related instanceof Contract);

        if ($contract === null) {
            return;
        }

        $contract = $contract->fresh();

        // Still draft, but somebody is deciding internally — leave that
        // process to reach its own verdict rather than short-circuiting it
        // because the counterparty happened to sign first.
        if ($contract->status !== 'draft' || $contract->isAwaitingApproval()) {
            return;
        }

        $this->lifecycle->activate($contract);
    }
}
