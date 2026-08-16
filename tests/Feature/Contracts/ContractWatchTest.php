<?php

namespace Tests\Feature\Contracts;

use App\Support\ContractWatch;

/**
 * The one thing this feature exists to prevent: a contract renewing itself for
 * another year because nobody saw the notice deadline.
 *
 * Everything else here — expiry lists, overdue obligations — is useful. This
 * is the part that costs money when it is missing, so it is tested hardest:
 * the day before the deadline, the day of it, the day after, and every state a
 * contract can be in that should keep it off the list.
 */
class ContractWatchTest extends ContractsTestCase
{
    // ------------------------------------------------- notice about to lapse

    public function test_a_notice_deadline_inside_the_window_is_raised(): void
    {
        $contract = $this->activeContract([
            'ends_on' => now()->addDays(70)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $this->assertContractIn($contract, $this->watch()->noticeLapsing(30));
    }

    public function test_a_notice_deadline_beyond_the_window_is_not_raised(): void
    {
        $contract = $this->activeContract([
            'ends_on' => now()->addDays(120)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $this->assertContractNotIn($contract, $this->watch()->noticeLapsing(30));
    }

    /**
     * The boundary, both sides. An off-by-one here is the entire failure: a
     * deadline that falls on the last day of the window must appear, and one
     * that falls the day after must not.
     */
    public function test_the_window_includes_its_last_day_and_excludes_the_next(): void
    {
        $onTheEdge = $this->activeContract([
            'ends_on' => now()->addDays(90)->toDateString(),
            'notice_period_days' => 60,   // deadline in exactly 30 days
        ]);

        $justOutside = $this->activeContract([
            'title' => 'Just outside',
            'ends_on' => now()->addDays(91)->toDateString(),
            'notice_period_days' => 60,   // deadline in 31 days
        ]);

        $lapsing = $this->watch()->noticeLapsing(30);

        $this->assertContractIn($onTheEdge, $lapsing);
        $this->assertContractNotIn($justOutside, $lapsing);
    }

    /**
     * A deadline already passed is a different list. Mixed into "coming up" it
     * would be lost among deadlines somebody can still act on — and this one
     * needs a decision today, not a reminder.
     */
    public function test_a_passed_deadline_moves_to_the_missed_list(): void
    {
        $contract = $this->activeContract([
            'ends_on' => now()->addDays(30)->toDateString(),
            'notice_period_days' => 60,   // deadline was 30 days ago
        ]);

        $this->assertContractNotIn($contract, $this->watch()->noticeLapsing(30));
        $this->assertContractIn($contract, $this->watch()->noticeMissed());
    }

    /**
     * Missing the deadline on a contract that does not renew itself is an
     * inconvenience. Missing it on one that does is another year of spend.
     */
    public function test_the_missed_list_separates_the_ones_that_will_renew_themselves(): void
    {
        $auto = $this->activeContract([
            'ends_on' => now()->addDays(30)->toDateString(),
            'notice_period_days' => 60,
            'renewal_type' => 'auto',
        ]);

        $manual = $this->activeContract([
            'title' => 'Manual renewal',
            'ends_on' => now()->addDays(30)->toDateString(),
            'notice_period_days' => 60,
            'renewal_type' => 'manual',
        ]);

        $this->assertContractIn($auto, $this->watch()->autoRenewingWithNoticeMissed());
        $this->assertContractNotIn($manual, $this->watch()->autoRenewingWithNoticeMissed());
        $this->assertContractIn($manual, $this->watch()->noticeMissed());
    }

    public function test_a_draft_contract_never_appears(): void
    {
        $contract = $this->contract([
            'ends_on' => now()->addDays(70)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $this->assertContractNotIn($contract, $this->watch()->noticeLapsing(30));
        $this->assertContractNotIn($contract, $this->watch()->noticeMissed());
    }

    public function test_a_terminated_contract_never_appears(): void
    {
        $contract = $this->activeContract([
            'ends_on' => now()->addDays(70)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $this->service()->terminate($contract->fresh(), ['reason' => 'Ended early'], $this->owner);

        $this->assertContractNotIn($contract, $this->watch()->noticeLapsing(30));
        $this->assertContractNotIn($contract, $this->watch()->noticeMissed());
    }

    /**
     * Renewing is exactly the decision the alarm was asking for, so the alarm
     * must stop. A watchlist that keeps shouting after the work is done is one
     * people learn to close without reading.
     */
    public function test_renewing_clears_the_warning(): void
    {
        $contract = $this->activeContract([
            'ends_on' => now()->addDays(70)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $this->assertContractIn($contract, $this->watch()->noticeLapsing(30));

        $this->service()->renew($contract->fresh(), [
            'new_ends_on' => now()->addYears(2)->toDateString(),
        ], $this->owner);

        $this->assertContractNotIn($contract, $this->watch()->noticeLapsing(30));
    }

    public function test_the_watch_is_read_only(): void
    {
        $contract = $this->activeContract([
            'ends_on' => now()->addDays(70)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $before = $contract->fresh()->updated_at;

        $this->watch()->noticeLapsing(30);
        $this->watch()->summary();

        $this->assertEquals($before, $contract->fresh()->updated_at);
    }

    // ---------------------------------------------------------- expiring soon

    public function test_expiring_lists_contracts_ending_inside_the_window(): void
    {
        $soon = $this->activeContract([
            'ends_on' => now()->addDays(20)->toDateString(),
            'renewal_type' => 'none',
            'notice_period_days' => null,
        ]);

        $later = $this->activeContract([
            'title' => 'Later',
            'ends_on' => now()->addDays(200)->toDateString(),
            'renewal_type' => 'none',
            'notice_period_days' => null,
        ]);

        $expiring = $this->watch()->expiring(60);

        $this->assertContractIn($soon, $expiring);
        $this->assertContractNotIn($later, $expiring);
    }

    /**
     * Already-lapsed contracts are excluded on the same reasoning
     * BusinessDocument::scopeExpiringWithin uses: "expiring" is a prompt to act
     * before a deadline, and mixing in the ones already missed makes it a list
     * people stop opening.
     */
    public function test_an_already_ended_contract_is_not_expiring(): void
    {
        $contract = $this->activeContract([
            'starts_on' => now()->subYears(2)->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
            'renewal_type' => 'none',
            'notice_period_days' => null,
        ]);

        $this->assertContractNotIn($contract, $this->watch()->expiring(60));
        $this->assertContractIn($contract, $this->watch()->lapsed());
    }

    // ---------------------------------------------------- overdue obligations

    public function test_an_obligation_past_its_date_and_not_done_is_overdue(): void
    {
        $contract = $this->activeContract();

        $this->service()->addObligation($contract, [
            'owed_by' => 'them',
            'title' => 'Quarterly report',
            'due_on' => now()->subDays(5)->toDateString(),
        ], $this->owner);

        $this->assertSame(['Quarterly report'], $this->watch()->overdueObligations()->pluck('title')->all());
    }

    public function test_a_completed_obligation_is_not_overdue(): void
    {
        $contract = $this->activeContract();

        $obligation = $this->service()->addObligation($contract, [
            'owed_by' => 'them',
            'title' => 'Quarterly report',
            'due_on' => now()->subDays(5)->toDateString(),
        ], $this->owner);

        $this->service()->completeObligation($obligation, $this->owner);

        $this->assertTrue($this->watch()->overdueObligations()->isEmpty());
    }

    public function test_an_obligation_with_no_date_is_never_overdue(): void
    {
        $contract = $this->activeContract();

        $this->service()->addObligation($contract, [
            'owed_by' => 'them',
            'title' => 'Keep the site tidy',
        ], $this->owner);

        $this->assertTrue($this->watch()->overdueObligations()->isEmpty());
    }

    /**
     * An obligation under a contract that has been terminated is nobody's
     * problem. Leaving it on the list is how a to-do list fills with work that
     * has been called off.
     */
    public function test_obligations_under_a_dead_contract_drop_off(): void
    {
        $contract = $this->activeContract();

        $this->service()->addObligation($contract, [
            'owed_by' => 'them',
            'title' => 'Quarterly report',
            'due_on' => now()->subDays(5)->toDateString(),
        ], $this->owner);

        $this->service()->terminate($contract->fresh(), ['reason' => 'Ended'], $this->owner);

        $this->assertTrue($this->watch()->overdueObligations()->isEmpty());
    }

    // ------------------------------------------------------------- summary

    public function test_the_summary_counts_each_list(): void
    {
        $this->activeContract([
            'ends_on' => now()->addDays(70)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $missed = $this->activeContract([
            'title' => 'Missed',
            'ends_on' => now()->addDays(30)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $this->service()->addObligation($missed, [
            'owed_by' => 'us',
            'title' => 'Serve notice',
            'due_on' => now()->subDay()->toDateString(),
        ], $this->owner);

        $summary = $this->watch()->summary();

        $this->assertSame(1, $summary['notice_lapsing']);
        $this->assertSame(1, $summary['notice_missed']);
        $this->assertSame(1, $summary['auto_renewing_at_risk']);
        $this->assertSame(1, $summary['overdue_obligations']);
    }

    protected function watch(): ContractWatch
    {
        return new ContractWatch;
    }
}
