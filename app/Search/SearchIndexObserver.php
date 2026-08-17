<?php

namespace App\Search;

use App\Jobs\IndexSearchEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the search index in step with the record it mirrors.
 *
 * `saved` covers create and every update (including a void, which the source
 * closure answers with null and index() turns into a removal). Both delete
 * flavours matter: a trashed record must vanish from search immediately, not
 * when somebody empties the bin.
 *
 * §60: the actual write goes through IndexSearchEntry, a ShouldQueue job, so
 * a source model whose subtitle/body computation ever gets expensive does
 * not have to invent its own queueing path. On `sync` this is dispatch-and-
 * run-inline, in the same order as before — see that job's docblock.
 *
 * A model with no company yet (created before CurrentCompany is set, e.g.
 * mid-transaction in a factory) is skipped here rather than queued with a
 * blank company id — GlobalSearch::index() already refuses those, and
 * queuing one would just mean IndexSearchEntry re-derives the same refusal
 * a request later.
 */
class SearchIndexObserver
{
    public function saved(Model $model): void
    {
        if ($model->company_id === null) {
            return;
        }

        IndexSearchEntry::forSave($model);
    }

    public function deleted(Model $model): void
    {
        if ($model->company_id === null) {
            return;
        }

        IndexSearchEntry::forRemoval($model);
    }

    public function restored(Model $model): void
    {
        $this->saved($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->deleted($model);
    }
}
