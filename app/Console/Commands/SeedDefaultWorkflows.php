<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Workflow;
use App\Support\DefaultWorkflows;
use Illuminate\Console\Command;

/**
 * Give existing businesses the approval paths new ones now start with.
 *
 * `DefaultWorkflows::seed()` runs when a company is created, which does
 * nothing for the businesses that already exist — and those are precisely the
 * ones with work waiting. Every one of them currently refuses every
 * submit-for-approval with "no approval path is defined".
 *
 * Safe to run as often as you like: a business that already has a path for a
 * subject keeps exactly what it has, including one it has edited itself.
 */
class SeedDefaultWorkflows extends Command
{
    protected $signature = 'opes:seed-workflows
                            {--company= : Just this company, by slug or id}
                            {--pretend : Say what would happen and change nothing}';

    protected $description = 'Give businesses without approval paths the default ones';

    public function handle(): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $ref) => $q
                ->where('slug', $ref)
                ->orWhere('id', $ref))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No businesses matched.');

            return self::SUCCESS;
        }

        $seeded = 0;

        foreach ($companies as $company) {
            $before = $this->pathCount($company);

            if ($before >= count(DefaultWorkflows::subjects())) {
                continue;
            }

            if ($this->option('pretend')) {
                $this->line("  {$company->name}: would gain ".
                    (count(DefaultWorkflows::subjects()) - $before).' path(s)');
                $seeded++;

                continue;
            }

            DefaultWorkflows::seed($company);

            $gained = $this->pathCount($company) - $before;
            $this->line("  {$company->name}: +{$gained} path(s), threshold ".
                number_format(DefaultWorkflows::thresholdFor($company))." {$company->currency}");
            $seeded++;
        }

        $this->info($this->option('pretend')
            ? "{$seeded} business(es) would be given approval paths."
            : "{$seeded} business(es) given approval paths.");

        return self::SUCCESS;
    }

    /**
     * Counted without the tenant scope: this runs from the console, where
     * there is no current company, and the global scope would otherwise
     * return nothing for everybody.
     */
    protected function pathCount(Company $company): int
    {
        return Workflow::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('subject_type', DefaultWorkflows::subjects())
            ->distinct()
            ->count('subject_type');
    }
}
