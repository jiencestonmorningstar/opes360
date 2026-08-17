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

    /**
     * How often an autosave stream may mint a version: at most one per author
     * per five minutes. The rule, and why:
     *
     * The rich editor autosaves every few seconds, and a version per keystroke
     * burst would turn the history from "what did this document say when Jean
     * finished with it" into an unreadable film strip of half-typed sentences.
     * Versions of a version row are also immutable here (never edited, never
     * deleted), so collapsing after the fact is not an option — the throttle
     * has to happen at mint time. Five minutes is roughly a paragraph of
     * work; the editor additionally calls checkpoint() when a writer leaves,
     * so the final state of every editing session is always captured
     * regardless of the window. A different author always gets a fresh
     * version immediately: "who changed it" must never be blurred to save
     * rows.
     */
    public const AUTOSAVE_WINDOW_SECONDS = 300;

    /**
     * While true, the model's `updated` hook stays quiet and the editor's own
     * snapshotThrottled()/checkpoint() calls own the cadence instead.
     * Static because the hook resolves a fresh instance from the container.
     */
    protected static bool $throttling = false;

    /**
     * Run an autosave under throttled versioning: the save itself does not
     * snapshot (the updated-hook defers to us), then the throttle decides.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    public function withAutosaveCadence(BusinessDocument $document, callable $save)
    {
        static::$throttling = true;

        try {
            $result = $save();
        } finally {
            static::$throttling = false;
        }

        $this->snapshotThrottled($document);

        return $result;
    }

    /** Snapshot a content change, unless the same author versioned recently. */
    public function snapshotThrottled(BusinessDocument $document): void
    {
        $latest = $document->versions()->orderByDesc('version_number')->first();

        if ($latest !== null && ! $this->contentDiffers($document, $latest)) {
            return;
        }

        $sameAuthor = $latest !== null
            && $latest->created_by !== null
            && (int) $latest->created_by === (int) auth()->id();

        if ($sameAuthor && $latest->created_at->gt(now()->subSeconds(self::AUTOSAVE_WINDOW_SECONDS))) {
            return; // Within the window; checkpoint() catches the final state.
        }

        $this->snapshot($document);
    }

    /**
     * The end of an editing session: snapshot whatever the autosave window
     * withheld, so closing the editor never loses the last few minutes from
     * history. No-op when the latest version already matches.
     */
    public function checkpoint(BusinessDocument $document): void
    {
        $latest = $document->versions()->orderByDesc('version_number')->first();

        if ($latest === null || $this->contentDiffers($document, $latest)) {
            $this->snapshot($document);
        }
    }

    protected function contentDiffers(BusinessDocument $document, BusinessDocumentVersion $version): bool
    {
        foreach (self::CONTENT_COLUMNS as $column) {
            if ($document->{$column} != $version->{$column}) {
                return true;
            }
        }

        return false;
    }

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
        if (static::$throttling) {
            return; // An autosave is in flight; withAutosaveCadence() decides.
        }

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
