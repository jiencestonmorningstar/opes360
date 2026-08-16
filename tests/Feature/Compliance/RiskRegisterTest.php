<?php

namespace Tests\Feature\Compliance;

use App\Models\Risk;
use App\Models\RiskControl;
use App\Services\Compliance\RiskRegister;
use RuntimeException;

/**
 * What could go wrong, how badly, and what is being done about it.
 *
 * The register's only real job is to keep two numbers apart: the risk as it
 * stands with nothing done, and the risk as it stands with the controls the
 * business actually has. Collapsing them is how a register comes to say
 * everything is fine.
 */
class RiskRegisterTest extends ComplianceTestCase
{
    protected function risks(): RiskRegister
    {
        return app(RiskRegister::class);
    }

    protected function risk(array $overrides = []): Risk
    {
        return $this->risks()->record(array_merge([
            'title' => 'Single supplier for diesel',
            'category' => 'operational',
            'likelihood' => 4,
            'impact' => 5,
        ], $overrides), $this->owner);
    }

    // ─────────────────────────────────────────────────────── scoring ──

    /**
     * The score is computed, never stored.
     *
     * A stored score is a number that can disagree with the two numbers it
     * came from — and it will, the first time somebody edits the likelihood
     * through a screen that forgot to recalculate.
     */
    public function test_the_inherent_score_is_likelihood_times_impact(): void
    {
        $risk = $this->risk();

        $this->assertSame(20, $risk->inherentScore());
        $this->assertSame('severe', $risk->inherentBand());
    }

    /** Every band has a boundary, and boundaries are where scoring goes wrong. */
    public function test_the_bands_are_where_they_are_documented_to_be(): void
    {
        $this->assertSame('low', $this->risk(['likelihood' => 2, 'impact' => 2])->inherentBand());
        $this->assertSame('moderate', $this->risk(['likelihood' => 3, 'impact' => 3])->inherentBand());
        $this->assertSame('high', $this->risk(['likelihood' => 2, 'impact' => 5])->inherentBand());
        $this->assertSame('severe', $this->risk(['likelihood' => 3, 'impact' => 5])->inherentBand());
    }

    /** A score outside the scale is a typo, and a typo here mis-ranks the register. */
    public function test_a_score_outside_the_scale_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/between 1 and 5/');

        $this->risk(['likelihood' => 7]);
    }

    /**
     * Residual falls back to inherent until somebody has actually reassessed.
     *
     * The alternative — assuming a control works and lowering the number
     * automatically — is the single most dangerous thing a risk register can
     * do, because it produces a reassuring figure nobody chose.
     */
    public function test_residual_equals_inherent_until_the_risk_is_reassessed(): void
    {
        $risk = $this->risk();

        $this->assertSame(20, $risk->residualScore());

        $this->risks()->addControl($risk, [
            'title' => 'Second approved fuel supplier',
            'kind' => 'preventive',
            'status' => 'in_place',
        ], $this->owner);

        $this->assertSame(
            20,
            $risk->fresh()->residualScore(),
            'A control on paper has not lowered anything yet.',
        );
    }

    /** Lowering the residual is a judgement somebody makes and signs. */
    public function test_reassessing_records_the_residual_the_assessor_chose(): void
    {
        $risk = $this->risk();

        $this->risks()->reassess($risk, residualLikelihood: 2, residualImpact: 5);

        $fresh = $risk->fresh();

        $this->assertSame(20, $fresh->inherentScore(), 'The untreated risk is unchanged.');
        $this->assertSame(10, $fresh->residualScore());
        $this->assertSame('high', $fresh->residualBand());
    }

    // ─────────────────────────────────────────────────────── reviews ──

    /**
     * A review's clock runs from the day the review actually happened.
     *
     * Same reasoning as servicing a van, and the opposite of a statutory
     * deadline: the question a review answers is "how stale is what we
     * believe about this risk", and staleness is measured from the last time
     * somebody looked, not from the day the diary said they should have.
     */
    public function test_the_next_review_is_counted_from_when_the_review_happened(): void
    {
        $risk = $this->risk([
            'review_interval_months' => 6,
            'next_review_on' => now()->subMonths(2)->toDateString(),
        ]);

        $reviewedOn = now()->startOfDay();
        $this->risks()->review($risk, $reviewedOn, 'Still one supplier.', $this->owner);

        $fresh = $risk->fresh();

        $this->assertSame($reviewedOn->toDateString(), $fresh->last_reviewed_on->toDateString());
        $this->assertSame(
            $reviewedOn->copy()->addMonths(6)->toDateString(),
            $fresh->next_review_on->toDateString(),
        );
    }

    /** A risk with no review cadence is reviewed when somebody decides to, not never. */
    public function test_reviewing_a_risk_with_no_cadence_leaves_no_next_date(): void
    {
        $risk = $this->risk(['review_interval_months' => null]);

        $this->risks()->review($risk, now(), 'Noted.', $this->owner);

        $this->assertNull($risk->fresh()->next_review_on);
        $this->assertNotNull($risk->fresh()->last_reviewed_on);
    }

    // ────────────────────────────────────────────────────── controls ──

    /** A control belongs to its risk and to nobody else's. */
    public function test_a_control_cannot_be_hung_on_another_businesss_risk(): void
    {
        $risk = $this->risk();

        $this->risks()->addControl($risk, [
            'title' => 'Hold two weeks of fuel',
            'kind' => 'corrective',
            'status' => 'planned',
            'due_on' => now()->addMonth()->toDateString(),
        ], $this->owner);

        $this->assertSame(1, $risk->controls()->count());
        $this->assertSame($this->company->id, RiskControl::first()->company_id);
    }

    /**
     * Marking a control in place records when — a control everybody agrees
     * exists but nobody can date is not evidence of anything.
     */
    public function test_putting_a_control_in_place_records_the_date(): void
    {
        $risk = $this->risk();
        $control = $this->risks()->addControl($risk, [
            'title' => 'Second approved supplier',
            'kind' => 'preventive',
            'status' => 'planned',
        ], $this->owner);

        $this->risks()->markControlInPlace($control, now(), 4);

        $fresh = $control->fresh();

        $this->assertSame('in_place', $fresh->status);
        $this->assertSame(now()->toDateString(), $fresh->implemented_on->toDateString());
        $this->assertSame(4, (int) $fresh->effectiveness);
    }

    // ───────────────────────────────────────────────────── closure ──

    /** Closing needs a reason, because "why is this gone" is the audit question. */
    public function test_closing_a_risk_needs_a_reason(): void
    {
        $risk = $this->risk();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reason/');

        $this->risks()->close($risk, '   ', $this->owner);
    }

    public function test_a_closed_risk_is_not_reopened_by_a_review(): void
    {
        $risk = $this->risk(['review_interval_months' => 3]);
        $this->risks()->close($risk, 'The supplier was dual-sourced and the contract signed.', $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/closed/');

        $this->risks()->review($risk->fresh(), now(), 'Checking.', $this->owner);
    }

    /** The register is ordered by what would hurt most, not by what was typed first. */
    public function test_the_register_ranks_by_untreated_severity(): void
    {
        $small = $this->risk(['title' => 'Printer failure', 'likelihood' => 1, 'impact' => 2]);
        $large = $this->risk(['title' => 'Fuel supply', 'likelihood' => 4, 'impact' => 5]);
        $middling = $this->risk(['title' => 'Staff turnover', 'likelihood' => 3, 'impact' => 3]);

        $ranked = Risk::query()->open()->mostSevereFirst()->pluck('id')->all();

        $this->assertSame([$large->id, $middling->id, $small->id], $ranked);
    }
}
