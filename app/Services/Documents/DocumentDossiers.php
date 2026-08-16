<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentChecklist;
use App\Models\BusinessDocumentPackage;
use App\Support\DocumentKinds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * §36–40: the document view of an ERP record, and the two ways of grouping
 * documents that are not folders.
 *
 * A dossier is not a stored thing. It is a question asked of the relations
 * that already exist — "everything filed against this customer, grouped by
 * kind" — which is exactly what keeps Documents from becoming a second
 * customer module: there is no dossier row that could drift from the
 * documents it claims to describe.
 */
class DocumentDossiers
{
    public function __construct(protected DocumentLinker $linker) {}

    /**
     * Every document filed against an ERP record, grouped by kind.
     *
     * @return array{total: int, groups: array<string, array{label: string, documents: Collection}>}
     */
    public function forRecord(Model $related): array
    {
        $documents = $this->linker->documentsFor($related);

        $groups = $documents
            ->groupBy(fn (BusinessDocument $d) => $d->kind ?? 'document')
            ->map(fn (Collection $group, string $kind) => [
                'label' => DocumentKinds::label($kind),
                'documents' => $group->values(),
            ])
            ->all();

        return ['total' => $documents->count(), 'groups' => $groups];
    }

    // ── Packages ─────────────────────────────────────────────────────────

    public function addToPackage(BusinessDocumentPackage $package, BusinessDocument $document): void
    {
        if ($package->isClosed()) {
            throw new RuntimeException('This package is closed. Reopen it before adding to it.');
        }

        // syncWithoutDetaching rather than attach: adding the same document
        // twice is the caller asking for it to be in the package, and it is.
        $package->documents()->syncWithoutDetaching([
            $document->id => ['sort_order' => $package->documents()->count()],
        ]);
    }

    /** Removing from a package never touches the document itself. */
    public function removeFromPackage(BusinessDocumentPackage $package, BusinessDocument $document): void
    {
        $package->documents()->detach($document->id);
    }

    public function closePackage(BusinessDocumentPackage $package): BusinessDocumentPackage
    {
        $package->update(['status' => 'closed']);

        return $package->fresh();
    }

    public function reopenPackage(BusinessDocumentPackage $package): BusinessDocumentPackage
    {
        $package->update(['status' => 'open']);

        return $package->fresh();
    }

    // ── Checklists ───────────────────────────────────────────────────────

    /**
     * How a record stands against a checklist, computed from what is
     * actually linked to it right now.
     *
     * @return array{complete: bool, satisfied: array<int, string>, missing: array<int, string>}
     */
    public function checklistStatus(BusinessDocumentChecklist $checklist, Model $related): array
    {
        $presentKinds = $this->linker->documentsFor($related)
            ->pluck('kind')
            ->filter()
            ->unique()
            ->all();

        $required = $checklist->required_kinds ?? [];

        $satisfied = array_values(array_intersect($required, $presentKinds));
        $missing = array_values(array_diff($required, $presentKinds));

        return [
            'complete' => $missing === [],
            'satisfied' => $satisfied,
            'missing' => $missing,
        ];
    }

    /** @return Collection<int, BusinessDocumentChecklist> */
    public function checklistsFor(Model $related): Collection
    {
        return BusinessDocumentChecklist::query()
            ->forSubjectType($related->getMorphClass())
            ->get();
    }
}
