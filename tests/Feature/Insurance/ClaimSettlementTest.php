<?php

namespace Tests\Feature\Insurance;

use App\Models\InsuranceClaim;
use App\Models\Role;
use App\Services\Insurance\Claims;
use App\Services\Insurance\Policies;
use RuntimeException;

/**
 * The one decision with money in it goes through the one engine.
 *
 * There is no approve() anywhere in this vertical, and the last test here
 * asserts that stays true — a second approval path is precisely how a
 * product ends up with two of them disagreeing.
 */
class ClaimSettlementTest extends InsuranceTestCase
{
    public function test_a_claim_cannot_settle_while_its_approval_is_running(): void
    {
        $this->settlementWorkflow();
        $this->memberAt(Role::MANAGER);

        $policy = $this->activePolicy();
        $claim = $this->claims()->open($policy, [
            'incident_on' => now()->subDays(10)->toDateString(),
            'description' => 'Rear-end collision at Carrefour Bastos.',
            'claimed_amount' => 400_000,
        ], $this->owner);

        $this->claims()->assess($claim, [], $this->owner);
        $this->claims()->submitSettlement($claim->fresh(), 350_000, $this->owner);

        // The engine's record — not a status column — is what refuses.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still waiting for approval');

        $this->claims()->settle($claim->fresh());
    }

    public function test_a_claim_cannot_settle_around_a_defined_workflow(): void
    {
        // A workflow exists but nobody submitted this claim to it. Settling
        // directly would be settling behind the engine's back.
        $this->settlementWorkflow();

        $policy = $this->activePolicy();
        $claim = $this->claims()->open($policy, [
            'incident_on' => now()->subDays(4)->toDateString(),
            'description' => 'Warehouse fire, partial stock loss.',
        ], $this->owner);
        $this->claims()->assess($claim, [], $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('go through an approval workflow');

        $this->claims()->settle($claim->fresh(), 1_000_000);
    }

    public function test_the_engines_yes_settles_the_claim_through_the_listener(): void
    {
        // No Event::fake() anywhere here — faking would disable the listener,
        // so the proof is the side effect itself: the engine approves, and
        // the claim comes out settled at the amount the approver saw.
        $this->settlementWorkflow();
        $manager = $this->memberAt(Role::MANAGER);

        $policy = $this->activePolicy();
        $claim = $this->claims()->open($policy, [
            'incident_on' => now()->subDays(10)->toDateString(),
            'description' => 'Windscreen and bodywork.',
            'claimed_amount' => 400_000,
        ], $this->owner);
        $this->claims()->assess($claim, [], $this->owner);

        $instance = $this->claims()->submitSettlement($claim->fresh(), 350_000, $this->owner);

        $this->engine()->act($instance, $manager, 'approved');

        $claim = $claim->fresh();
        $this->assertSame('settled', $claim->status);
        $this->assertSame('350000.00', (string) $claim->settled_amount);
        $this->assertNotNull($claim->settled_on);
    }

    public function test_a_rejected_settlement_leaves_the_claim_open(): void
    {
        $this->settlementWorkflow();
        $manager = $this->memberAt(Role::MANAGER);

        $policy = $this->activePolicy();
        $claim = $this->claims()->open($policy, [
            'incident_on' => now()->subDays(2)->toDateString(),
            'description' => 'Disputed liability.',
        ], $this->owner);
        $this->claims()->assess($claim, [], $this->owner);

        $instance = $this->claims()->submitSettlement($claim->fresh(), 200_000, $this->owner);
        $this->engine()->act($instance, $manager, 'rejected');

        // "No" from the engine is not "rejected by the insurer" — the claim
        // stays assessed for a better offer or an honest rejection.
        $this->assertSame('assessed', $claim->fresh()->status);
    }

    public function test_with_no_workflow_defined_settlement_is_direct(): void
    {
        $policy = $this->activePolicy();
        $claim = $this->claims()->open($policy, [
            'incident_on' => now()->subDays(1)->toDateString(),
            'description' => 'Minor glass damage.',
        ], $this->owner);
        $this->claims()->assess($claim, [], $this->owner);

        $settled = $this->claims()->settle($claim->fresh(), 75_000, $this->owner);

        $this->assertSame('settled', $settled->status);
    }

    public function test_an_incident_outside_the_cover_dates_is_refused(): void
    {
        $policy = $this->activePolicy([
            'covers_from' => now()->subMonths(2)->toDateString(),
            'covers_to' => now()->addMonth()->toDateString(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside this policy\'s cover');

        $this->claims()->open($policy, [
            'incident_on' => now()->subMonths(6)->toDateString(),
            'description' => 'Before cover started.',
        ], $this->owner);
    }

    public function test_nothing_in_the_vertical_grew_an_approve_method(): void
    {
        // The design, asserted: approval belongs to the shared engine, and
        // the day somebody adds approve() here is the day there are two
        // approval paths that can disagree.
        $this->assertFalse(method_exists(Claims::class, 'approve'));
        $this->assertFalse(method_exists(Policies::class, 'approve'));
        $this->assertFalse(method_exists(InsuranceClaim::class, 'approve'));
        $this->assertFalse(method_exists(\App\Models\InsurancePolicy::class, 'approve'));
    }
}
