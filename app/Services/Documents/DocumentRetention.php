<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentRetentionPolicy;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * How long a document must be kept, and the one gate that overrides the
 * schedule entirely: a legal hold.
 *
 * §31–32 of the master spec. Nothing here deletes anything automatically —
 * see dispose(), which is a deliberate, permission-gated act, never a
 * background job quietly reclaiming space. An automated retention sweep is a
 * later, separate decision; the brief itself only asks for the policy and the
 * hold, not a cron job authorised to destroy business records unattended.
 */
class DocumentRetention
{
    /** The policy that applies to a document's kind, falling back to the company's catch-all. */
    public function policyFor(BusinessDocument $document): ?BusinessDocumentRetentionPolicy
    {
        $companyId = $document->company_id ?? app(CurrentCompany::class)->id();

        if ($companyId === null) {
            return null;
        }

        return BusinessDocumentRetentionPolicy::query()
            ->where('company_id', $companyId)
            ->where('kind', $document->kind)
            ->first()
            ?? BusinessDocumentRetentionPolicy::query()
                ->where('company_id', $companyId)
                ->whereNull('kind')
                ->first();
    }

    /**
     * The date a document may be disposed of, or null if no policy covers it.
     *
     * Counted from when it was issued — that is when a document became a
     * record of something that happened — falling back to when it was
     * created for a draft that never was.
     */
    public function retainUntil(BusinessDocument $document): ?Carbon
    {
        $policy = $this->policyFor($document);

        if ($policy === null) {
            return null;
        }

        $basis = $document->issued_at ?? $document->created_at;

        return $basis?->copy()->addYears($policy->retain_years);
    }

    /**
     * Whether a document may be disposed of right now: a policy exists, its
     * period has passed, and nothing is holding it.
     */
    public function isDisposable(BusinessDocument $document): bool
    {
        if ($document->legal_hold) {
            return false;
        }

        $retainUntil = $this->retainUntil($document);

        return $retainUntil !== null && $retainUntil->isPast();
    }

    public function placeLegalHold(BusinessDocument $document, string $reason, User $actor): BusinessDocument
    {
        $document->forceFill([
            'legal_hold' => true,
            'legal_hold_reason' => $reason,
            'legal_hold_set_by' => $actor->id,
            'legal_hold_set_at' => now(),
        ])->save();

        // Compliance's moment: a document just became undeletable regardless
        // of what the retention schedule says. Worth telling someone
        // directly rather than leaving it to be noticed on the record.
        $document->emitDomainEvent('document.legal_hold.placed', ['reason' => $reason]);

        return $document->fresh();
    }

    public function liftLegalHold(BusinessDocument $document): BusinessDocument
    {
        $document->forceFill([
            'legal_hold' => false,
            'legal_hold_reason' => null,
            'legal_hold_set_by' => null,
            'legal_hold_set_at' => null,
        ])->save();

        $document->emitDomainEvent('document.legal_hold.lifted');

        return $document->fresh();
    }

    /**
     * Permanent, controlled destruction — not the ordinary soft delete.
     *
     * Refuses outright if a hold is active or the retention period has not
     * yet passed; there is no override parameter, because a parameter that
     * bypasses a legal hold is a legal hold that does not actually hold.
     */
    public function dispose(BusinessDocument $document): void
    {
        if ($document->legal_hold) {
            throw new RuntimeException('This document is under legal hold and cannot be disposed of.');
        }

        if (! $this->isDisposable($document)) {
            throw new RuntimeException('This document has not yet passed its retention period.');
        }

        $document->forceDelete();
    }
}
