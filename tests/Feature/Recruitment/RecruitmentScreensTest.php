<?php

namespace Tests\Feature\Recruitment;

use App\Livewire\Recruitment\Index;
use App\Livewire\Recruitment\Show;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use Livewire\Livewire;

/**
 * The two screens, tested for the acts that would be expensive to get wrong:
 * hiring from the page, and an unauthorised hand on the pipeline.
 */
class RecruitmentScreensTest extends RecruitmentTestCase
{
    public function test_a_vacancy_can_be_created_and_opened_from_the_screen(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->call('startAdding')
            ->set('positionId', $this->position->id)
            ->set('description', 'Deliver bread across Douala.')
            ->set('openings', 2)
            ->call('save')
            ->assertHasNoErrors();

        $vacancy = Vacancy::query()->first();
        $this->assertNotNull($vacancy);
        $this->assertSame('draft', $vacancy->status);
        $this->assertNotNull($vacancy->share_token);

        Livewire::test(Index::class)->call('open', $vacancy->id);

        $this->assertSame('open', $vacancy->fresh()->status);
    }

    public function test_the_board_moves_a_card_and_keeps_the_history(): void
    {
        $application = $this->application();

        $this->actingAs($this->owner);

        Livewire::test(Index::class)->call('moveStage', $application->id, 'screening');

        $fresh = $application->fresh();
        $this->assertSame('screening', $fresh->stage);
        $this->assertSame($this->owner->id, $fresh->stageMoves()->where('to_stage', 'screening')->value('moved_by'));
    }

    public function test_somebody_without_the_ability_cannot_reach_the_screen(): void
    {
        $stranger = User::factory()->create();
        $this->joinCompany($this->company, $stranger, Role::READ_ONLY);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($stranger);

        Livewire::test(Index::class)->assertForbidden();
    }

    public function test_the_application_page_can_run_the_whole_offer_and_hire(): void
    {
        $application = $this->application();
        $this->offerWorkflow();

        $this->actingAs($this->owner);

        // Draft the offer with its letter from the page.
        Livewire::test(Show::class, ['application' => $application])
            ->set('offerAmount', '300000')
            ->set('offerStartsOn', now()->addWeeks(2)->toDateString())
            ->call('makeOffer')
            ->assertHasNoErrors();

        $offer = $application->fresh()->currentOffer();
        $this->assertNotNull($offer);
        $this->assertNotNull($offer->letter);

        // Submit, approve through the engine, then accept — which hires.
        Livewire::test(Show::class, ['application' => $application->fresh()])
            ->call('submitOffer', $offer->id);

        $this->engine()->act($offer->fresh()->approval(), $this->owner, 'approved');

        Livewire::test(Show::class, ['application' => $application->fresh()])
            ->call('acceptOffer', $offer->id)
            ->assertHasNoErrors();

        $this->assertSame(1, Employee::query()->count());
        $this->assertSame('hired', $application->fresh()->stage);
    }

    public function test_accepting_early_shows_the_refusal_instead_of_a_500(): void
    {
        $application = $this->application();
        $this->offerWorkflow();

        $offer = $this->offers()->make($application, 250_000, now()->addWeeks(2)->toDateString(), $this->owner);
        $this->offers()->submit($offer, $this->owner);

        $this->actingAs($this->owner);

        Livewire::test(Show::class, ['application' => $application->fresh()])
            ->call('acceptOffer', $offer->id)
            ->assertHasErrors('action');

        $this->assertSame(0, Employee::query()->count());
    }

    public function test_rejecting_from_the_page_requires_a_reason(): void
    {
        $application = $this->application();

        $this->actingAs($this->owner);

        Livewire::test(Show::class, ['application' => $application])
            ->call('reject')
            ->assertHasErrors('rejectionReason');

        Livewire::test(Show::class, ['application' => $application])
            ->set('rejectionReason', 'No licence.')
            ->call('reject')
            ->assertHasNoErrors();

        $this->assertSame('rejected', $application->fresh()->stage);
    }
}
