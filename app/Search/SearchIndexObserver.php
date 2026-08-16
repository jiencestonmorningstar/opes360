<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the search index in step with the record it mirrors.
 *
 * `saved` covers create and every update (including a void, which the source
 * closure answers with null and index() turns into a removal). Both delete
 * flavours matter: a trashed record must vanish from search immediately, not
 * when somebody empties the bin.
 */
class SearchIndexObserver
{
    public function saved(Model $model): void
    {
        GlobalSearch::index($model);
    }

    public function deleted(Model $model): void
    {
        GlobalSearch::forget($model);
    }

    public function restored(Model $model): void
    {
        GlobalSearch::index($model);
    }

    public function forceDeleted(Model $model): void
    {
        GlobalSearch::forget($model);
    }
}
