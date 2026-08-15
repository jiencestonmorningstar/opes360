<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentRelation;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Attaching documents to the ERP records they are about.
 *
 * This is the service that stops Documents becoming a second ERP. Nothing here
 * copies a customer, an invoice or an employee — it records that a document
 * concerns one, and leaves the authoritative record where it lives.
 */
class DocumentLinker
{
    /**
     * Link a document to an ERP record.
     *
     * Idempotent. Attaching the same record twice in the same role is one link,
     * enforced by the unique key rather than by a read-then-write, so two
     * people doing it at once still end up with one.
     */
    public function attach(
        BusinessDocument $document,
        Model $related,
        string $role = 'about',
        ?User $actor = null,
    ): BusinessDocumentRelation {
        $this->assertSameCompany($related);

        $role = array_key_exists($role, BusinessDocumentRelation::ROLES) ? $role : 'about';

        try {
            return BusinessDocumentRelation::create([
                'business_document_id' => $document->id,
                'related_type' => $related->getMorphClass(),
                'related_id' => (string) $related->getKey(),
                'role' => $role,
                'created_by' => $actor?->id,
            ]);
        } catch (QueryException) {
            // Already linked. Return the existing row rather than failing: the
            // caller asked for the link to exist, and it does.
            return BusinessDocumentRelation::query()
                ->where('business_document_id', $document->id)
                ->where('related_type', $related->getMorphClass())
                ->where('related_id', (string) $related->getKey())
                ->where('role', $role)
                ->firstOrFail();
        }
    }

    /** Removing a link never touches the ERP record. */
    public function detach(BusinessDocument $document, Model $related, ?string $role = null): int
    {
        return BusinessDocumentRelation::query()
            ->where('business_document_id', $document->id)
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', (string) $related->getKey())
            ->when($role !== null, fn ($q) => $q->where('role', $role))
            ->delete();
    }

    /**
     * Every document filed against an ERP record.
     *
     * @return Collection<int, BusinessDocument>
     */
    public function documentsFor(Model $related, ?string $role = null): Collection
    {
        $ids = BusinessDocumentRelation::query()
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', (string) $related->getKey())
            ->when($role !== null, fn ($q) => $q->where('role', $role))
            ->pluck('business_document_id');

        return BusinessDocument::query()
            ->whereIn('id', $ids)
            ->latest('created_at')
            ->get();
    }

    public function countFor(Model $related): int
    {
        return BusinessDocumentRelation::query()
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', (string) $related->getKey())
            ->distinct('business_document_id')
            ->count('business_document_id');
    }

    /** @return Collection<int, BusinessDocumentRelation> */
    public function relationsOf(BusinessDocument $document): Collection
    {
        return BusinessDocumentRelation::query()
            ->where('business_document_id', $document->id)
            ->with('related')
            ->get();
    }

    /**
     * A document must not be filed against another company's record.
     *
     * The global scope already hides such a record from a query, but this is
     * reached with a model somebody already holds — so it is checked rather
     * than assumed. A cross-tenant link would be the worst leak this module
     * could produce: one business's contract listed on another's customer.
     */
    protected function assertSameCompany(Model $related): void
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot link a document without a current company.');
        }

        $relatedCompany = $related->getAttribute('company_id');

        if ($relatedCompany !== null && $relatedCompany !== $company->id) {
            throw new RuntimeException('That record belongs to another business.');
        }
    }
}
