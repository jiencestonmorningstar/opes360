<?php

namespace Tests\Feature\Compliance;

use App\Livewire\Risks\Index;
use App\Models\Risk;
use App\Models\Role;
use App\Services\Compliance\RiskRegister;
use Livewire\Livewire;

/**
 * The register screen. The test that matters most is the last one: somebody
 * who may raise risks must not be able to mark one down.
 */
class RiskScreensTest extends ComplianceTestCase
{
    protected function risk(array $overrides = []): Risk
    {
        return app(RiskRegister::class)->record(array_merge([
            'title' => 'Generator fails during a power cut',
            'likelihood' => 4,
            'impact' => 5,
            'review_interval_months' => 6,
        ], $overrides), $this->owner);
    }

    public function test_risks_past_their_review_date_surface(): void
    {
        $this->risk([
            'title' => 'Nobody has checked the fire extinguishers',
            'next_review_on' => now()->subMonth()->toDateString(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->assertViewHas('summary', fn (array $summary) => $summary['risks_to_review'] === 1)
            ->set('tab', 'review')
            ->assertSee('Nobody has checked the fire extinguishers');
    }

    public function test_the_untreated_score_is_shown_and_ranks_the_register(): void
    {
        $this->risk(['title' => 'Minor stationery shortage', 'likelihood' => 1, 'impact' => 1]);
        $this->risk(['title' => 'Warehouse fire', 'likelihood' => 5, 'impact' => 5]);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->assertSeeInOrder(['Warehouse fire', 'Minor stationery shortage']);
    }

    public function test_a_control_does_not_lower_the_score(): void
    {
        $risk = $this->risk();
        $before = $risk->residualScore();

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startControl', $risk->id)
            ->set('controlTitle', 'Monthly load test')
            ->call('addControl')
            ->assertHasNoErrors();

        $this->assertSame($before, $risk->fresh()->residualScore());
        $this->assertFalse($risk->fresh()->hasBeenReassessed());
    }

    public function test_a_score_outside_the_scale_shows_the_services_message(): void
    {
        $risk = $this->risk();

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startReassessing', $risk->id)
            ->set('residualLikelihood', '7')
            ->set('residualImpact', '3')
            ->call('reassess')
            ->assertHasErrors('reassessing');

        $this->assertFalse($risk->fresh()->hasBeenReassessed());
    }

    public function test_somebody_without_risks_review_cannot_reassess(): void
    {
        $risk = $this->risk();

        // A manager may raise risks and record controls, but marking one down
        // is deliberately somebody else's decision.
        $manager = $this->memberAt(Role::MANAGER);

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->call('startReassessing', $risk->id)
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->set('reassessing', $risk->id)
            ->set('residualLikelihood', '1')
            ->set('residualImpact', '1')
            ->call('reassess')
            ->assertForbidden();

        $this->assertFalse($risk->fresh()->hasBeenReassessed());
    }

    public function test_reviewing_moves_the_next_review_date(): void
    {
        $risk = $this->risk(['next_review_on' => now()->subMonth()->toDateString()]);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startReview', $risk->id)
            ->set('reviewedOn', now()->toDateString())
            ->call('review')
            ->assertHasNoErrors();

        // Counted from when somebody actually looked — the opposite of a
        // statutory deadline, and deliberately so.
        $this->assertSame(
            now()->addMonths(6)->toDateString(),
            $risk->fresh()->next_review_on->toDateString(),
        );
    }
}
