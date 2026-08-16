<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\User;
use RuntimeException;

/**
 * Decides whether a save changed content, and if so, snapshots it.
 *
 * The distinction this exists to make: filing (kind, tags, folder, owner,
 * expiry, department, lock state) is not content, and moving a document into
 * a folder must not flood its history with a version that reads identically
 * to the one before it.
 */
class DocumentVersioner
{
    /** The columns a version is a version of. Everything else is filing. */
    protected const CONTENT_COLUMNS = ['title', 'recipient', 'fields', 'body'];

    /** Always versions. Called from the `created` hook, where there is nothing to compare against yet. */
    public function snapshotInitial(BusinessDocument $document): void
    {
        $this->snapshot($document);
    }

    /**
     * Versions only if a content column actually changed.
     *
     * Deliberately does not lean on `wasRecentlyCreated` to skip this on a
     * fresh insert — that flag is set true by Eloquent on INSERT and never
     * reset to false by the framework on a later save(), so on the same
     * in-memory object it would still read true during the very next update
     * and every update after it. `created` and `updated` are wired to two
     * separate methods for exactly this reason.
     */
    public function snapshotIfChanged(BusinessDocument $document): void
    {
        $dirty = array_intersect_key($document->getChanges(), array_flip(self::CONTENT_COLUMNS));

        if ($dirty === []) {
            return;
        }

        $this->snapshot($document);
    }

    protected function snapshot(BusinessDocument $document): BusinessDocumentVersion
    {
        $next = (int) $document->versions()->max('version_number') + 1;

        return BusinessDocumentVersion::create([
            'business_document_id' => $document->id,
            'version_number' => $next,
            'title' => $document->title,
            'recipient' => $document->recipient,
            'fields' => $document->fields,
            'body' => $document->body,
            'created_by' => auth()->id() ?? $document->created_by,
        ]);
    }

    /**
     * Restore replaces current content with a past version's, and — because a
     * version is never rewritten — records that act as a new version of its
     * own. The two records of "what does this say right now" would otherwise
     * disagree.
     */
    public function restore(BusinessDocument $document, BusinessDocumentVersion $version, User $actor): BusinessDocument
    {
        if (! $document->isDraft()) {
            throw new RuntimeException('Only a draft can be restored to an earlier version.');
        }

        $document->forceFill([
            'title' => $version->title,
            'recipient' => $version->recipient,
            'fields' => $version->fields,
            'body' => $version->body,
        ])->save();

        return $document->fresh();
    }
}
