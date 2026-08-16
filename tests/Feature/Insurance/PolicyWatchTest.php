<?php

namespace Tests\Feature\Insurance;

use App\Support\PolicyWatch;

/**
 * The watch's three lists, and the walls between them.
 *
 * Kept apart on ContractWatch's reasoning: a list that mixes "act before
 * Friday" with "it is already too late" gets skimmed, and then neither gets
 * done.
 */
class PolicyWatchTest extends InsuranceTestCase
{
    public function test_cover_ending_inside_the_window_is_lapsing(): void
    {
        $soon = $this->activePolicy(['covers_to' => now()->addDays(10)->toDateString()]);
        $far = $this->activePolicy(['covers_to' => now()->addDays(90)->toDateString()]);

        $watch = new PolicyWatch;

        $this->assertPolicyIn($soon, $watch->coverLapsing(30));
        $this->assertPolicyNotIn($far, $watch->coverLapsing(30));
        $this->assertPolicyNotIn($soon, $watch->lapsed());
    }

    public function test_cover_ending_today_is_lapsing_not_lapsed(): void
    {
        // The date-cast midnight trap: covers_to is a date, "today" is an
        // instant. Ending today must read as the most urgent actionable row,
        // never as already gone.
        $today = $this->activePolicy(['covers_to' => now()->toDateString()]);

        $watch = new PolicyWatch;

        $this->assertPolicyIn($today, $watch->coverLapsing(30));
        $this->assertPolicyNotIn($today, $watch->lapsed());
    }

    public function test_cover_already_out_is_lapsed(): void
    {
        $gone = $this->activePolicy(['covers_to' => now()->subDays(3)->toDateString()]);

        $watch = new PolicyWatch;

        $this->assertPolicyIn($gone, $watch->lapsed());
        $this->assertPolicyNotIn($gone, $watch->coverLapsing(30));
        // Renewed by agreement, so it is late but not self-renewing.
        $this->assertPolicyNotIn($gone, $watch->lapsedOnAutoRenew());
    }

    public function test_lapsed_auto_renewing_cover_is_the_alarm_and_a_subset(): void
    {
        $rolling = $this->activePolicy([
            'covers_to' => now()->subDays(5)->toDateString(),
            'renewal_type' => 'auto',
            'notice_period_days' => 30,
        ]);

        $watch = new PolicyWatch;

        // A subset, not an extra category: the same policy in both lists is
        // the point.
        $this->assertPolicyIn($rolling, $watch->lapsed());
        $this->assertPolicyIn($rolling, $watch->lapsedOnAutoRenew());

        $summary = $watch->summary();
        $this->assertSame(1, $summary['lapsed']);
        $this->assertSame(1, $summary['lapsed_on_auto_renew']);
    }

    public function test_drafts_and_cancelled_cover_never_appear(): void
    {
        $draft = $this->policy(['covers_to' => now()->subDays(3)->toDateString()]);

        $cancelled = $this->activePolicy(['covers_to' => now()->addDays(5)->toDateString()]);
        $this->policies()->cancel($cancelled, [], $this->owner);
        $cancelled = $cancelled->fresh();

        $watch = new PolicyWatch;

        $this->assertPolicyNotIn($draft, $watch->lapsed());
        $this->assertPolicyNotIn($cancelled, $watch->coverLapsing(30));
    }

    public function test_open_cover_with_no_end_date_never_lapses(): void
    {
        $open = $this->activePolicy(['covers_to' => null]);

        $watch = new PolicyWatch;

        $this->assertPolicyNotIn($open, $watch->coverLapsing(365));
        $this->assertPolicyNotIn($open, $watch->lapsed());
    }

    public function test_notice_by_is_recomputed_on_save_from_the_terms(): void
    {
        // The stored notice_by column follows covers_to and the notice period
        // on every save — the contracts pattern, copied for the same reason:
        // extending cover and leaving the notice date behind is the likeliest
        // quiet failure.
        $policy = $this->activePolicy([
            'covers_to' => now()->addDays(90)->toDateString(),
            'renewal_type' => 'auto',
            'notice_period_days' => 30,
        ]);

        $this->assertSame(
            now()->addDays(60)->toDateString(),
            $policy->notice_by->toDateString(),
        );

        $policy->forceFill(['covers_to' => now()->addDays(120)->toDateString()])->save();

        $this->assertSame(
            now()->addDays(90)->toDateString(),
            $policy->fresh()->notice_by->toDateString(),
        );
    }

    public function test_an_auto_renewing_policy_with_no_notice_period_is_refused(): void
    {
        $this->expectExceptionMessage('needs a notice period');

        $this->policy([
            'renewal_type' => 'auto',
            'notice_period_days' => null,
        ]);
    }
}
