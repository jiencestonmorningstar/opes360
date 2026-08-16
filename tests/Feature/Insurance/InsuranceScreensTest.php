<?php

namespace Tests\Feature\Insurance;

use App\Livewire\Insurance\Index;
use App\Livewire\Insurance\Show;
use App\Models\InsurancePolicy;
use Livewire\Livewire;

/**
 * The screens over the insurance vertical.
 *
 * The services are tested next door; what matters here is that the watch
 * screen puts each policy on the right one of its three lists, that an action
 * goes through the service rather than round it, and that a refusal reaches
 * the person reading the screen in the words the service used.
 */
class InsuranceScreensTest extends InsuranceTestCase
{
    public function test_the_watch_screen_separates_the_three_kinds_of_lapse(): void
    {
        $this->actingAs($this->owner);

        $rolling = $this->activePolicy([
            'covers_to' => now()->subDays(5)->toDateString(),
            'renewal_type' => 'auto',
            'notice_period_days' => 30,
        ]);

        $gone = $this->activePolicy([
            'covers_to' => now()->subDays(5)->toDateString(),
            'renewal_type' => 'manual',
        ]);

        $coming = $this->activePolicy([
            'covers_to' => now()->addDays(10)->toDateString(),
        ]);

        $screen = Livewire::test(Index::class);

        $this->assertPolicyIn($rolling, $screen->viewData('lapsedOnAutoRenew'));
        $this->assertPolicyNotIn($rolling, $screen->viewData('lapsed'));

        $this->assertPolicyIn($gone, $screen->viewData('lapsed'));
        $this->assertPolicyNotIn($gone, $screen->viewData('lapsedOnAutoRenew'));

        $this->assertPolicyIn($coming, $screen->viewData('lapsing'));
    }

    public function test_placing_a_policy_goes_through_the_service_and_refusals_come_back(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->call('startPlacing')
            ->set('holderId', $this->client->id)
            ->set('productLine', 'motor')
            ->set('coversFrom', now()->toDateString())
            ->set('coversTo', now()->addYear()->toDateString())
            ->set('renewalType', 'auto')
            // No notice period on auto-renew: the service refuses, and the
            // screen shows its words rather than "something went wrong".
            ->call('place')
            ->assertHasErrors('placing');

        Livewire::test(Index::class)
            ->call('startPlacing')
            ->set('holderId', $this->client->id)
            ->set('productLine', 'motor')
            ->set('premium', '850000')
            ->set('coversFrom', now()->toDateString())
            ->set('coversTo', now()->addYear()->toDateString())
            ->set('renewalType', 'manual')
            ->call('place')
            ->assertHasNoErrors();

        $this->assertSame(1, InsurancePolicy::query()->count());
    }

    public function test_the_policy_page_renders_with_its_claims_and_money(): void
    {
        $this->actingAs($this->owner);

        $policy = $this->activePolicy();
        $this->policies()->invoicePremium($policy, $this->owner);
        $this->policies()->recordCommission($policy, null, $this->owner);
        $this->claims()->open($policy, [
            'incident_on' => now()->subDays(3)->toDateString(),
            'description' => 'Broken windscreen.',
        ], $this->owner);

        Livewire::test(Show::class, ['policy' => $policy->fresh()])
            ->assertSee('Broken windscreen.')
            ->assertSee('Premium invoices')
            ->assertSee('Commission');
    }
}
