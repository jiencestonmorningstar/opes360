<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BusinessDocument;
use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * How long the audit trail itself is kept, and the one way it is trimmed.
 *
 * Pruning an audit log is itself a governance act — a business that quietly
 * deletes last year's trail has destroyed evidence — so everything here is
 * deliberate in the way DocumentRetention is deliberate: a stated per-company
 * period, floors a setting cannot undercut, a legal hold that wins outright,
 * and a summary row so the trail records its own trimming.
 *
 * Three tiers, because not every row carries the same weight in the dispute
 * the trail exists for:
 *
 *  - ACCESS — `accessed` rows. "Awa looked at Marie's pay" matters for months,
 *    not decades; these are the rows that triple the table and the ones a
 *    business may reasonably shorten. Floor: 12 months — long enough that a
 *    dispute raised at the next annual review can still ask who read what.
 *  - GENERAL — every other write about ordinary subjects. Floor: 24 months,
 *    so "who changed this and from what" survives at least two full annual
 *    cycles (the year in question and the year it gets argued about).
 *  - FINANCIAL — writes about money and the rows recording exports and
 *    permission grants. Floor: 120 months. OHADA-tradition bookkeeping keeps
 *    accounting records for ten years, and a trail that outlives the books it
 *    explains by less than the books themselves is a trail that goes missing
 *    exactly when an inspector asks. (Stated as our reasoning, not a citation
 *    — the books' own statutory period is the books' problem; the trail
 *    simply refuses to be shorter.)
 *
 * The company setting is one number. It lengthens any tier and shortens only
 * the ACCESS tier below the defaults — each tier takes max(setting, floor),
 * so no setting, however small, undercuts a floor.
 */
class AuditRetention
{
    /** What a company keeps when it has never chosen: two years, every tier's smaller floor. */
    public const DEFAULT_MONTHS = 24;

    public const FLOOR_ACCESS_MONTHS = 12;

    public const FLOOR_GENERAL_MONTHS = 24;

    public const FLOOR_FINANCIAL_MONTHS = 120;

    /**
     * The event a pruning run writes about itself. Never pruned: delete the
     * record that a deletion happened and the trail can no longer account for
     * its own gaps, which is the property the whole table exists for.
     */
    public const SUMMARY_EVENT = 'retention-pruned';

    /** Events that sit in the financial tier regardless of subject. */
    public const FINANCIAL_EVENTS = ['exported', 'permission-changed'];

    /**
     * Subjects whose rows are money. Strings, not ::class imports, so a model
     * renamed or removed later degrades a row to the general tier instead of
     * breaking the pruner.
     */
    public const FINANCIAL_SUBJECTS = [
        'App\Models\Document', 'App\Models\Payment', 'App\Models\Receipt', 'App\Models\Refund',
        'App\Models\Payslip', 'App\Models\PayrollRun', 'App\Models\SalaryComponent',
        'App\Models\PaymentRun', 'App\Models\PaymentRunItem',
        'App\Models\Expense', 'App\Models\ExpensePayment',
        'App\Models\ExpenseClaim', 'App\Models\ExpenseClaimReimbursement',
        'App\Models\BankAccount', 'App\Models\AccountTransfer',
        'App\Models\JournalEntry', 'App\Models\LedgerAccount',
        'App\Models\TaxRate', 'App\Models\FiscalPeriod',
    ];

    /** Deletes are batched so a year of backlog never holds a table lock. */
    protected const CHUNK = 1000;

    /** The three cutoffs for a company, keyed by tier name. */
    public static function cutoffs(?int $settingMonths): array
    {
        $setting = $settingMonths ?? self::DEFAULT_MONTHS;

        return [
            'access' => Carbon::now()->subMonths(max($setting, self::FLOOR_ACCESS_MONTHS)),
            'general' => Carbon::now()->subMonths(max($setting, self::FLOOR_GENERAL_MONTHS)),
            'financial' => Carbon::now()->subMonths(max($setting, self::FLOOR_FINANCIAL_MONTHS)),
        ];
    }

    /**
     * Prune one company's rows. Returns [pruned => int, cutoffs => array].
     *
     * With $pretend the queries are counted and nothing is deleted — and no
     * summary row is written, because a trail entry saying rows were pruned
     * when none were would itself be a false record.
     */
    public static function prune(Company $company, bool $pretend = false): array
    {
        $cutoffs = self::cutoffs($company->audit_retention_months);

        /*
         * Documents under legal hold pin every trail row about them, whatever
         * its age or tier. withTrashed(): a soft-deleted document is still the
         * document the lawyers asked about.
         */
        $held = BusinessDocument::withTrashed()
            ->where('company_id', $company->id)
            ->where('legal_hold', true)
            ->pluck('id')
            ->all();

        $pruned = 0;

        foreach (['access', 'general', 'financial'] as $tier) {
            $query = ActivityLog::query()
                ->where('company_id', $company->id)
                ->where('event', '!=', self::SUMMARY_EVENT)
                // Carbon instant, not ->toDateString(): a date comparison
                // truncates to midnight and moves the cutoff by up to a day.
                ->where('created_at', '<', $cutoffs[$tier]);

            $financial = fn ($q) => $q
                ->whereIn('event', self::FINANCIAL_EVENTS)
                ->orWhereIn('subject_type', self::FINANCIAL_SUBJECTS);

            match ($tier) {
                'access' => $query->where('event', 'accessed'),
                'general' => $query->where('event', '!=', 'accessed')->whereNot($financial),
                'financial' => $query->where('event', '!=', 'accessed')->where($financial),
            };

            if ($held !== []) {
                $query->whereNot(fn ($q) => $q
                    ->where('subject_type', BusinessDocument::class)
                    ->whereIn('subject_id', $held));
            }

            if ($pretend) {
                $pruned += $query->count();

                continue;
            }

            // Chunked by primary key, never by offset: rows vanish under an
            // offset and half the backlog would be skipped every run.
            do {
                $ids = $query->clone()->orderBy('id')->limit(self::CHUNK)->pluck('id');

                $pruned += ActivityLog::query()->whereIn('id', $ids)->delete();
            } while ($ids->count() === self::CHUNK);
        }

        if ($pruned > 0 && ! $pretend) {
            self::recordSummary($company, $pruned, $cutoffs);
        }

        return ['pruned' => $pruned, 'cutoffs' => $cutoffs];
    }

    /** The trail records its own trimming: one row per company per run that removed anything. */
    protected static function recordSummary(Company $company, int $pruned, array $cutoffs): void
    {
        Audit::record(null, self::SUMMARY_EVENT, [
            'pruned' => $pruned,
            'policy' => [
                'retention_months' => $company->audit_retention_months ?? self::DEFAULT_MONTHS,
                'access_before' => $cutoffs['access']->toIso8601String(),
                'general_before' => $cutoffs['general']->toIso8601String(),
                'financial_before' => $cutoffs['financial']->toIso8601String(),
            ],
            'run_by' => 'schedule:opes:prune-audit',
        ], label: sprintf('Pruned %d audit entries under the retention policy', $pruned), companyId: $company->id);
    }
}
