<?php

namespace Tests\Feature\Insurance;

use App\Livewire\Insurance\Show;
use App\Models\InsuranceEndorsement;
use App\Models\InsurancePolicyRenewal;
use Livewire\Livewire;

/**
 * The screen side of the hardening: renewing and endorsing go through the
 * service, instalments draft the whole schedule, and a claim's evidence is
 * on the page through the shared library panel.
 */
class InsuranceHardeningScreensTest extends InsuranceTestCase
{
    public function test_renewing_from_the_screen_writes_the_history_row(): void
    {
        $this->actingAs($this->owner);

        $policy = $this->activePolicy(['renewal_term_months' => 12]);

        Livewire::test(Show::class, ['policy' => $policy])
            ->call('startRenewing')
            ->set('renewPremium', '900000')
            ->call('renew')
            ->assertHasNoErrors();

        $this->assertSame(1, InsurancePolicyRenewal::query()
            ->where('insurance_policy_id', $policy->id)->count());
        $this->assertSame('900000.00', (string) $policy->fresh()->premium);
    }

    public function test_endorsing_from_the_screen_records_the_change(): void
    {
        $this->actingAs($this->owner);

        $policy = $this->activePolicy(['premium' => 850_000]);

        Livewire::test(Show::class, ['policy' => $policy])
            ->call('startEndorsing')
            ->set('endorsementDescription', 'Sum insured raised.')
            ->set('endorsementPremium', '910000')
            ->call('endorse')
            ->assertHasNoErrors();

        $endorsement = InsuranceEndorsement::query()
            ->where('insurance_policy_id', $policy->id)->first();

        $this->assertNotNull($endorsement);
        $this->assertNotNull($endorsement->document_id, 'The premium adjustment should be drafted.');
    }

    public function test_instalments_from_the_screen_draft_the_whole_schedule(): void
    {
        $this->actingAs($this->owner);

        $policy = $this->activePolicy(['premium' => 850_000]);

        Livewire::test(Show::class, ['policy' => $policy])
            ->set('instalmentCount', '4')
            ->call('invoiceInstalments')
            ->assertHasNoErrors();

        $this->assertCount(4, $policy->fresh()->premiumInvoiceLinks);
    }

    public function test_a_refusal_from_the_service_reaches_the_screen(): void
    {
        $this->actingAs($this->owner);

        // Open cover has no term to renew — the service refuses, and the
        // refusal must surface as an error, not a 500.
        $policy = $this->activePolicy(['covers_to' => null, 'renewal_type' => 'none']);

        Livewire::test(Show::class, ['policy' => $policy])
            ->call('renew')
            ->assertHasErrors('renewing');
    }

    public function test_a_claim_shows_its_evidence_panel(): void
    {
        $this->actingAs($this->owner);

        $policy = $this->activePolicy();

        $this->claims()->open($policy, [
            'incident_on' => now()->subWeek()->toDateString(),
            'description' => 'Collision at the roundabout.',
        ], $this->owner);

        Livewire::test(Show::class, ['policy' => $policy->fresh()])
            ->assertSee('Evidence');
    }
}
