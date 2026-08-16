<?php

namespace Tests\Feature\Compliance;

use App\Models\Company;
use App\Models\Risk;
use App\Services\Compliance\ComplianceRegister;
use App\Services\Compliance\RiskRegister;
use App\Support\ComplianceCalendar;
use App\Support\CurrentCompany;
use Illuminate\Support\Str;

/**
 * "What is due, and what have we already missed."
 *
 * This is the read model the whole module exists to produce. Everything else
 * — obligations, filings, risks — is bookkeeping in service of one screen
 * somebody looks at on a Monday morning.
 */
class ComplianceCalendarTest extends ComplianceTestCase
{
    protected function calendar(): ComplianceCalendar
    {
        return app(ComplianceCalendar::class);
    }

    /** Missed, imminent and merely future are three different answers. */
    public function test_overdue_due_soon_and_upcoming_are_kept_apart(): void
    {
        $missed = $this->obligation(['name' => 'CNPS declaration', 'next_due_on' => now()->subWeek()->toDateString()]);
        $soon = $this->obligation(['name' => 'TVA declaration', 'next_due_on' => now()->addDays(5)->toDateString()]);
        $later = $this->obligation(['name' => 'Trade licence', 'next_due_on' => now()->addMonths(4)->toDateString()]);

        $this->assertSame([$missed->id], $this->calendar()->overdue()->pluck('id')->all());
        $this->assertSame([$soon->id], $this->calendar()->dueSoon()->pluck('id')->all());

        $upcoming = $this->calendar()->upcoming(365)->pluck('id')->all();

        $this->assertContains($soon->id, $upcoming);
        $this->assertContains($later->id, $upcoming);
        $this->assertNotContains($missed->id, $upcoming, 'A missed deadline is not upcoming.');
    }

    /**
     * How soon "soon" is belongs to the obligation, not to the screen. A
     * declaration you can prepare in an afternoon and a licence renewal that
     * needs a bank attestation are not warned about at the same distance.
     */
    public function test_each_obligation_sets_its_own_warning_distance(): void
    {
        $this->obligation(['name' => 'Quick return', 'lead_days' => 7, 'next_due_on' => now()->addDays(20)->toDateString()]);
        $slow = $this->obligation(['name' => 'Licence renewal', 'lead_days' => 60, 'next_due_on' => now()->addDays(20)->toDateString()]);

        $this->assertSame([$slow->id], $this->calendar()->dueSoon()->pluck('id')->all());
    }

    /**
     * The bug this module was warned about before a line of it was written.
     *
     * A date range whose last day is bounded at midnight silently drops
     * everything due on that day — which, for a compliance calendar, is
     * precisely the deadline itself. This pins the fix.
     */
    public function test_a_deadline_on_the_last_day_of_the_range_is_included(): void
    {
        $lastDay = now()->addDays(30);
        $obligation = $this->obligation(['next_due_on' => $lastDay->toDateString()]);

        $found = $this->calendar()->dueBetween(now(), $lastDay)->pluck('id')->all();

        $this->assertSame([$obligation->id], $found, 'The last day of the window counts.');
    }

    /** An obligation that no longer applies must not keep shouting. */
    public function test_an_inactive_obligation_drops_off_the_calendar(): void
    {
        $this->obligation(['next_due_on' => now()->subWeek()->toDateString(), 'is_active' => false]);

        $this->assertCount(0, $this->calendar()->overdue());
    }

    /**
     * Work already under way is not the same as work not started. A return
     * being drafted still has a deadline, so it stays on the calendar — but
     * it is flagged, because chasing somebody who is already doing it is how
     * a register loses its audience.
     */
    public function test_an_obligation_already_being_worked_on_is_flagged_not_hidden(): void
    {
        $obligation = $this->obligation(['next_due_on' => now()->subWeek()->toDateString()]);
        app(ComplianceRegister::class)->raise($obligation);

        $overdue = $this->calendar()->overdue();

        $this->assertCount(1, $overdue, 'Still overdue — a draft is not a filing.');
        $this->assertTrue($overdue->first()->hasOpenFiling());
    }

    /** Filing it clears it, because the obligation itself has moved on. */
    public function test_filing_clears_the_obligation_off_the_overdue_list(): void
    {
        $obligation = $this->obligation(['next_due_on' => now()->subWeek()->toDateString()]);

        app(ComplianceRegister::class)->complete(
            app(ComplianceRegister::class)->raise($obligation),
            now(),
        );

        $this->assertCount(0, $this->calendar()->overdue());
    }

    /** Risks go stale. A register nobody revisits is a document, not a control. */
    public function test_risks_past_their_review_date_appear_on_the_calendar(): void
    {
        $stale = app(RiskRegister::class)->record([
            'title' => 'Key customer concentration',
            'category' => 'financial',
            'likelihood' => 3,
            'impact' => 5,
            'next_review_on' => now()->subWeek()->toDateString(),
        ], $this->owner);

        app(RiskRegister::class)->record([
            'title' => 'Generator failure',
            'category' => 'operational',
            'likelihood' => 2,
            'impact' => 3,
            'next_review_on' => now()->addMonths(2)->toDateString(),
        ], $this->owner);

        $this->assertSame([$stale->id], $this->calendar()->risksDueForReview()->pluck('id')->all());
    }

    /** A closed risk is not overdue for review; it is finished. */
    public function test_a_closed_risk_is_not_chased_for_review(): void
    {
        $risk = app(RiskRegister::class)->record([
            'title' => 'Import duty change',
            'category' => 'regulatory',
            'likelihood' => 2,
            'impact' => 2,
            'next_review_on' => now()->subWeek()->toDateString(),
        ], $this->owner);

        app(RiskRegister::class)->close($risk, 'The tariff was withdrawn.', $this->owner);

        $this->assertCount(0, $this->calendar()->risksDueForReview());
        $this->assertSame('closed', $risk->fresh()->status);
    }

    /** One call for the dashboard, so five screens cannot count it five ways. */
    public function test_the_summary_counts_what_a_dashboard_needs(): void
    {
        $this->obligation(['name' => 'CNPS', 'next_due_on' => now()->subWeek()->toDateString()]);
        $this->obligation(['name' => 'TVA', 'next_due_on' => now()->addDays(3)->toDateString()]);
        $this->obligation(['name' => 'Licence', 'next_due_on' => now()->addMonths(6)->toDateString()]);

        app(RiskRegister::class)->record([
            'title' => 'Single supplier for fuel',
            'category' => 'operational',
            'likelihood' => 4,
            'impact' => 5,
            'next_review_on' => now()->subDay()->toDateString(),
        ], $this->owner);

        $summary = $this->calendar()->summary();

        $this->assertSame(1, $summary['overdue']);
        $this->assertSame(1, $summary['due_soon']);
        $this->assertSame(3, $summary['obligations']);
        $this->assertSame(1, $summary['risks_open']);
        $this->assertSame(1, $summary['risks_to_review']);
        $this->assertSame(1, $summary['risks_severe'], 'Likelihood 4 × impact 5 is not something to bury in a list.');
    }

    /** Another business's deadlines are none of this one's business. */
    public function test_the_calendar_is_scoped_to_one_business(): void
    {
        $this->obligation(['next_due_on' => now()->subWeek()->toDateString()]);

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        app(CurrentCompany::class)->set($other);

        $this->assertCount(0, $this->calendar()->overdue());
        $this->assertSame(0, Risk::count());
    }
}
