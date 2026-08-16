<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fiscal years, the periods inside them, and closing the books.
 *
 * The point of closing is that "what did the books say in March" keeps having
 * one answer. A closed period refuses new postings outright — enforced in
 * Ledger::post(), the single path every module reaches the books through, so
 * there is exactly one place the rule lives.
 */
class FiscalPeriods
{
    /**
     * A year and its twelve months in one go.
     *
     * Monthly is what almost every business this serves actually closes on;
     * a business wanting quarters can create the year and add periods by
     * hand. Building a period-frequency setting for a case nobody has asked
     * for yet would be inventing a requirement.
     */
    public function createYearWithMonths(Company $company, string $name, CarbonImmutable $startsOn, ?User $actor = null): FiscalYear
    {
        return DB::transaction(function () use ($company, $name, $startsOn) {
            $endsOn = $startsOn->addYear()->subDay();

            $year = FiscalYear::create([
                'company_id' => $company->id,
                'name' => $name,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => 'open',
            ]);

            for ($cursor = $startsOn, $i = 0; $i < 12; $i++, $cursor = $cursor->addMonth()) {
                FiscalPeriod::create([
                    'company_id' => $company->id,
                    'fiscal_year_id' => $year->id,
                    'name' => $cursor->format('F Y'),
                    'starts_on' => $cursor->startOfMonth()->toDateString(),
                    'ends_on' => $cursor->endOfMonth()->toDateString(),
                    'status' => 'open',
                ]);
            }

            return $year->fresh();
        });
    }

    /** The period covering a date, or null if the business has not defined one. */
    public function periodFor(Company $company, \DateTimeInterface $date): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
    }

    /**
     * Whether the books accept a posting dated here.
     *
     * A business that has defined no periods at all is unrestricted — the
     * feature is opt-in, and every business that existed before this shipped
     * must keep working untouched. Only an explicitly closed period refuses.
     */
    public function isPostingAllowed(Company $company, \DateTimeInterface $date): bool
    {
        $period = $this->periodFor($company, $date);

        if ($period === null) {
            return true;
        }

        return ! $period->isClosed() && ! $period->fiscalYear->isClosed();
    }

    public function closePeriod(FiscalPeriod $period, User $actor): FiscalPeriod
    {
        if ($period->isClosed()) {
            throw new RuntimeException('That period is already closed.');
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ]);

        return $period->fresh();
    }

    /**
     * Reopening is deliberately possible, and deliberately an explicit act
     * with its own permission. A period closed by mistake on the last day of
     * the month is a real thing that happens, and a product that made it
     * unrecoverable would have people avoid closing at all — which defeats
     * the point of having periods.
     */
    public function reopenPeriod(FiscalPeriod $period): FiscalPeriod
    {
        if ($period->fiscalYear->isClosed()) {
            throw new RuntimeException('Reopen the financial year before reopening a period inside it.');
        }

        $period->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        return $period->fresh();
    }

    /** Closing a year closes every period in it — a year is not closed while a month inside it is open. */
    public function closeYear(FiscalYear $year, User $actor): FiscalYear
    {
        return DB::transaction(function () use ($year, $actor) {
            $year->periods()->where('status', 'open')->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            $year->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            return $year->fresh();
        });
    }

    /**
     * Reopening a year does not reopen its periods. Reopening the year is
     * permission to reopen a month inside it; it is not a decision that
     * every month should be open again, which would usually be wrong.
     */
    public function reopenYear(FiscalYear $year): FiscalYear
    {
        $year->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        return $year->fresh();
    }
}
