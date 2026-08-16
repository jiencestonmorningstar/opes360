<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\AuditRetention;
use Illuminate\Console\Command;

/**
 * Trim the audit trail under each company's stated retention policy.
 *
 * Not `model:prune`: pruning an audit log is a governance act, and this one
 * runs tiered cutoffs (reads before writes before money), honours the
 * documents module's legal holds, and leaves a summary row in the trail it
 * trimmed — see App\Support\AuditRetention, where the policy actually lives.
 *
 * `--pretend` reports what a run would remove and deletes nothing.
 */
class PruneAuditLog extends Command
{
    protected $signature = 'opes:prune-audit
        {--pretend : Count what would be pruned without deleting anything}';

    protected $description = 'Prune audit trail entries past their company\'s retention policy';

    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $total = 0;

        // withTrashed(): a soft-deleted company's rows still age out — its
        // trail should not become the one immortal dataset in the database.
        foreach (Company::withTrashed()->cursor() as $company) {
            $result = AuditRetention::prune($company, $pretend);

            if ($result['pruned'] > 0) {
                $this->line(sprintf(
                    '%s: %s %d entries (kept: reads %s, writes %s, financial %s).',
                    $company->name,
                    $pretend ? 'would prune' : 'pruned',
                    $result['pruned'],
                    $result['cutoffs']['access']->toDateString(),
                    $result['cutoffs']['general']->toDateString(),
                    $result['cutoffs']['financial']->toDateString(),
                ));
            }

            $total += $result['pruned'];
        }

        $this->info($total === 0
            ? 'Nothing past retention.'
            : sprintf('%s %d entries in all.', $pretend ? 'Would prune' : 'Pruned', $total));

        return self::SUCCESS;
    }
}
