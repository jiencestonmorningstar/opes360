<?php

namespace App\Console\Commands;

use App\Models\SearchEntry;
use App\Search\GlobalSearch;
use Illuminate\Console\Command;

/**
 * Rebuild the global search index from the live tables.
 *
 * The observers keep the index current from the moment they are registered;
 * this command covers everything written before that, and repairs whatever a
 * crash or an import left behind. Safe to run repeatedly.
 */
class SearchReindex extends Command
{
    protected $signature = 'opes:search-reindex {--company= : Rebuild one company only, by id}';

    protected $description = 'Rebuild the global search index from the source tables';

    public function handle(): int
    {
        $companyId = $this->option('company');

        SearchEntry::query()->withoutGlobalScopes()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->delete();

        $total = 0;

        foreach (array_keys(GlobalSearch::sources()) as $class) {
            $count = 0;

            $model = new $class;

            $class::query()->withoutGlobalScopes()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                // withoutGlobalScopes drops SoftDeletingScope too; trashed
                // records must not come back into the index. Only models that
                // soft-delete have the column — shipments and manifests, for
                // example, are never deleted at all.
                ->when(
                    method_exists($model, 'getQualifiedDeletedAtColumn'),
                    fn ($q) => $q->whereNull($model->getQualifiedDeletedAtColumn()),
                )
                ->chunkById(200, function ($models) use (&$count) {
                    foreach ($models as $model) {
                        GlobalSearch::index($model);
                        $count++;
                    }
                });

            $this->line(sprintf('%-45s %d', class_basename($class), $count));
            $total += $count;
        }

        $this->info("Indexed {$total} records.");

        return self::SUCCESS;
    }
}
