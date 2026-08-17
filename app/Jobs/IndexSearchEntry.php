<?php

namespace App\Jobs;

use App\Search\GlobalSearch;
use App\Support\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §60 — reindex-on-save, off the request that caused it.
 *
 * SearchIndexObserver used to call GlobalSearch::index()/forget() straight
 * from `saved`/`deleted`, inline in whatever request touched the record.
 * The write itself (one `updateOrCreate` against SearchEntry, a table with
 * no foreign work in it) is cheap enough that inline was never wrong — this
 * exists so the *next* source model that isn't cheap (a big text body, a
 * computed subtitle that hits another table) doesn't have to invent its own
 * queueing path. One place, argued once.
 *
 * On `sync` — no worker provisioned — Laravel runs a ShouldQueue dispatch
 * inline, in the same request, in call order. That is already the exact
 * behaviour the observer had before this job existed, so nothing about
 * `sync` needed defending the way DeliverWebhook's retry schedule did:
 * there is no retry or backoff here to skip, just a write that either
 * happens now (sync) or shortly after (a real queue).
 *
 * The model is not carried across the boundary — id + class only, the same
 * reason DeliverWebhook carries an id rather than a model: a save queued
 * behind other work could run against a row that changed again, or was
 * deleted, by the time a worker picks it up. Re-reading it is what makes a
 * queued reindex correct instead of merely fast.
 */
class IndexSearchEntry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** @param  class-string<Model>  $modelClass */
    public function __construct(
        public string $modelClass,
        public string $modelId,
        public string $companyId,
        public bool $forget = false,
    ) {}

    public static function forSave(Model $model): void
    {
        self::dispatch($model::class, (string) $model->getKey(), (string) $model->company_id, false);
    }

    public static function forRemoval(Model $model): void
    {
        self::dispatch($model::class, (string) $model->getKey(), (string) $model->company_id, true);
    }

    public function handle(): void
    {
        $model = $this->modelClass::query()->withoutGlobalScopes()->find($this->modelId);

        if ($this->forget) {
            // A deleted row has nothing left to look up through; a shell
            // instance carrying only the id is enough for forget(), which
            // only ever reads getMorphClass() and getKey().
            $model ??= (new $this->modelClass)->forceFill(['id' => $this->modelId]);

            GlobalSearch::forget($model);

            return;
        }

        if ($model === null) {
            // Gone by the time a worker got to it — nothing to index, and
            // its own delete (if any) will have queued its own forget().
            return;
        }

        app(CurrentCompany::class)->withoutScope(fn () => GlobalSearch::index($model));
    }
}
