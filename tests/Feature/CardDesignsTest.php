<?php

namespace Tests\Feature;

use App\Livewire\Business\Stationery;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CardDesignsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'industry' => 'Technology',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_each_design_prints_with_its_verification_qr(): void
    {
        foreach (Company::CARD_DESIGNS as $design) {
            $this->company->update(['card_design' => $design]);
            app(CurrentCompany::class)->set($this->company->fresh());

            $content = $this->actingAs($this->user)
                ->get(route('stationery.print', ['asset' => 'card']))
                ->assertOk()
                ->assertSee('Acme Ltd')
                ->getContent();

            // The front face carries the design class; asserting the full
            // attribute keeps the stylesheet (which names every design) from
            // producing a false pass. The premium designs use their own
            // skeleton (pc-*) and bring their own back face (pcb-*).
            if (in_array($design, ['azure', 'onyx', 'jade', 'cyber', 'violet', 'sunrise'], true)) {
                $this->assertStringContainsString('pc pc-'.$design, $content);
                $this->assertStringContainsString('pcb pcb-'.$design, $content);
                $this->assertStringContainsString('Scan to view', $content);
                $this->assertStringContainsString('All your business. One platform.', $content);
            } else {
                $this->assertStringContainsString('card-inner card-'.$design, $content);
            }
            // The QR is non-negotiable: every design must stay verifiable.
            $this->assertStringContainsString('<svg', $content, "{$design} card should carry a QR");
        }
    }

    public function test_an_unset_or_unknown_design_prints_the_classic_card(): void
    {
        foreach ([null, 'neon'] as $stored) {
            $this->company->update(['card_design' => $stored]);
            app(CurrentCompany::class)->set($this->company->fresh());

            $this->actingAs($this->user)
                ->get(route('stationery.print', ['asset' => 'card']))
                ->assertOk()
                ->assertSee('card-inner card-classic', false);
        }
    }

    public function test_the_picker_saves_the_choice_on_the_company(): void
    {
        Livewire::actingAs($this->user)
            ->test(Stationery::class)
            ->call('setAsset', 'card')
            ->call('setCardDesign', 'split')
            ->assertSet('cardDesign', 'split');

        $this->assertSame('split', $this->company->fresh()->card_design);
    }

    public function test_the_picker_rejects_an_unknown_design(): void
    {
        Livewire::actingAs($this->user)
            ->test(Stationery::class)
            ->call('setCardDesign', 'neon')
            ->assertSet('cardDesign', 'classic');

        $this->assertNull($this->company->fresh()->card_design);
    }

    public function test_the_picker_opens_on_the_saved_design(): void
    {
        $this->company->update(['card_design' => 'minimal']);
        app(CurrentCompany::class)->set($this->company->fresh());

        Livewire::actingAs($this->user)
            ->test(Stationery::class)
            ->assertSet('cardDesign', 'minimal');
    }
}
